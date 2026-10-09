<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Setup;

use Flipick\VideoGenerator\Model\AdapterClient;
use Flipick\VideoGenerator\Model\IntegrationManager;
use Magento\Framework\Setup\ModuleContextInterface;
use Magento\Framework\Setup\SchemaSetupInterface;
use Magento\Framework\Setup\UninstallInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Psr\Log\LoggerInterface;

/**
 * Runs on "bin/magento module:uninstall Flipick_VideoGenerator". Tells the Flipick service this installation is gone (its
 * data is kept there), then removes the credentials and the Magento integration created at connect time. Everything is
 * best effort: an unreachable service must not block removing the module.
 */
class Uninstall implements UninstallInterface
{
    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var IntegrationServiceInterface
     */
    private $integrationService;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        AdapterClient $adapter,
        IntegrationServiceInterface $integrationService,
        LoggerInterface $logger
    )
    {
        $this->adapter = $adapter;
        $this->integrationService = $integrationService;
        $this->logger = $logger;
    }

    public function uninstall(SchemaSetupInterface $setup, ModuleContextInterface $context): void
    {
        if ($this->adapter->isConnected()) {
            try {
                $this->adapter->post('/api/v1/uninstall');
            } catch (\Throwable $e) {
                // service unreachable: the installation is simply marked unused later
                $this->logger->warning('Flipick: the adapter was not told about the uninstall', ['error' => $e->getMessage()]);
            }
        }
        try {
            $integration = $this->integrationService->findByName(IntegrationManager::NAME);
            if ($integration->getId()) {
                $this->integrationService->delete((int)$integration->getId());
            }
        } catch (\Throwable $e) {
            // already removed
            $this->logger->info('Flipick: integration already removed or not removable', ['error' => $e->getMessage()]);
        }
        $connection = $setup->getConnection();
        $connection->delete($setup->getTable('core_config_data'), ['path LIKE ?' => 'flipick_videogenerator/%']);
    }
}
