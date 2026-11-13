<?php
/**
 * Turns off the legacy Gravity Forms Web API (v1).
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

/**
 * The v1 API authenticates with one site-wide public and private key pair and returns whole form
 * definitions, notification addresses included. Gravity Forms' own switch turns v1 and v2 off
 * together, so this uses the filter it provides to turn off v1 alone and leaves v2 running.
 *
 * With v1 off Gravity Forms stops claiming /gravityformsapi/, so WordPress would resolve the path
 * like any other page. This answers it with a plain 404 in the v1 envelope instead.
 *
 * Off by default: it changes a Gravity Forms feature, so a site switches it on once it knows
 * nothing calls v1.
 */
final class GravityFormsApiV1Off {

	/**
	 * Registers the filter and the 404.
	 */
	public static function boot(): void {
		add_filter( 'gform_is_rest_api_v1_enabled', '__return_false' );
		add_action( 'parse_request', array( self::class, 'answer_legacy_path' ), 0 );
	}

	/**
	 * Whether a URL path is under /gravityformsapi/.
	 *
	 * @param string $path URL path.
	 */
	public static function is_legacy_path( string $path ): bool {
		return 1 === preg_match( '#^/gravityformsapi(/|$)#i', $path );
	}

	/**
	 * Sends the 404 for a legacy API request.
	 */
	public static function answer_legacy_path(): void {
		// Read-only: the value is only matched against a fixed pattern, never stored or output.
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = rawurldecode( (string) wp_parse_url( $uri, PHP_URL_PATH ) );
		// A site installed in a subdirectory sees the install path in the request URI.
		$home = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home && 0 === stripos( $path, $home . '/' ) ) {
			$path = substr( $path, strlen( $home ) );
		}
		if ( self::is_legacy_path( $path ) ) {
			wp_send_json(
				array(
					'status'   => 404,
					'response' => 'Not found',
				),
				404
			);
		}
	}
}
