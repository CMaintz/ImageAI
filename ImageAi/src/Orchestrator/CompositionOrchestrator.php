<?php declare(strict_types=1);

namespace Illux\ImageAi\Orchestrator;

use Illux\ImageAi\Api\Gemini\GeminiClient;
use Illux\ImageAi\DTO\FrameData;
use Illux\ImageAi\DTO\Request\CompositionRequest;
use Illux\ImageAi\Service\Composition\CompositionJobStore;
use Illux\ImageAi\Service\Composition\EnvironmentSceneSelector;
use Illux\ImageAi\Service\Frame\FrameCornerImageResolver;
use Illux\ImageAi\Service\Media\MediaFileReader;
use Illux\ImageAi\Service\Prompt\PromptDirector;
use Illux\ImageAi\Trait\RetryWithBackoffTrait;
use RuntimeException;
use Shopware\Core\Content\Media\MediaEntity;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Content\Product\ProductCollection;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class CompositionOrchestrator
{
    use RetryWithBackoffTrait;

    /** @param EntityRepository<ProductCollection> $productRepository */
    public function __construct(
        private readonly EntityRepository $productRepository,
        private readonly GeminiClient $geminiClient,
        private readonly PromptDirector $promptDirector,
        private readonly CompositionJobStore $jobStore,
        private readonly EnvironmentSceneSelector $sceneSelector,
        private readonly MediaFileReader $mediaFileReader,
        private readonly FrameCornerImageResolver $frameImageResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @param array{width: int, height: int, unit: string}|null $dimensions
     * @param array<array{folderId: string, name: string}> $roomFolders
     * @return array{jobId: string, total: int, environments: array<string>}
     * @throws TransportExceptionInterface
     */
    public function startArtworkCompositionJob(
        string $productId,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        $coverMedia = $this->getProductCoverMedia($productId, $context);
        $productImageData = $this->mediaFileReader->readMediaFile($coverMedia);

        return $this->executeCompositionJob(
            base64_encode($productImageData),
            $coverMedia->getMimeType() ?? 'image/jpeg',
            'artwork',
            $options,
            $dimensions,
            $roomFolders,
            $context,
            $customEnvironmentImage,
            $customEnvironmentMimeType
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{width: int, height: int, unit: string}|null $dimensions
     * @param array<array{folderId: string, name: string}> $roomFolders
     * @return array{jobId: string, total: int, environments: array<string>}
     * @throws TransportExceptionInterface
     */
    public function startUserImageCompositionJob(
        string $userImageBase64,
        string $userImageMimeType,
        string $promptType,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        return $this->executeCompositionJob(
            $userImageBase64,
            $userImageMimeType,
            $promptType,
            $options,
            $dimensions,
            $roomFolders,
            $context,
            $customEnvironmentImage,
            $customEnvironmentMimeType
        );
    }

    /**
     * @param array<string, mixed> $options
     * @param array{width: int, height: int, unit: string}|null $dimensions
     * @param array<array{folderId: string, name: string}> $roomFolders
     * @return array{jobId: string, total: int, environments: array<string>}
     * @throws TransportExceptionInterface
     */
    private function executeCompositionJob(
        string $productImageBase64,
        string $productMimeType,
        string $promptType,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        $jobId = $this->jobStore->generateJobId();

        // Optimize product image once at the start to reduce payload size
        $productImageBase64 = $this->optimizeBase64Image($productImageBase64);
        $productMimeType = 'image/jpeg'; // optimizeBase64Image outputs JPEG

        // Resolve frame data (images and dimensions) if frame option is selected
        $frameResolverData = $this->frameImageResolver->resolveFrameDataFromOptions($options, $context);
        $frameData = FrameData::fromResolverResult($frameResolverData);

        $prompt = $this->promptDirector->buildCompositionPrompt($promptType, $options, $dimensions, $frameData);

        $compositionRequests = [];
        $environmentNames = [];

        // Add custom environment request if provided
        if ($customEnvironmentImage !== null) {
            $sceneName = 'Custom Environment';
            $environmentNames[] = $sceneName;

            // Optimize custom environment image
            $optimizedCustomEnv = $this->optimizeBase64Image($customEnvironmentImage);

            $compositionRequests[$sceneName] = new CompositionRequest(
                prompt: $prompt,
                productImageBase64: $productImageBase64,
                productMimeType: $productMimeType,
                environmentImageBase64: $optimizedCustomEnv,
                environmentMimeType: 'image/jpeg', // optimizeBase64Image outputs JPEG
                frameData: $frameData,
            );
        }

        if (!empty($roomFolders)) {
            $environmentImages = $this->sceneSelector->getEnvironmentImagesByFolders($roomFolders, $context);

            foreach ($environmentImages as $sceneName => $environmentMedia) {
                $environmentImageData = $this->mediaFileReader->readMediaFile($environmentMedia);
                $environmentNames[] = $sceneName;

                // Optimize environment image before adding to request
                $optimizedEnvBase64 = $this->optimizeBase64Image(base64_encode($environmentImageData));

                $compositionRequests[$sceneName] = new CompositionRequest(
                    prompt: $prompt,
                    productImageBase64: $productImageBase64,
                    productMimeType: $productMimeType,
                    environmentImageBase64: $optimizedEnvBase64,
                    environmentMimeType: 'image/jpeg', // optimizeBase64Image outputs JPEG
                    frameData: $frameData,
                );
            }
        }

        if (empty($compositionRequests)) {
            throw new RuntimeException('No environments selected (neither room folders nor custom environment)');
        }

        $jobData = [
            'status' => 'processing',
            'total' => count($compositionRequests),
            'completed' => 0,
            'lastReturnedIndex' => 0,
            'environments' => $environmentNames,
            'results' => [],
            'promptType' => $promptType,
        ];
        $this->jobStore->store($jobId, $jobData);

        // Process all requests concurrently - fastest total time
        $apiResults = $this->geminiClient->compositeImagesConcurrently($compositionRequests);

        $results = [];
        foreach ($apiResults as $sceneName => $apiResult) {
            if ($apiResult['success'] && $apiResult['image'] !== null) {
                $results[] = [
                    'sceneName' => $sceneName,
                    'label' => $sceneName,
                    'image' => base64_encode($apiResult['image']),
                ];
            } else {
                $results[] = [
                    'sceneName' => $sceneName,
                    'label' => $sceneName,
                    'image' => null,
                    'error' => $apiResult['error'] ?? 'Unknown error',
                ];
            }
        }

        $jobData = $this->jobStore->getOrFail($jobId);
        $jobData['results'] = $results;
        $jobData['completed'] = count($results);
        $jobData['status'] = 'completed';
        $this->jobStore->store($jobId, $jobData);

        return [
            'jobId' => $jobId,
            'total' => count($compositionRequests),
            'environments' => $environmentNames,
        ];
    }

    /**
     * Get new results that haven't been returned to the client yet.
     *
     * Uses lastReturnedIndex to track which results have been sent,
     * allowing incremental polling even though all processing is done.
     *
     * @param string $jobId Job ID
     * @return array Job status with any new results
     */
    public function getNewResults(string $jobId): array
    {
        $jobData = $this->jobStore->getOrFail($jobId);

        $allResults = $jobData['results'] ?? [];
        $lastReturnedIndex = $jobData['lastReturnedIndex'] ?? 0;

        $newResults = array_slice($allResults, $lastReturnedIndex);

        if (!empty($newResults)) {
            $jobData['lastReturnedIndex'] = count($allResults);
            $this->jobStore->store($jobId, $jobData);
        }

        return [
            'status' => $jobData['status'],
            'total' => $jobData['total'],
            'completed' => $jobData['completed'],
            'newResults' => $newResults,
        ];
    }

    /**
     * Prepare an artwork composition job without executing it.
     *
     * Builds all request data and stores it in session for later streaming execution.
     * This is the first step for SSE streaming workflow.
     *
     * @return array{jobId: string, total: int, environments: array<string>}
     */
    public function prepareArtworkCompositionJob(
        string $productId,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        $coverMedia = $this->getProductCoverMedia($productId, $context);
        $productImageData = $this->mediaFileReader->readMediaFile($coverMedia);

        return $this->prepareCompositionJob(
            base64_encode($productImageData),
            $coverMedia->getMimeType() ?? 'image/jpeg',
            'artwork',
            $options,
            $dimensions,
            $roomFolders,
            $context,
            $customEnvironmentImage,
            $customEnvironmentMimeType
        );
    }

    /**
     * Prepare a user-image composition job without executing it.
     *
     * @return array{jobId: string, total: int, environments: array<string>}
     */
    public function prepareUserImageCompositionJob(
        string $userImageBase64,
        string $userImageMimeType,
        string $promptType,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        return $this->prepareCompositionJob(
            $userImageBase64,
            $userImageMimeType,
            $promptType,
            $options,
            $dimensions,
            $roomFolders,
            $context,
            $customEnvironmentImage,
            $customEnvironmentMimeType
        );
    }

    /**
     * Prepare composition job and store request data for later execution.
     *
     * @return array{jobId: string, total: int, environments: array<string>}
     */
    private function prepareCompositionJob(
        string $productImageBase64,
        string $productMimeType,
        string $promptType,
        array $options,
        ?array $dimensions,
        array $roomFolders,
        Context $context,
        ?string $customEnvironmentImage = null,
        string $customEnvironmentMimeType = 'image/jpeg'
    ): array {
        $jobId = $this->jobStore->generateJobId();

        // Optimize product image once
        $productImageBase64 = $this->optimizeBase64Image($productImageBase64);
        $productMimeType = 'image/jpeg';

        // Resolve frame data
        $frameResolverData = $this->frameImageResolver->resolveFrameDataFromOptions($options, $context);
        $frameData = FrameData::fromResolverResult($frameResolverData);

        $prompt = $this->promptDirector->buildCompositionPrompt($promptType, $options, $dimensions, $frameData);

        // Build serializable request data (not CompositionRequest objects)
        $requestDataArray = [];
        $environmentNames = [];

        // Custom environment
        if ($customEnvironmentImage !== null) {
            $sceneName = 'Custom Environment';
            $environmentNames[] = $sceneName;
            $optimizedCustomEnv = $this->optimizeBase64Image($customEnvironmentImage);

            $requestDataArray[$sceneName] = [
                'prompt' => $prompt,
                'productImageBase64' => $productImageBase64,
                'productMimeType' => $productMimeType,
                'environmentImageBase64' => $optimizedCustomEnv,
                'environmentMimeType' => 'image/jpeg',
                'frameData' => $frameData?->toArray(),
            ];
        }

        // Room folder environments
        if (!empty($roomFolders)) {
            $environmentImages = $this->sceneSelector->getEnvironmentImagesByFolders($roomFolders, $context);

            foreach ($environmentImages as $sceneName => $environmentMedia) {
                $environmentImageData = $this->mediaFileReader->readMediaFile($environmentMedia);
                $environmentNames[] = $sceneName;

                $optimizedEnvBase64 = $this->optimizeBase64Image(base64_encode($environmentImageData));

                $requestDataArray[$sceneName] = [
                    'prompt' => $prompt,
                    'productImageBase64' => $productImageBase64,
                    'productMimeType' => $productMimeType,
                    'environmentImageBase64' => $optimizedEnvBase64,
                    'environmentMimeType' => 'image/jpeg',
                    'frameData' => $frameData?->toArray(),
                ];
            }
        }

        if (empty($requestDataArray)) {
            throw new RuntimeException('No environments selected');
        }

        // Store job data with prepared requests
        $jobData = [
            'status' => 'pending',
            'total' => count($requestDataArray),
            'completed' => 0,
            'lastReturnedIndex' => 0,
            'streamedCount' => 0,
            'environments' => $environmentNames,
            'results' => [],
            'promptType' => $promptType,
            'processingStarted' => false,
        ];
        $this->jobStore->store($jobId, $jobData);
        $this->jobStore->storeRequests($jobId, $requestDataArray);

        return [
            'jobId' => $jobId,
            'total' => count($requestDataArray),
            'environments' => $environmentNames,
        ];
    }

    /**
     * Execute a prepared job using streaming, yielding results as they arrive.
     *
     * This is called by the SSE endpoint to process each scene and store
     * results incrementally for streaming to the client.
     *
     * @return \Generator<array{sceneName: string, label: string, image: string|null, error: string|null}>
     */
    public function executeCompositionJobStreaming(string $jobId): \Generator
    {
        // Mark processing as started
        $this->jobStore->markProcessingStarted($jobId);
        $this->jobStore->update($jobId, ['status' => 'processing']);

        // Get stored request data
        $requestDataArray = $this->jobStore->getRequests($jobId);
        if ($requestDataArray === null) {
            throw new RuntimeException('No prepared requests found for job: ' . $jobId);
        }

        // Rebuild CompositionRequest objects from stored data
        $compositionRequests = [];
        foreach ($requestDataArray as $sceneName => $data) {
            $frameData = FrameData::fromArray($data['frameData']);
            $compositionRequests[$sceneName] = new CompositionRequest(
                prompt: $data['prompt'],
                productImageBase64: $data['productImageBase64'],
                productMimeType: $data['productMimeType'],
                environmentImageBase64: $data['environmentImageBase64'],
                environmentMimeType: $data['environmentMimeType'],
                frameData: $frameData,
            );
        }

        // Use streaming generator - yields each result as it completes
        foreach ($this->geminiClient->compositeImagesStreaming($compositionRequests) as $sceneName => $apiResult) {
            $result = [
                'sceneName' => $sceneName,
                'label' => $sceneName,
                'image' => null,
                'error' => null,
            ];

            if ($apiResult['success'] && $apiResult['image'] !== null) {
                $result['image'] = base64_encode($apiResult['image']);
            } else {
                $result['error'] = $apiResult['error'] ?? 'Unknown error';
            }

            // Store result immediately
            $this->jobStore->addResult($jobId, $result);
            $this->jobStore->incrementCompleted($jobId);

            // Yield for SSE streaming
            yield $result;
        }

        // Ensure job is marked complete
        $this->jobStore->update($jobId, ['status' => 'completed']);
    }

    /**
     * Check if a job's processing has started
     */
    public function isProcessingStarted(string $jobId): bool
    {
        return $this->jobStore->isProcessingStarted($jobId);
    }

    /**
     * Get results that haven't been streamed yet (for SSE catch-up)
     */
    public function getUnstreamedResults(string $jobId): array
    {
        return $this->jobStore->getNewResults($jobId);
    }

    /**
     * Mark results as streamed to client
     */
    public function markResultsStreamed(string $jobId, int $count): void
    {
        $this->jobStore->markResultsStreamed($jobId, $count);
    }

    private function getProductCoverMedia(string $productId, Context $context): MediaEntity
    {
        $product = $this->getProduct($productId, $context);
        if (!$product) {
            throw new RuntimeException('Product not found');
        }

        $coverMedia = $product->getCover()?->getMedia();

        if (!$coverMedia && $product->getParentId()) {
            $parentProduct = $this->getProduct($product->getParentId(), $context);
            $coverMedia = $parentProduct?->getCover()?->getMedia();
        }

        if (!$coverMedia) {
            throw new RuntimeException('Product has no cover image (checked both product and parent)');
        }

        return $coverMedia;
    }

    public function getJobStatus(string $jobId): array
    {
        $jobData = $this->jobStore->getOrFail($jobId);

        return [
            'status' => $jobData['status'],
            'total' => $jobData['total'],
            'completed' => $jobData['completed'],
        ];
    }

    private function getProduct(string $productId, Context $context): ?ProductEntity
    {
        $criteria = new Criteria([$productId]);
        $criteria->addAssociation('cover.media.thumbnails');

        /** @var ProductEntity|null $product */
        $product = $this->productRepository->search($criteria, $context)->first();

        return $product;
    }

    private function optimizeBase64Image(string $base64, int $maxSize = 1536): string
    {
        $data = base64_decode($base64);
        $img = imagecreatefromstring($data);
        if (!$img) {
            return $base64;
        }

        $width = imagesx($img);
        $height = imagesy($img);

        if ($width <= $maxSize && $height <= $maxSize) {
            imagedestroy($img);
            return $base64;
        }

        $ratio = $width / $height;
        $newW = $ratio > 1 ? $maxSize : (int)($maxSize * $ratio);
        $newH = $ratio > 1 ? (int)($maxSize / $ratio) : $maxSize;

        $dst = imagecreatetruecolor($newW, $newH);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $newW, $newH, $width, $height);

        ob_start();
        imagejpeg($dst, null, 85);
        $optimized = ob_get_clean();

        imagedestroy($img);
        imagedestroy($dst);

        if ($optimized === false) {
            return $base64;
        }

        return base64_encode($optimized);
    }
}
