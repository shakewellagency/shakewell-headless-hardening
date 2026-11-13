=== Shakewell Headless Hardening ===
Contributors: shakewellagency
Tags: security, rest-api, headless, xmlrpc, acf
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.2.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Switchable hardening for headless sites: REST user lists, ACF secrets, XML-RPC and the legacy Gravity Forms API.

== Description ==

A site with a separate front end keeps WordPress open on its own hostname. This plugin closes the common exposures, one module at a time, each with its own switch.

* **ACF lockdown** (on by default): refuses the acf REST namespace to non-staff and blanks password, secret, API key and token fields in anonymous REST responses. You can name the few ACF fields that stay public.
* **REST boundaries** (on by default): staff only for the users and co-authors routes and for any namespace you list, plus 404 on author archives and no author link in oEmbed.
* **XML-RPC off** (on by default): ends every XML-RPC request.
* **Gravity Forms API v1 off** (off by default): turns off the legacy Gravity Forms Web API and leaves REST API v2 running. Needs Gravity Forms, which is a separate product.
* **Gravity Forms addresses** (off by default): removes notification settings, and the staff addresses in them, from Gravity Forms REST v2 form definitions.
* **No index on chosen hosts** (does nothing until you list hostnames): sends noindex on pages and REST responses, a robots meta tag and a disallow-all robots.txt on the hostnames you list in SHAKEWELL_HEADLESS_HARDENING_NOINDEX_HOST_LIST (comma-separated) or the shakewell_headless_hardening_noindex_hosts filter, and leaves every other hostname alone.

Switch a module with the constant SHAKEWELL_HEADLESS_HARDENING_ followed by the module key in capitals, for example SHAKEWELL_HEADLESS_HARDENING_XMLRPC_OFF, or with the filter shakewell_headless_hardening_module_enabled. The route and field lists are filters, all empty by default. See the readme in the repository for the full list.

The plugin stores nothing and adds no admin screen.

= External services =

None. The plugin makes no outside requests.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install it with Composer.
2. Activate it from the Plugins screen. The default modules start working at once.
3. Add constants or filters to change which modules run and which routes and fields they cover.

== Frequently Asked Questions ==

= Will it break my site's REST clients? =

Anonymous clients lose access to the users and co-authors routes and the acf namespace. Add a route prefix to the shakewell_headless_hardening_public_routes filter to keep one open, or switch the module off.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.2.0 =
* New module: Gravity Forms addresses.
* New module: No index on chosen hosts.

= 0.1.0 =
* First release.
