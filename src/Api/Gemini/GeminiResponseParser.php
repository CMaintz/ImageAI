<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Api\Gemini;

use RuntimeException;

/**
 * Parses raw Gemini API responses into structured data
 * Handles the nested response structure from Gemini's generateContent API
 * and provides consistent error handling for malformed responses.
 */
class GeminiResponseParser
{

    /**
     * Parse a batch analysis response from Gemini API
     * @param array $rawResponse The raw API response array
     * @return array Decoded analysis results
     * @throws RuntimeException If response structure is invalid or JSON is malformed
     */
    public function parseBatchAnalysisResponse(array $rawResponse): array
    {
        $jsonText = $this->extractJsonText($rawResponse);

        $decoded = json_decode($jsonText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Failed to decode Gemini JSON response: ' . json_last_error_msg());
        }

        return $decoded;
    }

    /**
     * Extract JSON text from the nested Gemini response structure
     * @param array $rawResponse Raw API response
     * @return string The JSON text content
     * @throws RuntimeException If the expected structure is not found
     */
    private function extractJsonText(array $rawResponse): string
    {
        // Standard path: candidates[0].content.parts[0].text
        if (isset($rawResponse['candidates'][0]['content']['parts'][0]['text'])) {
            return $rawResponse['candidates'][0]['content']['parts'][0]['text'];
        }

        throw new RuntimeException('Unexpected response structure from Gemini API -
        missing candidates[0].content.parts[0].text');
    }

    /**
     * Parse a composition/image generation response and extract the first image as binary data
     * Used by compositeImage() and compositeImagesConcurrently()
     *
     * @param array $rawResponse Raw API response
     * @return array{image: string|null, textResponse: string|null} Decoded binary image data,
     * plus any text response from Gemini
     */
    public function parseCompositionResponse(array $rawResponse): array
    {
        $images = $this->extractInlineImages($rawResponse);
        $textResponse = $this->extractTextResponse($rawResponse);

        if (empty($images)) {
            return [
                'image' => null,
                'textResponse' => $textResponse,
            ];
        }

        return [
            'image' => base64_decode($images[0]['base64']),
            'textResponse' => $textResponse,
        ];
    }

    /**
     * Extract any text parts from Gemini response (useful for debugging when image generation fails)
     */
    private function extractTextResponse(array $rawResponse): ?string
    {
        $parts = $rawResponse['candidates'][0]['content']['parts'] ?? [];
        $texts = [];

        foreach ($parts as $part) {
            if (isset($part['text'])) {
                $texts[] = $part['text'];
            }
        }

        return empty($texts) ? null : implode("\n", $texts);
    }

    /**
     * Parse a generation response and extract all images with their metadata
     * Used by generateImages()
     *
     * @param array $rawResponse Raw API response
     * @return array<array{base64: string, mimeType: string}> Array of image data
     */
    public function parseGenerationResponse(array $rawResponse): array
    {
        return $this->extractInlineImages($rawResponse);
    }

    /**
     * Extract all inline image data from Gemini response parts
     *
     * @param array $rawResponse Raw API response
     * @return array<array{base64: string, mimeType: string}> Array of image data with base64 and mimeType
     */
    private function extractInlineImages(array $rawResponse): array
    {
        $images = [];

        $parts = $rawResponse['candidates'][0]['content']['parts'] ?? [];

        foreach ($parts as $part) {
            // Check both camelCase (inlineData) and snake_case (inline_data)
            $inlineData = $part['inlineData'] ?? $part['inline_data'] ?? null;

            if ($inlineData !== null) {
                $data = $inlineData['data'] ?? null;
                $mimeType = $inlineData['mimeType'] ?? $inlineData['mime_type'] ?? 'image/png';

                if ($data !== null) {
                    $images[] = [
                        'base64' => $data,
                        'mimeType' => $mimeType,
                    ];
                }
            }
        }

        return $images;
    }

}
