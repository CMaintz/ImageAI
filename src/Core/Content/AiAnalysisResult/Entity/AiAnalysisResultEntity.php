<?php declare(strict_types=1);

namespace Illux\ImageAi\Core\Content\AiAnalysisResult\Entity;

use Illux\ImageAi\Core\Content\AiBatchJob\AiBatchJobEntity;
use Illux\ImageAi\Model\Enum\AiAnalysisStatusEnum;
use Shopware\Core\Content\Product\ProductEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ForeignKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ManyToOne;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OnDelete;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\ReferenceVersion;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Translations;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

#[EntityAttribute('ai_analysis_result', collectionClass: AiAnalysisResultCollection::class)]
class AiAnalysisResultEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[ForeignKey(entity: 'product', api: true)]
    #[Required]
    public string $productId;

    #[ReferenceVersion('product')]
    #[Required]
    public string $productVersionId;

    #[ManyToOne(entity: 'product', onDelete: OnDelete::CASCADE, api: true)]
    public ?ProductEntity $product = null;

    #[ForeignKey(entity: 'ai_batch_job', api: true)]
    public ?string $batchJobId = null;

    #[ManyToOne(entity: 'ai_batch_job', onDelete: OnDelete::SET_NULL, api: true)]
    public ?AiBatchJobEntity $batchJob = null;

    #[Field(type: FieldType::ENUM, api: true)]
    public AiAnalysisStatusEnum $status;

    #[Field(type: FieldType::FLOAT, api: true)]
    public ?float $totalConfidenceScore;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $confidenceWarnings = null;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $suggestedPropertyOptionCandidates = null;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $analyzedProperties;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $errorMessage = null;

    #[Field(type: FieldType::STRING, translated: true, api: true)]
    public ?string $metaTitle = null;

    #[Field(type: FieldType::TEXT, translated: true, api: true)]
    public ?string $metaDescription = null;

    #[Field(type: FieldType::TEXT, translated: true, api: true)]
    public ?string $seoKeywords = null;

    #[Field(type: FieldType::TEXT, translated: true, api: true)]
    public ?string $productDescription = null;

    #[Translations]
    public ?array $translations = null;
}
