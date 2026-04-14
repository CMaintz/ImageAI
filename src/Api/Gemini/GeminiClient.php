<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Api\Gemini;

use CMaintz\ImageAi\Config\PluginConfiguration;
use CMaintz\ImageAi\Config\PluginConstants;
use CMaintz\ImageAi\DTO\Request\AnalysisRequest;
use CMaintz\ImageAi\DTO\Request\CompositionRequest;
use CMaintz\ImageAi\DTO\Request\GenerationRequest;
use CMaintz\ImageAi\Trait\RetryWithBackoffTrait;
use RuntimeException;
use SplObjectStorage;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Throwable;
use Generator;

class GeminiClient
{

    use RetryWithBackoffTrait;
    private HttpClientInterface $client;

    public function __construct(
        private readonly PluginConfiguration $config,
        private readonly GeminiResponseParser $responseParser,
        ?HttpClientInterface $client = null,
    ) {
        $this->client = $client ?? HttpClient::create();
    }

    private function assertSuccessResponse(ResponseInterface $response): void
    {
        $statusCode = $response->getStatusCode();

        if ($statusCode !== 200) {
            $error = $response->toArray(false);
            throw new RuntimeException("Gemini API error: {$statusCode} - " . json_encode($error));
        }
    }

    /**
     * Analyze a batch of product images in ONE API call
     * @param AnalysisRequest $request Batch request containing multiple product images
     * @return array Response data as array
     */
    public function analyzeBatch(AnalysisRequest $request): array
    {
        $apiConfig = $this->config->getApiConfig();

        if (!$apiConfig->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured');
        }

        $url = $apiConfig->getAnalysisUrl();

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $request->toApiPayload(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiConfig->apiKey,
                ],
                'timeout' => PluginConstants::API_TIMEOUT_BATCH,
                'max_duration' => PluginConstants::API_MAX_DURATION_BATCH,
            ]);

            $this->assertSuccessResponse($response);

            return $this->responseParser->parseBatchAnalysisResponse($response->toArray());
        } catch (Throwable $e) {
            throw new RuntimeException('Gemini API batch request failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Composite product artwork into environment scene
     * @param CompositionRequest $request Encapsulated composition request with prompt and images
     * @return string Binary data of composited image
     * @throws RuntimeException
     */
    public function compositeImage(CompositionRequest $request): string
    {
        $apiConfig = $this->config->getApiConfig();

        if (!$apiConfig->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured');
        }

        $url = $apiConfig->getImageGenerationUrl();
        $payload = $request->toApiPayload();

        try {
            $response = $this->client->request('POST', $url, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiConfig->apiKey,
                ],
                'timeout' => PluginConstants::API_TIMEOUT,
                'max_duration' => PluginConstants::API_MAX_DURATION_GENERATION,
            ]);

            $this->assertSuccessResponse($response);

            $result = $this->responseParser->parseCompositionResponse($response->toArray());

            if ($result['image'] === null) {
                $errorMsg = 'Gemini did not return a composited image';
                if ($result['textResponse']) {
                    $errorMsg .= ': ' . $result['textResponse'];
                }
                throw new RuntimeException($errorMsg);
            }

            return $result['image'];
        } catch (Throwable $e) {
            throw new RuntimeException('Gemini image composition failed: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Composite multiple images concurrently using parallel HTTP requests.
     *
     * Launches all composition requests simultaneously and collects results
     * as they complete. Total time is ~1x API timeout (instead of Nx timeout w/ synchronous)
     *
     * @param CompositionRequest[] $requests Array of composition requests keyed by scene name
     * @return array<string, array{success: bool, image: string|null, error: string|null}> Results keyed by scene name
     */
    public function compositeImagesConcurrently(array $requests): array
    {
        return $this->retryWithBackoff(function () use ($requests) {
            $apiConfig = $this->config->getApiConfig();
            if (!$apiConfig->isConfigured()) {
                throw new RuntimeException('Gemini API key is not configured');
            }

            $url = $apiConfig->getImageGenerationUrl();
            $responses = [];
            $responseToScene = new SplObjectStorage();

            foreach ($requests as $sceneName => $request) {
                $responses[] = $response = $this->client->request('POST', $url, [
                    'json' => $request->toApiPayload(),
                    'headers' => [
                        'Content-Type' => 'application/json',
                        'x-goog-api-key' => $apiConfig->apiKey,
                    ],
                    'timeout' => PluginConstants::API_TIMEOUT,
                    'max_duration' => PluginConstants::API_MAX_DURATION_GENERATION,
                ]);
                $responseToScene[$response] = $sceneName;
            }

            $results = [];
            foreach ($this->client->stream($responses) as $response => $chunk) {
                if (!$chunk->isLast()) {
                    continue;
                }

                $sceneName = $responseToScene[$response];
                $responseData = $response->toArray();

                $parsed = $this->responseParser->parseCompositionResponse($responseData);
                $results[$sceneName] = [
                    'success' => $parsed['image'] !== null,
                    'image' => $parsed['image'],
                    'error' => $parsed['image'] === null ? ($parsed['textResponse'] ?: 'No image') : null,
                ];
            }
            return $results;
        });
    }

    /**
     * Composite multiple images concurrently, yielding results as each completes.
     *
     * Unlike compositeImagesConcurrently() which waits for ALL requests to finish,
     * this generator yields each result immediately when its API response arrives.
     * This enables SSE streaming so users see first result in ~8-15s instead of
     * waiting for all scenes (~30-60s).
     *
     * @param CompositionRequest[] $requests Array of composition requests keyed by scene name
     * @return Generator<string, array{success: bool, image: string|null, error: string|null}>
     * @throws TransportExceptionInterface
     */
    public function compositeImagesStreaming(array $requests): \Generator
    {
        $apiConfig = $this->config->getApiConfig();
        if (!$apiConfig->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured');
        }

        $url = $apiConfig->getImageGenerationUrl();
        $responses = [];
        $responseToScene = new SplObjectStorage();

        foreach ($requests as $sceneName => $request) {
            $responses[] = $response = $this->client->request('POST', $url, [
                'json' => $request->toApiPayload(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiConfig->apiKey,
                ],
                'timeout' => PluginConstants::API_TIMEOUT,
                'max_duration' => PluginConstants::API_MAX_DURATION_GENERATION,
            ]);
            $responseToScene[$response] = $sceneName;
        }

        // Yield results as each response completes
        foreach ($this->client->stream($responses) as $response => $chunk) {
            if (!$chunk->isLast()) {
                continue;
            }

            $sceneName = $responseToScene[$response];

            try {
                $statusCode = $response->getInfo('http_code');

                if ($statusCode !== 200) {
                    $errorBody = [];
                    try {
                        $errorBody = $response->toArray(false);
                    } catch (Throwable) {
                        // Ignore - we'll use the status code
                    }
                    yield $sceneName => [
                        'success' => false,
                        'image' => null,
                        'error' => "HTTP {$statusCode}: " . ($errorBody['error']['message'] ?? 'Server error'),
                    ];
                    continue;
                }

                $responseData = $response->toArray();
                $parsed = $this->responseParser->parseCompositionResponse($responseData);

                yield $sceneName => [
                    'success' => $parsed['image'] !== null,
                    'image' => $parsed['image'],
                    'error' => $parsed['image'] === null ? ($parsed['textResponse'] ?: 'No image returned') : null,
                ];
            } catch (Throwable $e) {
                yield $sceneName => [
                    'success' => false,
                    'image' => null,
                    'error' => $e->getMessage(),
                ];
            }
        }
    }

    /**
     * @param GenerationRequest[] $requests Keyed by identifier (e.g. scene type label)
     * @return array Keyed by same identifier [{success: bool, images: array, error: string|null}]
     * @throws TransportExceptionInterface
     */
    public function generateImages(array $requests): array
    {
        $apiConfig = $this->config->getApiConfig();

        if (!$apiConfig->isConfigured()) {
            throw new RuntimeException('Gemini API key is not configured');
        }

        $url = $apiConfig->getImageGenerationUrl();

        $responses = array_map(function ($request) use ($apiConfig, $url) {
            return $this->client->request('POST', $url, [
                'json' => $request->toApiPayload(),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'x-goog-api-key' => $apiConfig->apiKey,
                ],
                'timeout' => PluginConstants::API_TIMEOUT,
                'max_duration' => PluginConstants::API_MAX_DURATION_GENERATION,
            ]);
        }, $requests);

        $results = [];

        foreach ($responses as $key => $response) {
            try {
                $statusCode = $response->getStatusCode();

                if ($statusCode !== 200) {
                    $error = $response->toArray(false);
                    $results[$key] = [
                        'success' => false,
                        'images' => [],
                        'error' => "HTTP {$statusCode}: " . ($error['error']['message'] ?? json_encode($error)),
                    ];
                    continue;
                }

                $images = $this->responseParser->parseGenerationResponse($response->toArray());
                $results[$key] = [
                    'success' => !empty($images),
                    'images' => $images,
                    'error' => empty($images) ? 'No image in response' : null,
                ];
            } catch (Throwable $e) {
                $results[$key] = [
                    'success' => false,
                    'images' => [],
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $results;
    }
}
