<?php
/**
 * Turns the XML-RPC server off.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Two layers. A request core marks as XML-RPC (XMLRPC_REQUEST, defined by xmlrpc.php) is ended
 * with a 403 before core builds the server, because the xmlrpc_enabled filter only gates login
 * and pingbacks and system.multicall would still answer. The filters then empty the method list
 * for anything that gets past that. Block the file at the web server too; this is the layer that
 * holds if that rule is lost.
 */
final class XmlRpcOff {

	/**
	 * Ends an XML-RPC request and registers the filters.
	 */
	public static function boot(): void {
		if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
			http_response_code( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'XML-RPC is disabled.';
			exit;
		}
		add_filter( 'xmlrpc_enabled', '__return_false', PHP_INT_MAX );
		add_filter( 'xmlrpc_methods', '__return_empty_array', PHP_INT_MAX );
	}
}
