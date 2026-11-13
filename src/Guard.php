<?php
/**
 * Helpers shared by the REST modules.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Route matching, the staff check and the refusal.
 */
final class Guard {

	/**
	 * The route of a REST request, or an empty string.
	 *
	 * @param mixed $request A WP_REST_Request, or anything.
	 */
	public static function route( $request ): string {
		return is_object( $request ) && method_exists( $request, 'get_route' ) ? (string) $request->get_route() : '';
	}

	/**
	 * Whether the route starts with a prefix, on a path-segment boundary, ignoring case.
	 *
	 * @param string $route  Request route.
	 * @param string $prefix Route prefix such as /vendor/v1.
	 */
	public static function under( string $route, string $prefix ): bool {
		$prefix = '/' . trim( $prefix, '/' );
		return 0 === strcasecmp( $route, $prefix ) || 0 === stripos( $route, $prefix . '/' );
	}

	/**
	 * Whether the site has put the route on its public allow-list.
	 *
	 * @param string $route Request route.
	 */
	public static function is_public( string $route ): bool {
		/**
		 * Filters the route prefixes that no guard in this plugin applies to.
		 *
		 * @param string[] $prefixes Route prefixes, for example /example/v1/public. Empty by default.
		 */
		foreach ( (array) apply_filters( 'shakewell_headless_hardening_public_routes', array() ) as $prefix ) {
			if ( is_string( $prefix ) && '' !== trim( $prefix, '/' ) && self::under( $route, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the caller is logged in with the staff capability.
	 */
	public static function is_staff(): bool {
		/**
		 * Filters the capability that opens a guarded route.
		 *
		 * @param string $capability Defaults to edit_posts.
		 */
		$capability = (string) apply_filters( 'shakewell_headless_hardening_staff_capability', 'edit_posts' );
		return is_user_logged_in() && current_user_can( $capability );
	}

	/**
	 * The error a refused caller receives.
	 */
	public static function refusal(): WP_Error {
		return new WP_Error(
			'rest_forbidden',
			__( 'Authentication is required for this endpoint.', 'shakewell-headless-hardening' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Refuses matching routes to non-staff, twice.
	 *
	 * A WP_Error returned from rest_pre_dispatch only short-circuits the request if it is still
	 * the value when the filter chain ends, and a third-party callback that ignores its argument
	 * returns null and discards it. So the check runs last on rest_pre_dispatch, and again just
	 * before the route's callback in rest_request_before_callbacks. One mis-ordered plugin cannot
	 * reopen the route.
	 *
	 * @param callable $is_guarded Receives the route string, returns true when it must be refused.
	 */
	public static function refuse_non_staff( callable $is_guarded ): void {
		$check = static function ( $value, $second, $request ) use ( $is_guarded ) {
			$route = self::route( $request );
			if ( '' === $route || self::is_public( $route ) || ! $is_guarded( $route ) ) {
				return $value;
			}
			return self::is_staff() ? $value : self::refusal();
		};
		add_filter( 'rest_pre_dispatch', $check, PHP_INT_MAX - 1, 3 );
		add_filter( 'rest_request_before_callbacks', $check, PHP_INT_MAX - 1, 3 );
	}
}
