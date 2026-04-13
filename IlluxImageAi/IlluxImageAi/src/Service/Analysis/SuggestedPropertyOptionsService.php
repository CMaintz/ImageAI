<?php declare(strict_types=1);

namespace Illux\ImageAi\Service\Analysis;

use Illux\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Illux\ImageAi\Service\Property\PropertyLookupService;
use Illux\ImageAi\Service\Property\PropertyMutationService;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use Illux\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultEntity;
use Throwable;

/**
 * Service for managing AI-suggested property options
 *
 * Handles aggregation, approval, and creation of property options
 * that were suggested by AI but don't exist in the system.
 */
class SuggestedPropertyOptionsService
{
    /**
     * @param EntityRepository<AiAnalysisResultCollection<AiAnalysisResultEntity>> $aiAnalysisResultRepository
     */
    public function __construct(
        private readonly EntityRepository $aiAnalysisResultRepository,
        private readonly PropertyMutationService $propertyMutationService,
        private readonly PropertyLookupService $propertyLookupService,
    ) {
    }

    /**
     * Get aggregated suggested property options from analysis results
     *
     * Collects all unique suggestions across pending/approved analyses,
     * groups by property type, and sorts by frequency.
     *
     * @param Context $context Shopware context
     * @return array{suggestions: array<string, array>, totalSuggestions: int}
     */
    public function getSuggestedOptions(Context $context): array
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsAnyFilter('status', [
            AiAnalysisStatusEnum::PendingReview->value,
            AiAnalysisStatusEnum::Approved->value,
        ]));
        $criteria->addFilter(new NotFilter(
            'AND',
            [new EqualsFilter('suggestedPropertyOptionCandidates', null)]
        ));

        $results = $this->aiAnalysisResultRepository->search($criteria, $context);

        $aggregatedSuggestions = [];

        foreach ($results as $result) {
            $suggestions = $result->get('suggestedPropertyOptionCandidates');

            if (!is_array($suggestions)) {
                continue;
            }

            foreach ($suggestions as $propertyType => $options) {
                if (!isset($aggregatedSuggestions[$propertyType])) {
                    $aggregatedSuggestions[$propertyType] = [];
                }

                foreach ($options as $option) {
                    $optionKey = strtolower($option);

                    if (!isset($aggregatedSuggestions[$propertyType][$optionKey])) {
                        $aggregatedSuggestions[$propertyType][$optionKey] = [
                            'name' => $option,
                            'count' => 0,
                        ];
                    }

                    $aggregatedSuggestions[$propertyType][$optionKey]['count']++;
                }
            }
        }

        $formattedSuggestions = [];
        foreach ($aggregatedSuggestions as $propertyType => $options) {
            $formattedSuggestions[$propertyType] = array_values($options);

            usort($formattedSuggestions[$propertyType], fn($a, $b) => $b['count'] <=> $a['count']);
        }

        return [
            'suggestions' => $formattedSuggestions,
            'totalSuggestions' => array_sum(array_map('count', $formattedSuggestions)),
        ];
    }

    /**
     * Approve and create property options from suggestions
     *
     * Creates the suggested options as actual property group options
     * in the Shopware system, then removes them from suggestions.
     *
     * @param array<array{
     *     propertyGroup: string,
     *     optionName: string,
     *     translations?: array<string, string>
     *         }> $optionsToCreate
     * @param Context $context Shopware context
     * @return array{created: int, failed: int, errors: array}
     */
    public function approveAndCreateOptions(array $optionsToCreate, Context $context): array
    {
        $created = 0;
        $failed = 0;
        $errors = [];
        $successfullyCreated = [];

        $groupedOptions = [];
        foreach ($optionsToCreate as $item) {
            $groupName = $item['propertyGroup'];
            $optionName = $item['optionName'];
            $translations = $item['translations'] ?? null;

            if (!isset($groupedOptions[$groupName])) {
                $groupedOptions[$groupName] = [];
            }
            $groupedOptions[$groupName][] = [
                'name' => $optionName,
                'translations' => $translations,
            ];
        }

        foreach ($groupedOptions as $groupName => $options) {
            try {
                $groupId = $this->propertyLookupService->findPropertyGroup($groupName, $context);

                if (!$groupId) {
                    $errors[] = "Property group not found: {$groupName}";
                    $failed += count($options);
                    continue;
                }

                foreach ($options as $option) {
                    try {
                        $formattedTranslations = null;
                        if (!empty($option['translations'])) {
                            $formattedTranslations = [];
                            foreach ($option['translations'] as $langCode => $translatedName) {
                                $formattedTranslations[$langCode] = ['name' => $translatedName];
                            }
                        }

                        $this->propertyMutationService->createPropertyOption(
                            $groupId,
                            $option['name'],
                            $context,
                            $formattedTranslations
                        );
                        $created++;
                        $successfullyCreated[] = [
                            'propertyGroup' => $groupName,
                            'optionName' => $option['name'],
                        ];
                    } catch (Throwable $e) {
                        $errors[] = "Failed to create option
                        '{$option['name']}' in group '{$groupName}': {$e->getMessage()}";
                        $failed++;
                    }
                }
            } catch (Throwable $e) {
                $errors[] = "Error processing group '{$groupName}': {$e->getMessage()}";
                $failed += count($options);
            }
        }

        foreach ($successfullyCreated as $item) {
            $this->removeSuggestionFromResults($item['propertyGroup'], $item['optionName'], $context);
        }

        return [
            'created' => $created,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    /**
     * Remove a suggestion from all analysis results (internal helper)
     *
     * Used after approval to clean up the suggestedPropertyOptionCandidates field.
     */
    private function removeSuggestionFromResults(string $propertyGroup, string $optionName, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(
            'AND',
            [new EqualsFilter('suggestedPropertyOptionCandidates', null)]
        ));

        $results = $this->aiAnalysisResultRepository->search($criteria, $context);
        $updates = [];

        foreach ($results as $result) {
            $suggestions = $result->get('suggestedPropertyOptionCandidates');

            if (!is_array($suggestions) || !isset($suggestions[$propertyGroup])) {
                continue;
            }

            $filtered = array_filter(
                $suggestions[$propertyGroup],
                fn($opt) => strtolower($opt) !== strtolower($optionName)
            );

            if (count($filtered) !== count($suggestions[$propertyGroup])) {
                $suggestions[$propertyGroup] = array_values($filtered);

                if (empty($suggestions[$propertyGroup])) {
                    unset($suggestions[$propertyGroup]);
                }

                $updates[] = [
                    'id' => $result->id,
                    'suggestedPropertyOptionCandidates' => empty($suggestions) ? null : $suggestions,
                ];
            }
        }

        if (!empty($updates)) {
            $this->aiAnalysisResultRepository->update($updates, $context);
        }
    }

    /**
     * Remove a suggestion from all analysis results
     *
     * Used when rejecting a suggestion - removes it from the
     * suggestedPropertyOptionCandidates field of all results.
     *
     * @param string $propertyGroup Property group name
     * @param string $optionName Option name to remove
     * @param Context $context Shopware context
     * @return int Number of results updated
     */
    public function rejectSuggestion(string $propertyGroup, string $optionName, Context $context): int
    {
        $criteria = new Criteria();
        $criteria->addFilter(new NotFilter(
            'AND',
            [new EqualsFilter('suggestedPropertyOptionCandidates', null)]
        ));

        $results = $this->aiAnalysisResultRepository->search($criteria, $context);
        $updates = [];

        foreach ($results as $result) {
            $suggestions = $result->get('suggestedPropertyOptionCandidates');

            if (!is_array($suggestions) || !isset($suggestions[$propertyGroup])) {
                continue;
            }

            $filtered = array_filter(
                $suggestions[$propertyGroup],
                fn($opt) => strtolower($opt) !== strtolower($optionName)
            );

            if (count($filtered) !== count($suggestions[$propertyGroup])) {
                $suggestions[$propertyGroup] = array_values($filtered);

                if (empty($suggestions[$propertyGroup])) {
                    unset($suggestions[$propertyGroup]);
                }

                $updates[] = [
                    'id' => $result->id,
                    'suggestedPropertyOptionCandidates' => empty($suggestions) ? null : $suggestions,
                ];
            }
        }

        if (!empty($updates)) {
            $this->aiAnalysisResultRepository->update($updates, $context);
        }

        return count($updates);
    }
}
