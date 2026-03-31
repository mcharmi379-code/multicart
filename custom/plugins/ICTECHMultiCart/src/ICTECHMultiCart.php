<?php declare(strict_types=1);

namespace ICTECHMultiCart;

use Shopware\Core\Framework\Plugin;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\ContainerInterface;

final class ICTECHMultiCart extends Plugin
{
    public function install(InstallContext $installContext): void
    {
        parent::install($installContext);
    }

    public function uninstall(UninstallContext $uninstallContext): void
    {
        parent::uninstall($uninstallContext);

        if ($uninstallContext->keepUserData()) {
            return;
        }

        $this->removePluginData();
    }

    public function activate(ActivateContext $activateContext): void
    {
        parent::activate($activateContext);
    }

    public function deactivate(DeactivateContext $deactivateContext): void
    {
        parent::deactivate($deactivateContext);
    }

    public function update(UpdateContext $updateContext): void
    {
        parent::update($updateContext);
    }

    public function postInstall(InstallContext $installContext): void
    {
        parent::postInstall($installContext);
    }

    public function postUpdate(UpdateContext $updateContext): void
    {
        parent::postUpdate($updateContext);
    }

    /**
     * Remove every persistent data structure created by the plugin so Shopware
     * falls back to its default storefront and administration flows after uninstall.
     */
    private function removePluginData(): void
    {
        $connection = $this->getConnection();

        $this->removeSystemConfig($connection);
        $this->dropPluginTables($connection);
    }

    private function getConnection(): Connection
    {
        $container = $this->container;

        if (!$container instanceof ContainerInterface) {
            throw new \RuntimeException('Plugin container is not available.');
        }

        $connection = $container->get(Connection::class);

        if (!$connection instanceof Connection) {
            throw new \RuntimeException('Doctrine DBAL connection service is not available.');
        }

        return $connection;
    }

    /**
     * Clear any saved plugin configuration, including legacy placeholder config keys.
     */
    private function removeSystemConfig(Connection $connection): void
    {
        $connection->executeStatement(
            'DELETE FROM `system_config` WHERE `configuration_key` LIKE :configurationKeyPrefix',
            ['configurationKeyPrefix' => 'ICTECHMultiCart.config.%']
        );
    }

    private function dropPluginTables(Connection $connection): void
    {
        foreach ($this->getPluginTables() as $tableName) {
            $connection->executeStatement(sprintf('DROP TABLE IF EXISTS `%s`', $tableName));
        }
    }

    /**
     * Drop child tables first so foreign key dependencies are removed cleanly.
     *
     * @return list<string>
     */
    private function getPluginTables(): array
    {
        return [
            'ictech_multi_cart_order',
            'ictech_multi_cart_item',
            'ictech_multi_cart_blacklist',
            'ictech_multi_cart_config',
            'ictech_multi_cart',
        ];
    }
}
