<?php declare(strict_types=1);

namespace Illux\ImageAi\Installers;

use Illux\ImageAi\Config\PluginConstants;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;

class CustomFieldsInstaller
{
    /**
     * @param EntityRepository<CustomFieldSetCollection> $customFieldSetRepository
     */
    public function __construct(
        private readonly EntityRepository $customFieldSetRepository,
    ) {
    }

    /**
     * Install custom fields for PropertyGroup entity
     */
    public function install(Context $context): void
    {
        $this->createPropertyGroupCustomFields($context);
    }

    /**
     * Uninstall custom fields
     */
    public function uninstall(Context $context): void
    {
            $this->deleteCustomFieldSet(PluginConstants::CUSTOM_FIELD_SET_NAME, $context);
    }

    /**
     * Create custom fields for PropertyGroup entity
     */
    private function createPropertyGroupCustomFields(Context $context): void
    {
        if ($this->customFieldSetExists(PluginConstants::CUSTOM_FIELD_SET_NAME, $context)) {
            return;
        }

        $this->customFieldSetRepository->create([
            [
                'name' => PluginConstants::CUSTOM_FIELD_SET_NAME,
                'config' => [
                    'label' => [
                        'da-DK' => 'AI Egenskabsgruppe',
                        'en-GB' => 'AI Property Group',
                        'de-DE' => 'AI-Eigenschaftsgruppe',
                    ],
                ],
                'relations' => [
                    [
                        'entityName' => 'property_group',
                    ],
                ],
                'customFields' => [
                    [
                        'name' => PluginConstants::CUSTOM_FIELD_AI_MANAGED,
                        'type' => CustomFieldTypes::BOOL,
                        'config' => [
                            'label' => [
                                'da-DK' => 'AI-Styret',
                                'en-GB' => 'AI Managed',
                                'de-DE' => 'AI-Verwaltet',
                            ],
                            'helpText' => [
                                'da-DK' => 'Denne egenskabsgruppe administreres af AI Image Tools-plugin',
                                'en-GB' => 'This property group is managed by the AI Image Tools plugin',
                                'de-DE' => 'Diese Eigenschaftsgruppe wird vom AI Image Tools-Plugin verwaltet',
                            ],
                            'customFieldPosition' => 1,
                            'componentName' => 'sw-field',
                            'customFieldType' => 'checkbox',
                        ],
                    ],
                ],
            ]
        ], $context);
    }

    /**
     * Check if a custom field set exists by name
     */
    private function customFieldSetExists(string $name, Context $context): bool
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));

        return $this->customFieldSetRepository->searchIds($criteria, $context)->firstId() !== null;
    }

    /**
     * Delete a custom field set by name
     */
    private function deleteCustomFieldSet(string $name, Context $context): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $name));

        /** @var CustomFieldSetEntity|null $customFieldSet */
        $customFieldSet = $this->customFieldSetRepository->search($criteria, $context)->first();
        if ($customFieldSet !== null) {
            $this->customFieldSetRepository->delete(
                [['id' => $customFieldSet->getId()]],
                $context
            );
        }
    }
}
