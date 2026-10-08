<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Integration\Api\AuthorizationServiceInterface;
use Magento\Integration\Api\IntegrationServiceInterface;
use Magento\Integration\Api\OauthServiceInterface;
use Magento\Integration\Model\Integration;

/**
 * Creates (once) the Magento integration the Flipick adapter uses to read the catalog and write the video attributes,
 * and returns its access token, so merchants never copy tokens by hand. The integration can be reviewed or revoked under
 * System > Extensions > Integrations.
 */
class IntegrationManager
{
    public const NAME = 'Flipick Video Generator';

    /** Only what the adapter needs: list/read products and attributes, write products, read websites. */
    private const RESOURCES = [
        'Magento_Catalog::catalog',
        'Magento_Catalog::catalog_inventory',
        'Magento_Catalog::products',
        'Magento_Catalog::categories',
        'Magento_Backend::stores',
        'Magento_Backend::stores_settings',
        'Magento_Backend::store',
        'Magento_Backend::stores_attributes',
        'Magento_Catalog::attributes_attributes',
    ];

    /**
     * @var IntegrationServiceInterface
     */
    private $integrationService;

    /**
     * @var AuthorizationServiceInterface
     */
    private $authorizationService;

    /**
     * @var OauthServiceInterface
     */
    private $oauthService;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    public function __construct(
        IntegrationServiceInterface $integrationService,
        AuthorizationServiceInterface $authorizationService,
        OauthServiceInterface $oauthService,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList
    )
    {
        $this->integrationService = $integrationService;
        $this->authorizationService = $authorizationService;
        $this->oauthService = $oauthService;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
    }

    /**
     * @throws LocalizedException
     */
    public function ensureAccessToken(): string
    {
        // Needed so the adapter can use the integration token as an ordinary Bearer token.
        $this->configWriter->save('oauth/consumer/enable_integration_as_bearer', '1');
        $this->cacheTypeList->cleanType('config');

        $integration = $this->integrationService->findByName(self::NAME);
        if (!$integration->getId()) {
            $integration = $this->integrationService->create([
                'name' => self::NAME,
                'status' => Integration::STATUS_INACTIVE,
                'setup_type' => Integration::TYPE_MANUAL,
            ]);
        }
        // Activate first: saving an integration resets its permissions, so they are granted after.
        if ((int)$integration->getStatus() !== Integration::STATUS_ACTIVE) {
            $integration->setStatus(Integration::STATUS_ACTIVE);
            $this->integrationService->update($integration->getData());
        }
        $this->authorizationService->grantPermissions((int)$integration->getId(), self::RESOURCES);

        $consumerId = (int)$integration->getConsumerId();
        $token = $this->oauthService->getAccessToken($consumerId);
        if (!$token) {
            $this->oauthService->createAccessToken($consumerId, false);
            $token = $this->oauthService->getAccessToken($consumerId);
        }
        if (!$token) {
            throw new LocalizedException(__('Could not create the Magento integration token.'));
        }

        return (string)$token->getToken();
    }
}
