<?php
/**
 * Plugin Name:       Shakewell Headless Hardening
 * Plugin URI:        https://github.com/shakewellagency/shakewell-headless-hardening
 * Description:       Switchable hardening modules for headless sites: locks down REST, ACF secrets, XML-RPC, the legacy Gravity Forms API, staff addresses in form definitions and indexing of chosen hostnames.
 * Version:           0.2.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-headless-hardening
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.2.0';
const FILE    = __FILE__;

foreach ( array( 'Guard', 'AcfLockdown', 'RestBoundaries', 'XmlRpcOff', 'GravityFormsApiV1Off', 'GravityFormsAddresses', 'NoindexHosts', 'Plugin' ) as $shakewell_headless_hardening_class ) {
	require_once __DIR__ . '/src/' . $shakewell_headless_hardening_class . '.php';
}

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
