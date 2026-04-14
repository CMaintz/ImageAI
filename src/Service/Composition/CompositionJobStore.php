<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Service\Composition;

use RuntimeException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Manages composition job state in session storage.
 *
 * Handles creation, retrieval, and updates of composition job data
 * stored in the user's session during the composition workflow.
 */
class CompositionJobStore
{
    private const string SESSION_PREFIX = 'composition_job_';

    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    /**
     * Generate a unique job ID
     */
    public function generateJobId(): string
    {
        return uniqid('comp_', true);
    }

    /**
     * Store job data in session
     *
     * @param string $jobId Unique job identifier
     * @param array $jobData Job data to store
     */
    public function store(string $jobId, array $jobData): void
    {
        $this->getSession()->set(self::SESSION_PREFIX . $jobId, $jobData);
    }

    /**
     * Retrieve job data from session
     *
     * @param string $jobId Job identifier
     * @return array|null Job data or null if not found
     */
    public function get(string $jobId): ?array
    {
        return $this->getSession()->get(self::SESSION_PREFIX . $jobId);
    }

    /**
     * Retrieve job data, throwing if not found
     *
     * @param string $jobId Job identifier
     * @return array Job data
     * @throws RuntimeException If job not found
     */
    public function getOrFail(string $jobId): array
    {
        $jobData = $this->get($jobId);

        if ($jobData === null) {
            throw new RuntimeException('Job not found: ' . $jobId);
        }

        return $jobData;
    }

    /**
     * Update job data in session
     *
     * @param string $jobId Job identifier
     * @param array $updates Fields to update (merged with existing data)
     */
    public function update(string $jobId, array $updates): void
    {
        $jobData = $this->getOrFail($jobId);
        $jobData = array_merge($jobData, $updates);
        $this->store($jobId, $jobData);
    }

    /**
     * Add a result to the job's results array
     *
     * @param string $jobId Job identifier
     * @param array $result Result data to add
     */
    public function addResult(string $jobId, array $result): void
    {
        $jobData = $this->getOrFail($jobId);
        $jobData['results'][] = $result;
        $this->store($jobId, $jobData);
    }

    /**
     * Increment the completed count and optionally mark as complete
     *
     * @param string $jobId Job identifier
     * @return array Updated job data
     */
    public function incrementCompleted(string $jobId): array
    {
        $jobData = $this->getOrFail($jobId);
        $jobData['completed']++;

        if ($jobData['completed'] >= $jobData['total']) {
            $jobData['status'] = 'completed';
        }

        $this->store($jobId, $jobData);

        return $jobData;
    }

    /**
     * Check if job is completed
     */
    public function isCompleted(string $jobId): bool
    {
        $jobData = $this->get($jobId);
        return $jobData !== null && $jobData['status'] === 'completed';
    }

    /**
     * Mark job as processing started (for SSE streaming)
     */
    public function markProcessingStarted(string $jobId): void
    {
        $this->update($jobId, ['processingStarted' => true]);
    }

    /**
     * Check if processing has been started for this job
     */
    public function isProcessingStarted(string $jobId): bool
    {
        $jobData = $this->get($jobId);
        return $jobData !== null && ($jobData['processingStarted'] ?? false);
    }

    /**
     * Get the number of results that have been streamed to client
     */
    public function getStreamedCount(string $jobId): int
    {
        $jobData = $this->get($jobId);
        return $jobData['streamedCount'] ?? 0;
    }

    /**
     * Get new results that haven't been streamed yet
     *
     * @return array New results since last call
     */
    public function getNewResults(string $jobId): array
    {
        $jobData = $this->get($jobId);
        if ($jobData === null) {
            return [];
        }

        $streamedCount = $jobData['streamedCount'] ?? 0;
        $results = $jobData['results'] ?? [];

        // Return only results that haven't been streamed
        return array_slice($results, $streamedCount);
    }

    /**
     * Mark results as streamed (update the streamed count)
     */
    public function markResultsStreamed(string $jobId, int $count): void
    {
        $jobData = $this->getOrFail($jobId);
        $currentStreamed = $jobData['streamedCount'] ?? 0;
        $jobData['streamedCount'] = $currentStreamed + $count;
        $this->store($jobId, $jobData);
    }

    /**
     * Store prepared composition requests for later execution
     */
    public function storeRequests(string $jobId, array $requests): void
    {
        $this->update($jobId, ['preparedRequests' => $requests]);
    }

    /**
     * Get prepared composition requests
     *
     * @return array|null Serialized request data or null
     */
    public function getRequests(string $jobId): ?array
    {
        $jobData = $this->get($jobId);
        return $jobData['preparedRequests'] ?? null;
    }

    /**
     * Remove job from session (cleanup)
     */
    public function remove(string $jobId): void
    {
        $this->getSession()->remove(self::SESSION_PREFIX . $jobId);
    }

    /**
     * Get session from request stack
     */
    private function getSession(): SessionInterface
    {
        $request = $this->requestStack->getCurrentRequest();
        if (!$request) {
            throw new RuntimeException('No current request available');
        }

        return $request->getSession();
    }
}
