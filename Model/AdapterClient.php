<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Talks to the central Flipick adapter on behalf of this Magento installation.
 *
 * Every call is signed: headers X-Flipick-Key, -Timestamp, -Nonce, -Signature, where
 * Signature = HMAC-SHA256(secret, "<ts>\n<nonce>\n<METHOD>\n<path+query>\n<sha256 of body>"). The adapter rejects stale,
 * replayed or tampered requests. The browser never sees the secret: the admin page opens the adapter with a one-time,
 * signed launch token instead (see launchUrl()).
 *
 * Two URLs: the one an admin's BROWSER uses (adapter_url) and the one this server uses (adapter_server_url). They differ
 * when Magento runs in Docker, where "localhost" is the container itself.
 */
class AdapterClient
{
    public const XML_PATH_ADAPTER_URL = 'flipick_videogenerator/general/adapter_url';
    public const XML_PATH_ADAPTER_SERVER_URL = 'flipick_videogenerator/general/adapter_server_url';
    public const XML_PATH_INSTALL_KEY = 'flipick_videogenerator/connection/install_key';
    public const XML_PATH_SECRET = 'flipick_videogenerator/connection/secret';
    public const EXTENSION_VERSION = '1.0.0';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var WriterInterface
     */
    private $configWriter;

    /**
     * @var TypeListInterface
     */
    private $cacheTypeList;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var CurlFactory
     */
    private $curlFactory;

    /**
     * @var Json
     */
    private $json;

    /**
     * @var LoggerInterface
     */
    private $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        WriterInterface $configWriter,
        TypeListInterface $cacheTypeList,
        EncryptorInterface $encryptor,
        CurlFactory $curlFactory,
        Json $json,
        LoggerInterface $logger
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->configWriter = $configWriter;
        $this->cacheTypeList = $cacheTypeList;
        $this->encryptor = $encryptor;
        $this->curlFactory = $curlFactory;
        $this->json = $json;
        $this->logger = $logger;
    }

    /** URL the admin's browser loads (iframe). */
    public function getBrowserUrl(): string
    {
        return rtrim((string)$this->scopeConfig->getValue(self::XML_PATH_ADAPTER_URL), '/');
    }

    /** URL this server calls; falls back to the browser URL with localhost swapped for host.docker.internal. */
    public function getServerUrl(): string
    {
        $configured = trim((string)$this->scopeConfig->getValue(self::XML_PATH_ADAPTER_SERVER_URL));
        if ($configured !== '') {
            return rtrim($configured, '/');
        }
        return preg_replace('#//(localhost|127\.0\.0\.1)(?=[:/]|$)#', '//host.docker.internal', $this->getBrowserUrl());
    }

    public function getInstallKey(): string
    {
        return (string)$this->scopeConfig->getValue(self::XML_PATH_INSTALL_KEY);
    }

    private function getSecret(): string
    {
        $stored = (string)$this->scopeConfig->getValue(self::XML_PATH_SECRET);
        return $stored === '' ? '' : (string)$this->encryptor->decrypt($stored);
    }

    public function isConnected(): bool
    {
        return $this->getInstallKey() !== '' && $this->getSecret() !== '';
    }

    /** Saves the credentials the adapter issued at registration. The secret is stored encrypted. */
    public function saveCredentials(string $installKey, string $secret): void
    {
        $this->configWriter->save(self::XML_PATH_INSTALL_KEY, $installKey);
        $this->configWriter->save(self::XML_PATH_SECRET, $this->encryptor->encrypt($secret));
        $this->cacheTypeList->cleanType('config');
        $this->logger->info('Flipick: adapter credentials saved', ['install_key' => $installKey]);
    }

    public function clearCredentials(): void
    {
        $this->configWriter->delete(self::XML_PATH_INSTALL_KEY);
        $this->configWriter->delete(self::XML_PATH_SECRET);
        $this->cacheTypeList->cleanType('config');
        $this->logger->info('Flipick: adapter credentials cleared');
    }

    /**
     * Onboarding call (unsigned: there are no credentials yet). The Magento token proves control of this store.
     *
     * @return array{installKey: string, secret: string, stores: array<int, array<string, string>>}
     * @throws LocalizedException
     */
    public function register(array $payload): array
    {
        return $this->send('POST', '/api/v1/register', $payload, null, false);
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function get(string $path, ?string $websiteId = null): array
    {
        return $this->signed('GET', $path, null, $websiteId);
    }

    /**
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    public function post(string $path, array $body = [], ?string $websiteId = null): array
    {
        return $this->signed('POST', $path, $body, $websiteId);
    }

    /**
     * One-time URL that opens the adapter UI for a website (and optionally one product) inside the admin modal.
     * The launch token is signed with the installation secret and is valid for five minutes, single use.
     */
    public function launchUrl(string $websiteId, ?string $uniqueTag = null): string
    {
        $payload = rtrim(strtr(base64_encode((string)$this->json->serialize([
            'k' => $this->getInstallKey(),
            'w' => $websiteId,
            'e' => time() + 300,
            'n' => bin2hex(random_bytes(12)),
        ])), '+/', '-_'), '=');
        $token = $payload . '.' . hash_hmac('sha256', $payload, $this->getSecret());
        $query = ['embed' => 1, 'launch' => $token];
        if ($uniqueTag !== null && $uniqueTag !== '') {
            $query['product'] = $uniqueTag;
        }
        return $this->getBrowserUrl() . '/?' . http_build_query($query);
    }

    private function signed(string $method, string $path, ?array $body, ?string $websiteId): array
    {
        if (!$this->isConnected()) {
            throw new LocalizedException(__('This store is not connected to Flipick yet.'));
        }
        try {
            return $this->send($method, $path, $body, $websiteId, true);
        } catch (LocalizedException $e) {
            // A website created after connecting is unknown to the adapter until the websites are synced.
            if ($websiteId !== null && strpos($e->getMessage(), 'Unknown store') !== false) {
                $this->logger->info('Flipick: website unknown to the adapter, syncing websites and retrying', ['website' => $websiteId]);
                $this->send('POST', '/api/v1/stores/sync', [], null, true);
                return $this->send($method, $path, $body, $websiteId, true);
            }
            throw $e;
        }
    }

    private function send(string $method, string $path, ?array $body, ?string $websiteId, bool $sign): array
    {
        $base = $this->getServerUrl();
        if ($base === '') {
            throw new LocalizedException(__('Set the Adapter URL under Stores > Configuration > Video Generator.'));
        }
        $raw = $body === null ? '' : ($body === [] ? '{}' : (string)$this->json->serialize($body));
        $curl = $this->curlFactory->create();
        $curl->setTimeout(90); // the first call may fetch the whole Magento catalog
        $curl->addHeader('Accept', 'application/json');
        if ($raw !== '') {
            $curl->addHeader('Content-Type', 'application/json');
        }
        if ($sign) {
            $ts = (string)time();
            $nonce = bin2hex(random_bytes(16));
            $curl->addHeader('X-Flipick-Key', $this->getInstallKey());
            $curl->addHeader('X-Flipick-Timestamp', $ts);
            $curl->addHeader('X-Flipick-Nonce', $nonce);
            $curl->addHeader('X-Flipick-Signature', hash_hmac(
                'sha256',
                $ts . "\n" . $nonce . "\n" . $method . "\n" . $path . "\n" . hash('sha256', $raw),
                $this->getSecret()
            ));
            if ($websiteId !== null) {
                $curl->addHeader('X-Flipick-Website', $websiteId);
            }
        }
        $startedAt = microtime(true);
        try {
            $method === 'POST' ? $curl->post($base . $path, $raw) : $curl->get($base . $path);
        } catch (\Throwable $e) {
            $this->logger->error('Flipick: cannot reach the video adapter', [
                'method' => $method, 'path' => $path, 'base' => $base, 'error' => $e->getMessage(),
                'ms' => (int)((microtime(true) - $startedAt) * 1000),
            ]);
            throw new LocalizedException(__('Cannot reach the video adapter at %1: %2', $base, $e->getMessage()));
        }
        $elapsedMs = (int)((microtime(true) - $startedAt) * 1000);
        $responseBody = (string)$curl->getBody();
        $data = [];
        try {
            $data = $responseBody !== '' ? (array)$this->json->unserialize($responseBody) : [];
        } catch (\Throwable $e) {
            // non-JSON body: handled by the status check below
            $this->logger->warning('Flipick: adapter answered with a non-JSON body', ['path' => $path, 'status' => $curl->getStatus()]);
        }
        $this->logger->debug('Flipick: adapter call', ['method' => $method, 'path' => $path, 'status' => $curl->getStatus(), 'ms' => $elapsedMs]);
        if ($curl->getStatus() >= 400) {
            $this->logger->warning('Flipick: adapter rejected the call', [
                'method' => $method, 'path' => $path, 'status' => $curl->getStatus(), 'error' => $data['error'] ?? null, 'ms' => $elapsedMs,
            ]);
            throw new LocalizedException(__('Video adapter error (%1): %2', $curl->getStatus(), $data['error'] ?? $responseBody));
        }
        return $data;
    }
}
