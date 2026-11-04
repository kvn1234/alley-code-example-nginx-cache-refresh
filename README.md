# Website Cache Refresh — v1.0.0

A compact, theme-independent WordPress plugin for manually clearing a cPanel account's ea-nginx cache. Authorized users open **Clear Website Cache** in the admin bar (on the front end or in the dashboard), then press the button on the Website Cache page.

Clearing can make recent changes visible. It does not delete site content or settings. **The operation affects all websites belonging to the configured cPanel user**, not just the current WordPress installation. Use only where that account-wide access is appropriate.

No automatic purging, cron jobs, header scraping, theme integrations, database credential storage, or password authentication. Browser, CDN, and other plugin caches are outside its scope.

## Requirements and installation

- WordPress 6.2 or later; PHP 7.4 or later (a currently supported PHP release is recommended).
- Hosting with cPanel ea-nginx caching enabled for the target account and the UAPI `NginxCaching/clear_cache` function available.
- A valid HTTPS certificate on the cPanel hostname and server-side connectivity from WordPress to port 2083.
- An API token for the cPanel user that owns the site. A WHM/root token is not interchangeable.

1. Test on your own site before deploying to clients. Back up `wp-config.php` before editing it.
2. In WordPress, go to **Plugins → Add New Plugin → Upload Plugin** and select `website-cache-refresh-1.0.0.zip`.
3. Install and activate. Activation does not make a cPanel request or clear any cache.
4. Configure the constants below, then open **Website Cache** in the dashboard or **Clear Website Cache** in the admin bar.
5. Press **Clear Website Cache**, check the result, and reload the public website.

## Recommended configuration: wp-config.php

Add these above the “That's all, stop editing” line, before WordPress is loaded. Replace every placeholder with your own account's values:

```php
define( 'WEBSITE_CACHE_REFRESH_CPANEL_HOST', 'server.example.com' );
define( 'WEBSITE_CACHE_REFRESH_CPANEL_USERNAME', 'your_cpanel_username' );
define( 'WEBSITE_CACHE_REFRESH_CPANEL_API_TOKEN', 'PASTE_YOUR_CPANEL_API_TOKEN_HERE' );
```

Use the actual cPanel server hostname covered by its TLS certificate. Enter only the hostname, without `https://`, port, path, or trailing slash. The plugin uses HTTPS port 2083 and `/execute/NginxCaching/clear_cache`. Constants apply to every installation sharing that configuration, including sites in a multisite network. There is no per-site or network configuration interface.

Values must be PHP strings. Preserve the token exactly and escape quotes/backslashes as needed for a PHP string. Do not put real credentials in this README, the plugin source, source control, screenshots, support tickets, or shared backups. Protect `wp-config.php` and backups with appropriate server permissions. The plugin never shows configured values in its UI.

## Create and use a cPanel API token

1. Sign in to the site's **cPanel account**, then open **Security → Manage API Tokens**. If unavailable, ask the hosting provider to enable the feature.
2. Choose **Create**, give it a unique name such as `website-cache-refresh`, and choose an expiration date that fits your token-rotation process.
3. Create the token, copy it securely, and save it directly in the site's `wp-config.php`. cPanel displays the token only at creation.
4. Confirm that you saved it. Test the plugin, and schedule your own rotation before expiry; this plugin does not schedule reminders or jobs.

A cPanel token may grant broad account access; do not assume it is restricted to cache clearing. Use the least access your hosting provider supports for this endpoint. **Never share tokens.** Revoke and replace a token if disclosed. Use a separate token per deployment where practical so revocation is independent. To rotate, create a replacement, update the constant, test, then revoke the old token in cPanel.

Official references:
- [cPanel API token creation and revocation](https://docs.cpanel.net/cpanel/security/manage-api-tokens-in-cpanel/)
- [Token authentication](https://docs.cpanel.net/knowledge-base/security/how-to-use-cpanel-api-tokens/)
- [UAPI clear_cache endpoint](https://api.docs.cpanel.net/openapi/cpanel/operation/clear_cache/)
- [ea-nginx administration](https://docs.cpanel.net/knowledge-base/nginx/the-ea-nginx-script/)

## Permissions and extension

The default capability is `manage_options`, normally available to administrators. The same capability gates the admin-bar entry, dashboard page, and action handler. The handler requires a logged-in user, a POST request, and a valid WordPress nonce. Opening a link never clears the cache. There is no unauthenticated action handler.

A site-specific plugin or must-use plugin can change the capability:

```php
add_filter( 'website_cache_refresh_capability', function ( $capability ) {
    return 'clear_website_cache';
} );
```

Your deploymentmust separately grant the custom capability to selected roles/users. The plugin does not create roles or grant capabilities. Choose carefully: anyone granted access can clear the entire configured cPanel account's NGINX cache. Nonces prevent cross-site request forgery; capability checks provide authorization. On multisite, the constants are shared and users with the selected capability on an individual site can invoke the account-wide action.

## Request security and diagnostics

The server uses the WordPress HTTP API with an `Authorization: cpanel USERNAME:TOKEN` header, verified TLS, a 20-second timeout, no redirects, and a 64 KiB response limit. Credentials are never placed in the request URL. Only a successful HTTP response with a valid UAPI success status and no reported errors is treated as success.

The plugin does not log requests or responses. Notices and short-lived, user-specific result records contain only fixed diagnostic codes; remote error text and credentials are not displayed or stored. Other software with access to WordPress HTTP hooks or request-debugging facilities may see HTTP headers: ensure those tools redact Authorization headers and do not capture tokens. The plugin cannot protect secrets from other privileged code running in WordPress.

| Diagnostic | What to check |
| --- | --- |
| `configuration_missing` | All three constants must be defined as nonempty strings before WordPress loads. |
| `configuration_invalid` | Hostname format; username; spaces/newlines accidentally copied into credentials. |
| `transport_error` | DNS, firewall/outbound port 2083, TLS certificate/CA trust, and cPanel availability. Do not disable certificate verification. A timeout can occur after the server cleared the cache. |
| `http_401` / `http_403` | Correct cPanel username and token; expiry, revocation, feature access, and hosting security rules. |
| Other `http_NNN` / `http_error` | 3xx: use the direct cPanel hostname; 404: hostname/endpoint; 429: wait; 5xx: cPanel/server health. |
| `malformed_response` | Wrong host, HTML login/error page, proxy interference, unexpected UAPI format, or oversized/truncated response. |
| `uapi_failure` | Ask the host to check ea-nginx support, account caching state, endpoint permission, and server-side diagnostics. Raw UAPI messages are intentionally withheld because remote text may contain secrets. |
| `success`, but changes remain invisible | Reload the page and investigate browser/CDN/other plugin caches or whether the change was actually published. UAPI success confirms the clear operation, not that every visitor will see a particular change. |

Notices are one-time and expire after two minutes. 