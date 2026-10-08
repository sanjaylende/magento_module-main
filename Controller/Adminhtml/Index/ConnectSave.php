<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Controller\Adminhtml\Index;

use Flipick\VideoGenerator\Model\AdapterClient;
use Flipick\VideoGenerator\Model\IntegrationManager;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Model\Auth\Session as AuthSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ProductMetadataInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Registers this installation with the adapter: creates the Magento integration (token), sends it with the store's base URL,
 * name, country and admin email, and stores the install key and secret the adapter returns.
 */
class ConnectSave extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'Flipick_VideoGenerator::video_generator';

    /**
     * @var AdapterClient
     */
    private $adapter;

    /**
     * @var IntegrationManager
     */
    private $integrations;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var ProductMetadataInterface
     */
    private $productMetadata;

    /**
     * @var AuthSession
     */
    private $authSession;

    public function __construct(
        Context $context,
        AdapterClient $adapter,
        IntegrationManager $integrations,
        StoreManagerInterface $storeManager,
        ScopeConfigInterface $scopeConfig,
        ProductMetadataInterface $productMetadata,
        AuthSession $authSession
    )
    {
        parent::__construct($context);
        $this->adapter = $adapter;
        $this->integrations = $integrations;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->productMetadata = $productMetadata;
        $this->authSession = $authSession;
    }

    public function execute()
    {
        $redirect = $this->resultFactory->create(ResultFactory::TYPE_REDIRECT);
        try {
            $token = $this->integrations->ensureAccessToken();
            $baseUrl = rtrim((string)$this->scopeConfig->getValue('web/secure/base_url', ScopeInterface::SCOPE_STORE), '/');
            $name = trim((string)$this->scopeConfig->getValue('general/store_information/name'));
            $admin = $this->authSession->getUser();
            $result = $this->adapter->register([
                'baseUrl' => $this->publicBaseUrl($baseUrl),
                'magentoToken' => $token,
                'merchantName' => $name !== '' ? $name : parse_url($baseUrl, PHP_URL_HOST),
                'contactEmail' => $admin ? $admin->getEmail() : null,
                'countryCode' => (string)$this->scopeConfig->getValue('general/country/default'),
                'magentoVersion' => $this->productMetadata->getVersion(),
                'extensionVersion' => AdapterClient::EXTENSION_VERSION,
            ]);
            $this->adapter->saveCredentials($result['installKey'], $result['secret']);
            $this->messageManager->addSuccessMessage(__(
                'Connected to Flipick. %1 website(s) are ready, each starting on the free trial.',
                count($result['stores'] ?? [])
            ));
            return $redirect->setPath('*/*/billing');
        } catch (\Throwable $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            return $redirect->setPath('*/*/connect');
        }
    }

    /**
     * The address the adapter uses to reach this store. Merchants normally leave "Store URL for the adapter" empty and the
     * store's own base URL is used; set it when the adapter must use a different address (for example a Docker host name).
     */
    private function publicBaseUrl(string $baseUrl): string
    {
        $override = trim((string)$this->scopeConfig->getValue('flipick_videogenerator/general/store_url_for_adapter'));
        return $override !== '' ? rtrim($override, '/') : $baseUrl;
    }
}
