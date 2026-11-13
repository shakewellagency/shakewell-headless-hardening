<?php
/**
 * Shared helpers for the integration tests.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening\Tests;

use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

/**
 * Registers example routes and sends requests as a chosen role.
 */
abstract class BaseCase extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		global $wp_rest_server;
		$wp_rest_server = null;
		wp_set_current_user( 0 );
		unset( $GLOBALS['wp_rest_additional_fields'] );
	}

	public function tear_down(): void {
		global $wp_rest_server;
		$wp_rest_server = null;
		parent::tear_down();
	}

	/**
	 * Example routes that, like a careless plugin, have no permission callback.
	 */
	public function register_routes(): void {
		$routes = array(
			'acf/v3'     => array( '/options/options' ),
			'example/v1' => array( '/admin/list', '/open/list' ),
			'wp/v2'      => array( '/coauthors', '/leaky' ),
		);
		foreach ( $routes as $namespace => $paths ) {
			foreach ( $paths as $path ) {
				register_rest_route(
					$namespace,
					$path,
					array(
						'methods'             => 'GET',
						'permission_callback' => '__return_true',
						'callback'            => static function () {
							return array(
								'ok'  => true,
								'acf' => array(
									'title'        => 'kept',
									'api_key'      => 'secret-1',
									'custom_token' => 'secret-2',
									'nested'       => array( 'db_password' => 'secret-3' ),
								),
							);
						},
					)
				);
			}
		}
	}

	/**
	 * Sends a GET and returns the response.
	 *
	 * @param string $route Route.
	 * @param string $role  Role to act as, or empty for anonymous.
	 */
	protected function get( string $route, string $role = '' ): WP_REST_Response {
		wp_set_current_user( $role ? self::factory()->user->create( array( 'role' => $role ) ) : 0 );
		$server  = rest_get_server();
		$request = new WP_REST_Request( 'GET', $route );
		// dispatch() skips rest_post_dispatch, which serve_request() applies, so apply it here too.
		return apply_filters( 'rest_post_dispatch', rest_ensure_response( $server->dispatch( $request ) ), $server, $request );
	}
}
