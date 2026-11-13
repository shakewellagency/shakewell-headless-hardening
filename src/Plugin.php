<?php
/**
 * Plugin bootstrap.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Starts each hardening module that is switched on.
 *
 * A module is switched with the constant SHAKEWELL_HEADLESS_HARDENING_<MODULE> in wp-config.php
 * (true or false), or with the filter shakewell_headless_hardening_module_enabled. With neither,
 * the default below applies.
 */
final class Plugin {

	/**
	 * Module key => array( default state, class ). The key upper-cased is the constant suffix.
	 *
	 * @var array<string, array{0: bool, 1: class-string}>
	 */
	private const MODULES = array(
		'acf_lockdown'             => array( true, AcfLockdown::class ),
		'rest_boundaries'          => array( true, RestBoundaries::class ),
		'xmlrpc_off'               => array( true, XmlRpcOff::class ),
		'gravity_forms_api_v1_off' => array( false, GravityFormsApiV1Off::class ),
	);

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		foreach ( array_keys( self::MODULES ) as $module ) {
			if ( self::enabled( $module ) ) {
				( self::MODULES[ $module ][1] )::boot();
			}
		}
	}

	/**
	 * Whether a module is switched on. An unknown module is off.
	 *
	 * @param string $module Module key, for example xmlrpc_off.
	 */
	public static function enabled( string $module ): bool {
		$default  = self::MODULES[ $module ][0] ?? false;
		$constant = 'SHAKEWELL_HEADLESS_HARDENING_' . strtoupper( $module );
		$enabled  = defined( $constant ) ? (bool) constant( $constant ) : $default;

		/**
		 * Filters whether a hardening module is switched on.
		 *
		 * @param bool   $enabled Current state.
		 * @param string $module  Module key.
		 */
		return (bool) apply_filters( 'shakewell_headless_hardening_module_enabled', $enabled, $module );
	}
}
