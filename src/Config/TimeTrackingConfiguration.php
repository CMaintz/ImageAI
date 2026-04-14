<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Config;

/**
 * Value object for time tracking configuration
 * Encapsulates multipliers for time-saved calculations
 */
class TimeTrackingConfiguration
{
    public function __construct(
        public readonly int $minutesSavedPerProperty,
        public readonly int $minutesSavedPerSeo,
        public readonly int $minutesSavedForBaseDescription,
        public readonly int $minutesSavedPerAdditionalTranslation
    ) {
    }

    private function calculateDescriptionMinutes(int $descriptionLanguageCount): int
    {
        if ($descriptionLanguageCount <= 0) {
            return 0;
        }

        $baseMinutes = $this->minutesSavedForBaseDescription;
        $additionalMinutes = max(0, $descriptionLanguageCount - 1) * $this->minutesSavedPerAdditionalTranslation;

        return $baseMinutes + $additionalMinutes;
    }

    public function getBreakdown(
        bool $hasProperties,
        bool $hasSeo,
        int $descriptionLanguageCount
    ): array {
        return [
            'properties' => $hasProperties ? $this->minutesSavedPerProperty : 0,
            'seo' => $hasSeo ? $this->minutesSavedPerSeo : 0,
            'description' => $this->calculateDescriptionMinutes($descriptionLanguageCount),
        ];
    }
}
