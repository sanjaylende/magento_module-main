<?php
declare(strict_types=1);

namespace Flipick\VideoGenerator\Model;

/**
 * The only place that holds the addresses of the Flipick service. There are no admin settings for them.
 *
 * BEFORE A MARKETPLACE RELEASE: set ADAPTER_URL to the production address of the adapter. Leave the other two empty unless
 * you have a reason (see below). Nothing else in the extension needs to change. The full release checklist and the checks to
 * run afterwards are in PRODUCTION-CONFIGURATION.md in the extension's root folder.
 *
 * Development override: a developer can still point one installation somewhere else (for example at a Cloudflare tunnel whose
 * address changes on every start) by storing a value under the OVERRIDE_* path in the core_config_data table or in
 * app/etc/env.php. The admin screen does not show these values and a merchant installing from the Marketplace never has them,
 * so the constants below always apply to merchants.
 */
class Endpoints
{
    /**
     * Where an admin's BROWSER reaches the adapter (loaded in the frame of the Flipick page). Must be https in production.
     */
    public const ADAPTER_URL = 'https://stagemagento.flipick.com/';

    /**
     * Where this Magento SERVER reaches the adapter. Leave empty to use ADAPTER_URL (a public address needs nothing else).
     * Only a Magento running in Docker next to a local adapter needs a different value (host.docker.internal).
     */
    public const ADAPTER_SERVER_URL = '';

    /**
     * Where the adapter reaches this Magento STORE. Leave empty to use the store's own base URL.
     */
    public const STORE_URL_FOR_ADAPTER = '';

    // Developer-override paths (read through the normal Magento configuration; not shown in the admin).
    public const OVERRIDE_ADAPTER_URL = 'flipick_videogenerator/general/adapter_url';
    public const OVERRIDE_ADAPTER_SERVER_URL = 'flipick_videogenerator/general/adapter_server_url';
    public const OVERRIDE_STORE_URL_FOR_ADAPTER = 'flipick_videogenerator/general/store_url_for_adapter';

    /**
     * The developer override when there is one, otherwise the constant.
     *
     * @param mixed $override value read from the configuration (usually null)
     * @param string $constant
     * @return string
     */
    public static function pick($override, string $constant): string
    {
        $value = trim((string)$override);
        return $value !== '' ? $value : $constant;
    }
}
