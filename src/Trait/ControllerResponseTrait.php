<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Trait;

use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Throwable;

/**
 * Provides common response building and request parsing for controllers.
 *
 * Features:
 * - Standardized JSON success/error responses
 * - Exception handling with logging
 * - Request body parsing and parameter validation
 *
 * Classes using this trait must implement getLogger() to provide the logger instance.
 */
trait ControllerResponseTrait
{
    abstract protected function getLogger(): LoggerInterface;

    /**
     * Build a standardized success response
     *
     * @param array $data Additional data to include in response
     * @param int $statusCode HTTP status code (default 200)
     */
    protected function successResponse(array $data = [], int $statusCode = 200): JsonResponse
    {
        return new JsonResponse(
            array_merge(['success' => true], $data),
            $statusCode
        );
    }

    /**
     * Build a standardized error response
     *
     * @param string $message Error message to return
     * @param int $statusCode HTTP status code (default 400)
     */
    protected function errorResponse(string $message, int $statusCode = 400): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => $message,
        ], $statusCode);
    }

    /**
     * Handle an exception by logging and returning a standardized error response
     *
     * @param Throwable $e The exception that occurred
     * @param string $context Context label for the log message (e.g., 'Analysis', 'Composition')
     * @param array $extra Additional context data to include in the log
     */
    protected function handleException(Throwable $e, string $context, array $extra = []): JsonResponse
    {
        $this->getLogger()->error("[{$context}] Failed", array_merge([
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ], $extra));

        return new JsonResponse([
            'success' => false,
            'error' => $e->getMessage(),
        ], 500);
    }

    /**
     * Parse JSON body from request
     *
     * @return array Decoded JSON data or empty array if invalid/empty
     */
    protected function getJsonBody(Request $request): array
    {
        $content = $request->getContent();
        if ($content === '') {
            return [];
        }

        return json_decode($content, true) ?? [];
    }

    /**
     * Get required array parameter from data, throwing on missing/invalid
     *
     * @param array $data Source data array
     * @param string $key Parameter key to extract
     * @return array The validated array value
     * @throws InvalidArgumentException If parameter is missing or not an array
     */
    protected function requireArrayParam(array $data, string $key): array
    {
        $value = $data[$key] ?? null;

        if ($value === null || !is_array($value)) {
            throw new InvalidArgumentException("Missing required array parameter: {$key}");
        }

        return $value;
    }

    /**
     * Get required string parameter from data, throwing on missing/invalid
     *
     * @param array $data Source data array
     * @param string $key Parameter key to extract
     * @return string The validated string value
     * @throws InvalidArgumentException If parameter is missing or not a string
     */
    protected function requireStringParam(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        if (!is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing required string parameter: {$key}");
        }

        return $value;
    }

    /**
     * Get optional string parameter from data
     *
     * @param array $data Source data array
     * @param string $key Parameter key to extract
     * @param string|null $default Default value if not present
     * @return string|null The string value or default
     */
    protected function optionalStringParam(array $data, string $key, ?string $default = null): ?string
    {
        $value = $data[$key] ?? null;

        if (!is_string($value) || $value === '') {
            return $default;
        }

        return $value;
    }
}
