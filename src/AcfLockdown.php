<?php
/**
 * Keeps ACF secrets out of anonymous REST responses.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

use WP_REST_Response;

defined( 'ABSPATH' ) || exit;

/**
 * Three guards.
 *
 * 1. The acf/* REST namespace is refused to anyone who is not staff. Plugins that expose ACF
 *    options over REST have shipped without a permission callback, which put whole options
 *    records, API keys included, within reach of an anonymous caller.
 * 2. Credential-shaped fields (including inside objects) are blanked in anonymous wp/v2 and acf/* responses. The key stays
 *    and its value becomes an empty string, so a consumer that indexes it gets no PHP notice.
 *    A nested array deeper than the walk limit is emptied, never passed through.
 * 3. Optionally, a named list of ACF fields is the only ACF data on core responses.
 *
 * Filters:
 * - shakewell_headless_hardening_secret_fields: extra exact field names to withhold.
 * - shakewell_headless_hardening_acf_rest_post_types and shakewell_headless_hardening_acf_rest_fields:
 *   register `acf` on those post types with only the listed fields. A secret-shaped name is never
 *   registered. Both empty by default, so nothing is exposed.
 * - shakewell_headless_hardening_redact_route_prefixes: routes whose responses are redacted.
 *   Defaults to /wp/v2 and /acf.
 * - shakewell_headless_hardening_unredacted_capability: who sees fields unredacted. Default edit_pages.
 * - shakewell_headless_hardening_public_routes: routes no guard applies to.
 */
final class AcfLockdown {

	private const PATTERN   = '/(passcode|password|passwd|secret|api[_-]?key|api[_-]?token|access[_-]?token|auth[_-]?token|refresh[_-]?token|bearer[_-]?token|private[_-]?key|client[_-]?secret|credentials?)/i';
	private const MAX_DEPTH = 12;

	/**
	 * Registers the guards.
	 */
	public static function boot(): void {
		Guard::refuse_non_staff(
			static function ( string $route ): bool {
				return Guard::under( $route, '/acf' );
			}
		);
		add_filter( 'rest_post_dispatch', array( self::class, 'redact_response' ), 100, 3 );
		add_action( 'rest_api_init', array( self::class, 'register_public_fields' ), PHP_INT_MAX );
	}

	/**
	 * Whether a field name must be withheld from an anonymous caller.
	 *
	 * @param mixed $name Field name or array key.
	 */
	public static function is_secret( $name ): bool {
		if ( ! is_string( $name ) || '' === $name ) {
			return false;
		}
		/**
		 * Filters the exact field names withheld from anonymous callers, on top of the built-in pattern.
		 *
		 * @param string[] $names Empty by default.
		 */
		if ( in_array( $name, (array) apply_filters( 'shakewell_headless_hardening_secret_fields', array() ), true ) ) {
			return true;
		}
		return 1 === preg_match( self::PATTERN, $name );
	}

	/**
	 * Recursively blanks secret keys.
	 *
	 * @param mixed $value Response data.
	 * @param int   $depth Current depth.
	 * @return mixed
	 */
	public static function redact( $value, int $depth = 0 ) {
		if ( is_object( $value ) ) {
			// Objects (stdClass, JsonSerializable) are walked as the arrays they serialise to.
			$value = json_decode( (string) wp_json_encode( $value ), true );
		}
		if ( ! is_array( $value ) ) {
			return $value;
		}
		if ( $depth > self::MAX_DEPTH ) {
			return array();
		}
		foreach ( $value as $key => $item ) {
			if ( self::is_secret( $key ) ) {
				$value[ $key ] = '';
			} elseif ( is_array( $item ) || is_object( $item ) ) {
				$value[ $key ] = self::redact( $item, $depth + 1 );
			}
		}
		return $value;
	}

	/**
	 * Redacts an anonymous or contributor-level response on the configured routes.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   REST server.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function redact_response( $response, $server, $request ) {
		/**
		 * Filters the capability that sees ACF fields unredacted. Route access uses
		 * shakewell_headless_hardening_staff_capability instead, so by default an author or
		 * contributor can open acf routes but still gets redacted data.
		 *
		 * @param string $capability Defaults to edit_pages.
		 */
		$unredacted = (string) apply_filters( 'shakewell_headless_hardening_unredacted_capability', 'edit_pages' );
		if ( current_user_can( $unredacted ) || ! $response instanceof WP_REST_Response ) {
			return $response;
		}
		$route = Guard::route( $request );
		/**
		 * Filters the route prefixes whose responses are redacted for non-editors.
		 *
		 * @param string[] $prefixes Defaults to the core wp/v2 routes and the acf namespace.
		 */
		$prefixes = (array) apply_filters( 'shakewell_headless_hardening_redact_route_prefixes', array( '/wp/v2', '/acf' ) );
		$matched  = false;
		foreach ( $prefixes as $prefix ) {
			if ( is_string( $prefix ) && Guard::under( $route, $prefix ) ) {
				$matched = true;
				break;
			}
		}
		$data = $response->get_data();
		if ( $matched && is_array( $data ) ) {
			$response->set_data( self::redact( $data ) );
		}
		return $response;
	}

	/**
	 * Registers `acf` on the configured post types with the allow-listed fields and nothing else.
	 *
	 * ACF registers its own empty `acf` field at the default priority; this runs last and replaces it.
	 */
	public static function register_public_fields(): void {
		/**
		 * Filters the post types that expose allow-listed ACF fields on their REST responses.
		 *
		 * @param string[] $types Empty by default.
		 */
		$types = array_filter( (array) apply_filters( 'shakewell_headless_hardening_acf_rest_post_types', array() ), 'is_string' );
		/**
		 * Filters the ACF field names exposed on those post types.
		 *
		 * @param string[] $fields Empty by default.
		 */
		$fields = array_values(
			array_filter(
				(array) apply_filters( 'shakewell_headless_hardening_acf_rest_fields', array() ),
				static function ( $name ): bool {
					return is_string( $name ) && '' !== $name && ! self::is_secret( $name );
				}
			)
		);
		if ( ! $types || ! $fields ) {
			return;
		}
		register_rest_field(
			array_values( $types ),
			'acf',
			array(
				'get_callback' => static function ( $item ) use ( $fields ) {
					// Core blanks content for a password-protected post; extra fields must not leak either.
					if ( post_password_required( $item['id'] ) ) {
						return array_fill_keys( $fields, null );
					}
					$out = array();
					foreach ( $fields as $name ) {
						$out[ $name ] = function_exists( 'get_field' ) ? get_field( $name, $item['id'] ) : null;
					}
					return $out;
				},
				'schema'       => array(
					'description' => 'Allow-listed ACF fields.',
					'type'        => 'object',
					'context'     => array( 'view', 'embed', 'edit' ),
					'readonly'    => true,
				),
			)
		);
	}
}
