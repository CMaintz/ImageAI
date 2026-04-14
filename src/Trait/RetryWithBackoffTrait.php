<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Trait;

use CMaintz\ImageAi\Config\PluginConstants;
use RuntimeException;
use Throwable;

/**
 * Provides retry logic with exponential backoff for API operations.
 *
 * Features:
 * - Exponential backoff with configurable initial delay
 * - Rate limit detection with parsed or default delays
 * - Size-related error detection for batch reduction strategies
 * - Optional callback hook for size errors (e.g., remove largest item from batch)
 * - Enriched exception messages with retry context on final failure
 */
trait RetryWithBackoffTrait
{
    /**
     * Retry operation with exponential backoff and optional size reduction
     *
     * @param callable(): mixed $operation The operation to execute
     * @param int $maxRetries Maximum retry attempts for non-size errors
     * @param (callable(Throwable): bool)|null $onSizeError Optional callback for size-related errors.
     * Should modify state (e.g., remove largest image) and return:
     * - true: retry immediately with reset counter (size was reduced)
     * - false: continue with normal backoff retry
     * @return mixed The operation result
     * @throws RuntimeException If all retries exhausted (includes retry context)
     */
    protected function retryWithBackoff(
        callable $operation,
        int $maxRetries = PluginConstants::MAX_RETRIES,
        ?callable $onSizeError = null
    ): mixed {
        $attempt = 0;
        $lastException = null;
        $sizeReductions = 0;
        $totalDelayMs = 0;

        while ($attempt < $maxRetries) {
            try {
                return $operation();
            } catch (Throwable $e) {
                $lastException = $e;
                $attempt++;

                if ($onSizeError !== null && $this->isSizeRelatedError($e->getMessage())) {
                    $wasReduced = $onSizeError($e);

                    if ($wasReduced) {
                        $sizeReductions++;
                        $attempt = 0;
                        continue;
                    }
                }

                if ($attempt >= $maxRetries) {
                    break;
                }

                $delay = $this->calculateRetryDelay($e->getMessage(), $attempt);
                $totalDelayMs += $delay;

                usleep($delay * 1000);
            }
        }

        $context = sprintf(
            '[After %d retries, %d size reductions, %dms total delay] %s',
            $attempt,
            $sizeReductions,
            $totalDelayMs,
            $lastException?->getMessage() ?? 'Unknown error'
        );

        throw new RuntimeException($context, 0, $lastException);
    }

    /**
     * Calculate retry delay based on error type
     * Rate limit errors get longer delays; other errors use standard exponential backoff
     */
    protected function calculateRetryDelay(string $errorMessage, int $attempt): int
    {
        $lowerError = strtolower($errorMessage);

        $isRateLimit = str_contains($lowerError, '429')
            || str_contains($lowerError, 'rate limit')
            || str_contains($lowerError, 'quota exceeded')
            || str_contains($lowerError, 'too many requests');

        if ($isRateLimit) {
            // Try to parse the retry delay from the error message
            // Format: "retry in 21.737330623s" or "retryDelay\":\"21s\""
            if (preg_match('/retry\s*(?:in|delay["\s:]+)\s*(\d+(?:\.\d+)?)\s*s/i', $errorMessage, $matches)) {
                $seconds = (int) ceil((float) $matches[1]);
                return ($seconds + 2) * 1000;
            }

            return 30000;
        }

        return PluginConstants::INITIAL_RETRY_DELAY_MS * (2 ** ($attempt - 1));
    }

    /**
     * Check if an error message indicates a size/capacity-related issue
     * where reducing batch size might help
     */
    protected function isSizeRelatedError(string $errorMessage): bool
    {
        $lowerError = strtolower($errorMessage);

        // Exclude transient/retry-able errors - these should NOT trigger batch reduction
        // They need wait-and-retry, not smaller batches
        $nonSizeIndicators = [
            // Rate limit errors
            '429',
            'rate limit',
            'quota exceeded',
            'too many requests',
            'requests per',
            'retry in',
            'unavailable',
            'try again later',
        ];

        foreach ($nonSizeIndicators as $indicator) {
            if (str_contains($lowerError, $indicator)) {
                return false;
            }
        }

        // Size/capacity indicators that suggest reducing batch size might help
        // Only actual payload size errors should trigger batch splitting
        $sizeIndicators = [
            'request too large',
            '503',
            'overloaded',
            'model overloaded',
            'model is overloaded',
            'payload too large',
            'exceeds the limit',
            'request size',
            '413', // HTTP 413 Payload Too Large
            'content length',
            'body size',
            'max_tokens',
            'token limit',
            'input too long',
        ];

        foreach ($sizeIndicators as $indicator) {
            if (str_contains($lowerError, $indicator)) {
                return true;
            }
        }

        return false;
    }
}
