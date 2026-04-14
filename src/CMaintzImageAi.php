<?php declare(strict_types=1);

namespace CMaintz\ImageAi;

use CMaintz\ImageAi\Installers\PropertyGroupInstaller;
use CMaintz\ImageAi\Installers\MediaFolderInstaller;
use CMaintz\ImageAi\Installers\CustomFieldsInstaller;
use RuntimeException;
use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;

class CMaintzImageAi extends Plugin
{

    public function executeComposerCommands(): bool
    {
        return true;
    }

    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
        // Install essential plugin structures
        $this->getCustomFieldsInstaller()->install($installContext->getContext());
        $this->getPropertyGroupInstaller()->install($installContext->getContext());
        $this->getMediaFolderInstaller()->install($installContext->getContext());
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);

        $this->getCustomFieldsInstaller()->install($updateContext->getContext());
        $this->getPropertyGroupInstaller()->install($updateContext->getContext());
        $this->getMediaFolderInstaller()->install($updateContext->getContext());
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if (!$uninstallContext->keepUserData()) {
            $this->getCustomFieldsInstaller()->uninstall($uninstallContext->getContext());
            $this->getPropertyGroupInstaller()->uninstall($uninstallContext->getContext());
            $this->getMediaFolderInstaller()->uninstall($uninstallContext->getContext());
        }
    }

    private function getPropertyGroupInstaller(): PropertyGroupInstaller
    {
        $container = $this->container;
        if ($container === null) {
            throw new RuntimeException('Container is not available');
        }

        if ($container->has(PropertyGroupInstaller::class)) {
            /** @var PropertyGroupInstaller $installer */
            $installer = $container->get(PropertyGroupInstaller::class);
            return $installer;
        }

        return new PropertyGroupInstaller(
            $container->get('property_group.repository'),
            $container->get('language.repository'),
            $container->get('property_group_option.repository'),
        );
    }

    private function getCustomFieldsInstaller(): CustomFieldsInstaller
    {
        $container = $this->container;
        if ($container === null) {
            throw new RuntimeException('Container is not available');
        }

        if ($container->has(CustomFieldsInstaller::class)) {
            /** @var CustomFieldsInstaller $installer */
            $installer = $container->get(CustomFieldsInstaller::class);
            return $installer;
        }

        return new CustomFieldsInstaller(
            $container->get('custom_field_set.repository')
        );
    }

    private function getMediaFolderInstaller(): MediaFolderInstaller
    {
        $container = $this->container;
        if ($container === null) {
            throw new RuntimeException('Container is not available');
        }

        if ($container->has(MediaFolderInstaller::class)) {
            /** @var MediaFolderInstaller $installer */
            $installer = $container->get(MediaFolderInstaller::class);
            return $installer;
        }

        return new MediaFolderInstaller(
            $container->get('media_folder.repository'),
            $container->get('media_default_folder.repository'),
            $container->get('media_folder_configuration.repository'),
        );
    }
}
