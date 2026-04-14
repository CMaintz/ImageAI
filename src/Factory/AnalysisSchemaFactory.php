<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Factory;

use CMaintz\ImageAi\Builder\Schema\AnalysisSchemaBuilder;
use CMaintz\ImageAi\Config\ContentConfiguration;
use CMaintz\ImageAi\Service\Property\PropertyLookupService;
use Shopware\Core\Framework\Context;

/**
 * Factory for building JSON schemas for AI analysis.
 *
 * Loads property groups and delegates schema building to AnalysisSchemaBuilder.
 */
class AnalysisSchemaFactory
{
    public function __construct(
        private readonly PropertyLookupService $propertyLookupService,
        private readonly AnalysisSchemaBuilder $schemaBuilder
    ) {
    }

    /**
     * Build dynamic JSON schema for Gemini API batch analysis.
     *
     * @param ContentConfiguration $contentConfig Content configuration
     * @param Context $context Shopware context
     * @return array Complete JSON schema array
     */
    public function buildBatchSchema(ContentConfiguration $contentConfig, Context $context): array
    {
        $propertyGroups = $this->propertyLookupService->loadAiPropertyGroups($context);

        return $this->schemaBuilder
            ->reset()
            ->setPropertyGroups($propertyGroups)
            ->setContentConfig($contentConfig)
            ->build();
    }
}
