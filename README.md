# Shakewell Headless Hardening

> **Made by [Shakewell](https://shakewell.agency).** Free and open source, built and maintained by the Shakewell team.

Switchable hardening modules for WordPress sites that sit behind a separate front end. A headless site keeps WordPress reachable on its own hostname, so the REST API, the ACF options record, XML-RPC and the legacy Gravity Forms API are all open to the internet. Each module closes one of those, and each can be switched on its own.

## Modules

| Module | Key | Default | What it does |
|---|---|---|---|
| ACF lockdown | `acf_lockdown` | on | Refuses the `acf/*` REST namespace to anyone who is not staff. Blanks credential-shaped ACF fields, including inside objects (password, secret, API key, auth or access token, private key, credentials and so on) in anonymous `wp/v2` and `acf/*` responses. Optionally exposes a short list of ACF fields you name on chosen post types (nothing for a password-protected post), and nothing else from ACF. |
| REST boundaries | `rest_boundaries` | on | Refuses `/wp/vN/users` and `/wp/vN/coauthors` to non-staff, and any namespace you list. Also answers 404 on author archives and drops the core users sitemap and removes `author_url` and `author_name` from oEmbed, because they confirm the same user slugs by another route. |
| XML-RPC off | `xmlrpc_off` | on | Ends any XML-RPC request with a 403 before WordPress builds the server, and empties the method list as a second layer. |
| Gravity Forms API v1 off | `gravity_forms_api_v1_off` | off | Turns off the legacy `/gravityformsapi/` API with the filter Gravity Forms provides, leaves REST API v2 running, and answers the old path with a plain 404. |

Every guard that refuses a request runs last in the filter chain and again just before the route callback, so a third-party callback that returns nothing cannot reopen a route. Guards fail closed: a secret nested too deep to inspect is emptied, not passed through.

## Switch a module

In `wp-config.php`, set `SHAKEWELL_HEADLESS_HARDENING_<KEY>` to `true` or `false`, for example:

```php
define( 'SHAKEWELL_HEADLESS_HARDENING_XMLRPC_OFF', false );
define( 'SHAKEWELL_HEADLESS_HARDENING_GRAVITY_FORMS_API_V1_OFF', true );
```

Or use the filter `shakewell_headless_hardening_module_enabled( $enabled, $module )`. The constant wins over the default, and the filter wins over both.

## Configure with filters

Nothing site-specific is built in. Every list below is empty unless stated.

| Filter | Value | Used by |
|---|---|---|
| `shakewell_headless_hardening_public_routes` | Route prefixes no guard applies to, for example `/example/v1/public`. | ACF lockdown, REST boundaries |
| `shakewell_headless_hardening_staff_capability` | Capability that opens a guarded route. Default `edit_posts`. | ACF lockdown, REST boundaries |
| `shakewell_headless_hardening_guarded_namespaces` | Route prefixes that answer staff only, for example `/example/v1/admin`. | REST boundaries |
| `shakewell_headless_hardening_guarded_wp_bases` | Bases under `/wp/vN` that answer staff only. Default `users`, `coauthors`. | REST boundaries |
| `shakewell_headless_hardening_secret_fields` | Extra exact field names to withhold, on top of the built-in pattern. | ACF lockdown |
| `shakewell_headless_hardening_unredacted_capability` | Capability that sees ACF fields unredacted. Default `edit_pages`, so an author can open `acf` routes (see `staff_capability`) but still gets redacted data. | ACF lockdown |
| `shakewell_headless_hardening_redact_route_prefixes` | Routes whose responses are redacted for non-editors. Default `/wp/v2`, `/acf`. | ACF lockdown |
| `shakewell_headless_hardening_acf_rest_post_types` | Post types that get an `acf` object holding only the fields below. | ACF lockdown |
| `shakewell_headless_hardening_acf_rest_fields` | ACF field names that object exposes. A secret-shaped name is never exposed. | ACF lockdown |

Example, in a small plugin or `functions.php`:

```php
add_filter( 'shakewell_headless_hardening_guarded_namespaces', fn() => array( '/example/v1/admin' ) );
add_filter( 'shakewell_headless_hardening_acf_rest_post_types', fn() => array( 'page' ) );
add_filter( 'shakewell_headless_hardening_acf_rest_fields', fn() => array( 'is_private' ) );
```

## What it does not do

It does not replace a web application firewall, and it does not block `xmlrpc.php` at the web server: do that too, because the web server rule is the cheap gate and this module is what still holds if the rule is lost. It only reads requests and responses; it stores nothing, calls no outside service and adds no admin screen.

## Install

Download `shakewell-headless-hardening.zip` from the [latest release](https://github.com/shakewellagency/shakewell-headless-hardening/releases/latest), or pin it with Composer as described in the [Shakewell plugin template](https://github.com/shakewellagency/shakewell-plugin-template). Requires WordPress 6.5 and PHP 8.1.

## Work on it

```sh
composer install
npm ci
npm run env:start      # needs Docker
npm test               # PHPUnit inside the WordPress test container
composer lint          # WordPress coding standards
npm run plugin-check   # the official Plugin Check
```

## Made by Shakewell

Part of a family of free plugins from [Shakewell](https://shakewell.agency) for headless WordPress sites. Each one does one job, keeps its dependencies small and is released under GPL-2.0-or-later.

Use it, fork it and send fixes. To have it set up on your site, or to have a plugin built, [talk to Shakewell](https://shakewell.agency).

See them all: [Shakewell plugins on GitHub](https://github.com/orgs/shakewellagency/repositories?q=shakewell-).
