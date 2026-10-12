# Switching the extension to production

This extension has **no settings screen** for the addresses of the Flipick service. The addresses are constants in one file:

```
Model/Endpoints.php
```

To go to production (or to publish on the Adobe Commerce Marketplace) you change that file and nothing else.

## 1. The three constants

| Constant | What it is | Production value |
|---|---|---|
| `ADAPTER_URL` | The address of the Flipick adapter that an admin's **browser** opens (inside the frame of the *Flipick Video Generator* page). Also used to allow that address in the admin's Content-Security-Policy (the browser rule about which sites may be framed). | The final public **https** address, for example `https://magento.flipickvideogenerator.com`. No trailing slash needed. |
| `ADAPTER_SERVER_URL` | The address the Magento **server** (PHP) uses to call the adapter. | Leave **empty**. Empty means "same as `ADAPTER_URL`". Only a Magento running in Docker next to a local adapter needs a different value (`http://host.docker.internal:4200`). |
| `STORE_URL_FOR_ADAPTER` | The address the adapter uses to reach the merchant's **store**. | Leave **empty**. Empty means "the store's own base URL". Set it only if the adapter must use a different address than the store advertises. |

Rules for production:
- `ADAPTER_URL` must be `https://` and publicly reachable.
- The merchant's store must be reachable from the internet over **https**. The production adapter refuses stores on `http`, `localhost` or private network addresses.

## 2. Release checklist (do this every time you build a package)

1. Open `Model/Endpoints.php`. Confirm `ADAPTER_URL` is the final production address. Confirm the other two constants are `''`.
2. Open `etc/adminhtml/csp_whitelist.xml`. It contains a fixed `http://localhost:4200` entry that is only for local development. **Remove that `<value>` entry before submitting to the Marketplace.** (The real `ADAPTER_URL` is allowed automatically by `Model/Csp/AdapterCollector.php`; it needs no XML entry.)
3. Raise the version in `composer.json` and in `AdapterClient::EXTENSION_VERSION` (`Model/AdapterClient.php`) so they match.
4. Build the package without `tools/` and without any `.git` folder.
5. Install the package on a **clean** Magento and run the checks in section 4.

## 3. How the extension picks an address (for developers)

For each address the extension uses the first of these that is not empty:

1. A **developer override** stored in the database table `core_config_data` (or in `app/etc/env.php`) under the path listed below. It is not shown anywhere in the admin, and a merchant who installs from the Marketplace never has one.
2. The **constant** in `Model/Endpoints.php`.

(`ADAPTER_SERVER_URL` has a third step: if both are empty, the browser address is used, with `localhost` replaced by `host.docker.internal`.)

| Developer override path | Overrides |
|---|---|
| `flipick_videogenerator/general/adapter_url` | `ADAPTER_URL` |
| `flipick_videogenerator/general/adapter_server_url` | `ADAPTER_SERVER_URL` |
| `flipick_videogenerator/general/store_url_for_adapter` | `STORE_URL_FOR_ADAPTER` |

Why the overrides exist: while developing with a Cloudflare quick tunnel, the public address changes every time the tunnel restarts, and a constant in code cannot follow it. (`bin/magento config:set` does not accept these paths, because they have no admin field.)

Set an override (local development only):

```sql
INSERT INTO core_config_data (scope, scope_id, path, value)
VALUES ('default', 0, 'flipick_videogenerator/general/adapter_url', 'https://your-tunnel.trycloudflare.com'),
       ('default', 0, 'flipick_videogenerator/general/adapter_server_url', 'http://host.docker.internal:4200')
ON DUPLICATE KEY UPDATE value = VALUES(value);
```
then `bin/magento cache:clean config`.

Remove all overrides (to test exactly what a merchant gets):

```sql
DELETE FROM core_config_data WHERE path IN (
  'flipick_videogenerator/general/adapter_url',
  'flipick_videogenerator/general/adapter_server_url',
  'flipick_videogenerator/general/store_url_for_adapter');
```
then `bin/magento cache:clean config`.

**On a store you are about to ship or demo as "production", make sure these rows are removed**, otherwise the old development address wins over the constant.

## 4. Checks after switching to production

Run them on a Magento install that has no developer overrides:

1. **The settings are gone.** Stores → Configuration has no *Video Generator* section.
2. **Menu.** The admin menu has *Flipick → Flipick Video Generator*.
3. **Connect.** Opening *Flipick Video Generator* on a store that is not connected shows the Connect page with no warning. Click *Connect to Flipick*: you should see *Connected to Flipick*. (This proves the Magento server reached the adapter and the adapter reached the store.)
4. **Frame.** The *Flipick Video Generator* page shows the product list inside the frame, with no errors in the browser console (no Content-Security-Policy messages).
5. **Websites.** With more than one website, the *Website* dropdown lists them and switching reloads the page for the chosen website.
6. **Logs.** `var/log/system.log` has no *Flipick* errors.

If step 3 fails with "The Flipick service address is missing in this extension", `ADAPTER_URL` is empty. If the frame stays blank and the browser console mentions *Content-Security-Policy*, the address in `ADAPTER_URL` is not an `http(s)` address (see `var/log/system.log`, message *Flipick: the Adapter URL is not a valid http(s) address; the admin frame will be blocked by CSP*).

## 5. Related files

| File | Purpose |
|---|---|
| `Model/Endpoints.php` | The constants (the only file to edit for the addresses) |
| `Model/AdapterClient.php` | Uses the addresses: `getBrowserUrl()`, `getServerUrl()`, `getStoreUrlForAdapter()` |
| `Model/Csp/AdapterCollector.php` | Allows `ADAPTER_URL` as a frame source in the admin |
| `Controller/Adminhtml/Index/ConnectSave.php` | Connect action; sends the store address to the adapter |
| `etc/adminhtml/csp_whitelist.xml` | Fixed local-development frame source: remove before the Marketplace |
| `etc/adminhtml/system.xml`, `etc/config.xml` | Intentionally empty: no settings, no defaults |
