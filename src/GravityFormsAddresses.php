<?php
/**
 * Keeps notification settings out of Gravity Forms REST form definitions.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

use JsonSerializable;
use WP_HTTP_Response;

defined( 'ABSPATH' ) || exit;

/**
 * A headless front end often fetches each form definition from gf/v2/forms/<id> and hands the JSON to
 * the browser, so everything in the definition is public. The notification settings in it carry the
 * addresses an enquiry is routed to, which nobody outside WordPress needs: notifications are sent
 * server-side on submission.
 *
 * This removes the notifications block from the two routes that return a form definition, whoever
 * asks and whichever method they use: /gf/vN/forms (the collection, whole forms with ?include=) and
 * /gf/vN/forms/<id>. Confirmations, fields, choices and everything else are left as Gravity Forms
 * returns them, so rendering and validation are unchanged.
 *
 * Only the REST response is edited, never the form itself, so notification delivery is untouched. A GET
 * followed by a PUT of a stripped definition does not wipe stored notifications either: Gravity Forms
 * writes them only when the key is present.
 *
 * Route matching is case-insensitive because WordPress is, and anchored at both ends so sub-routes such
 * as forms/<id>/entries and unrelated routes such as forms-extra are not touched.
 *
 * Off by default: it changes what a Gravity Forms feature returns, so a site switches it on once it
 * knows nothing reads notifications over REST.
 */
final class GravityFormsAddresses {

	/**
	 * Registers the response filters, last in their chains.
	 *
	 * The filter rest_request_after_callbacks runs for every dispatch, including internal ones through
	 * rest_do_request(). The filter rest_post_dispatch is a second layer for requests served over HTTP.
	 */
	public static function boot(): void {
		add_filter( 'rest_request_after_callbacks', array( self::class, 'strip_response' ), PHP_INT_MAX - 1, 3 );
		add_filter( 'rest_post_dispatch', array( self::class, 'strip_response' ), PHP_INT_MAX - 1, 3 );
	}

	/**
	 * Whether a REST route returns a form definition.
	 *
	 * @param mixed $route Request route.
	 */
	public static function is_definition_route( $route ): bool {
		return is_string( $route ) && 1 === preg_match( '#^/gf/v\d+/forms(?:/\d+)?/?$#i', $route );
	}

	/**
	 * Removes notifications from the response of a form-definition route.
	 *
	 * @param mixed $response Response.
	 * @param mixed $server   REST server, or the matched handler when called from rest_request_after_callbacks.
	 * @param mixed $request  Request.
	 * @return mixed
	 */
	public static function strip_response( $response, $server, $request ) {
		if ( ! self::is_definition_route( Guard::route( $request ) ) ) {
			return $response;
		}
		if ( $response instanceof WP_HTTP_Response ) {
			$response->set_data( self::strip( $response->get_data() ) );
			return $response;
		}
		// rest_request_after_callbacks hands over whatever the route callback returned, often a plain array.
		return $response instanceof \WP_Error ? $response : self::strip( $response );
	}

	/**
	 * Payload with notifications removed from the form it is, or from each form it holds.
	 *
	 * A single form has the key at the top level. The collection keys whole forms by id, and a
	 * force-delete wraps the form as `previous`, so the direct children are cleaned as well. Anything
	 * else is returned untouched.
	 *
	 * @param mixed $data Response data.
	 * @return mixed
	 */
	public static function strip( $data ) {
		if ( $data instanceof JsonSerializable ) {
			$data = $data->jsonSerialize();
		}
		if ( ! is_array( $data ) && ! is_object( $data ) ) {
			return $data;
		}
		$data = self::drop( $data );
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $child ) {
				$data[ $key ] = self::drop( $child );
			}
		} else {
			$data = clone $data;
			foreach ( get_object_vars( $data ) as $key => $child ) {
				$data->$key = self::drop( $child );
			}
		}
		return $data;
	}

	/**
	 * One node with its notifications key removed. An object is cloned first so a form shared with
	 * Gravity Forms' own cache is never changed.
	 *
	 * @param mixed $node Array, object or scalar.
	 * @return mixed
	 */
	private static function drop( $node ) {
		if ( is_array( $node ) ) {
			unset( $node['notifications'] );
		} elseif ( is_object( $node ) && property_exists( $node, 'notifications' ) ) {
			$node = clone $node;
			unset( $node->notifications );
		}
		return $node;
	}
}
