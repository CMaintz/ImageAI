<?php declare(strict_types=1);

namespace CMaintz\ImageAi\Core\Content\AiBatchJob;

use DateTimeInterface;
use CMaintz\ImageAi\Core\Content\AiAnalysisResult\Entity\AiAnalysisResultCollection;
use CMaintz\ImageAi\Model\Enum\BatchJobStatusEnum;
use CMaintz\ImageAi\Model\Enum\BatchJobTypeEnum;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Entity as EntityAttribute;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\Field;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\FieldType;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\OneToMany;
use Shopware\Core\Framework\DataAbstractionLayer\Attribute\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;

#[EntityAttribute('ai_batch_job', collectionClass: AiBatchJobCollection::class)]
class AiBatchJobEntity extends Entity
{
    #[PrimaryKey]
    #[Field(type: FieldType::UUID, api: true)]
    public string $id;

    #[Field(type: FieldType::ENUM, api: true)]
    public BatchJobTypeEnum $type;

    #[Field(type: FieldType::ENUM, api: true)]
    public BatchJobStatusEnum $status;

    #[Field(type: FieldType::INT, api: true)]
    public int $totalItems = 0;

    #[Field(type: FieldType::INT, api: true)]
    public int $processedItems = 0;

    #[Field(type: FieldType::INT, api: true)]
    public int $successCount = 0;

    #[Field(type: FieldType::INT, api: true)]
    public int $failureCount = 0;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $productIds = null;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $config = null;

    #[Field(type: FieldType::JSON, api: true)]
    public ?array $metadataFilters = null;

    #[Field(type: FieldType::TEXT, api: true)]
    public ?string $errorMessage = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?DateTimeInterface $startedAt = null;

    #[Field(type: FieldType::DATETIME, api: true)]
    public ?DateTimeInterface $completedAt = null;

    #[OneToMany(entity: 'ai_analysis_result', ref: 'batchJobId', api: true)]
    public ?AiAnalysisResultCollection $analysisResults = null;

    public function getPercentage(): float
    {
        if ($this->totalItems === 0) {
            return 0.0;
        }

        return round(($this->processedItems / $this->totalItems) * 100, 1);
    }

    public function isCompleted(): bool
    {
        return $this->status === BatchJobStatusEnum::Completed;
    }

    public function isFailed(): bool
    {
        return $this->status === BatchJobStatusEnum::Failed;
    }

    public function isProcessing(): bool
    {
        return $this->status === BatchJobStatusEnum::Processing;
    }

    public function isQueued(): bool
    {
        return $this->status === BatchJobStatusEnum::Queued;
    }
}
