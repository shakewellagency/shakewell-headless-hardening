<?php
/**
 * Tells search engines not to index chosen hostnames.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

/**
 * A headless site is often reachable on more than one hostname: the real one, plus names used for
 * checking the site before launch. Site-wide "discourage search engines" settings cannot tell them
 * apart, and the real hostname must stay indexable. This module sends noindex only for requests that
 * arrive on a hostname you list, and does nothing for every other host.
 *
 * Hostnames come from the constant or environment variable SHAKEWELL_HEADLESS_HARDENING_NOINDEX_HOST_LIST
 * (comma-separated) and the filter shakewell_headless_hardening_noindex_hosts. An entry is an exact
 * hostname, or *.example.test for every subdomain of example.test. With no hostnames the module does nothing.
 *
 * On a matching host it sends the X-Robots-Tag header (so REST responses and assets are covered), the
 * robots meta tag, a disallow-all robots.txt, and reports blog_public as 0.
 */
final class NoindexHosts {

	private const SOURCE = 'SHAKEWELL_HEADLESS_HARDENING_NOINDEX_HOST_LIST';

	/**
	 * Registers the hooks. They act only when the request host is listed.
	 */
	public static function boot(): void {
		add_filter( 'wp_headers', array( self::class, 'add_header' ) );
		add_action( 'wp_head', array( self::class, 'print_meta' ), 1 );
		add_filter( 'robots_txt', array( self::class, 'robots_txt' ), PHP_INT_MAX );
		add_filter( 'pre_option_blog_public', array( self::class, 'blog_public' ) );
	}

	/**
	 * The configured hostname patterns, lower-cased.
	 *
	 * @return string[]
	 */
	public static function hosts(): array {
		$raw = defined( self::SOURCE ) ? constant( self::SOURCE ) : getenv( self::SOURCE );
		$raw = is_array( $raw ) ? $raw : explode( ',', is_string( $raw ) ? $raw : '' );
		/**
		 * Filters the hostnames that get noindex.
		 *
		 * @param string[] $hosts Exact hostnames, or *.example.test for all subdomains. Empty by default.
		 */
		$hosts = (array) apply_filters( 'shakewell_headless_hardening_noindex_hosts', $raw );
		return array_values( array_filter( array_map( static fn( $h ) => is_string( $h ) ? strtolower( trim( $h ) ) : '', $hosts ) ) );
	}

	/**
	 * Whether a Host header value matches a configured pattern. The port is ignored.
	 *
	 * @param string   $host     Host header value.
	 * @param string[] $patterns Hostname patterns.
	 */
	public static function matches( string $host, array $patterns ): bool {
		$host = (string) preg_replace( '/:\d+$/', '', strtolower( trim( $host ) ) );
		if ( '' === $host ) {
			return false;
		}
		foreach ( $patterns as $pattern ) {
			if ( $pattern === $host ) {
				return true;
			}
			// *.example.test matches sub.example.test but not example.test itself.
			if ( 0 === strpos( $pattern, '*.' ) && substr( $host, -strlen( $pattern ) + 1 ) === substr( $pattern, 1 ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether this request arrived on a listed host.
	 */
	public static function is_listed_host(): bool {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- only compared with a fixed list, never stored or output.
		return self::matches( $host, self::hosts() );
	}

	/**
	 * Adds the X-Robots-Tag header.
	 *
	 * @param mixed $headers Response headers.
	 * @return mixed
	 */
	public static function add_header( $headers ) {
		if ( is_array( $headers ) && self::is_listed_host() ) {
			$headers['X-Robots-Tag'] = 'noindex, nofollow';
		}
		return $headers;
	}

	/**
	 * Prints the robots meta tag.
	 */
	public static function print_meta(): void {
		if ( self::is_listed_host() ) {
			echo '<meta name="robots" content="noindex, nofollow" />' . "\n";
		}
	}

	/**
	 * Answers robots.txt with disallow-all.
	 *
	 * @param mixed $output robots.txt body.
	 * @return mixed
	 */
	public static function robots_txt( $output ) {
		return self::is_listed_host() ? "User-agent: *\nDisallow: /\n" : $output;
	}

	/**
	 * Reports the site as not public, which drives WordPress's own indexing signals.
	 *
	 * @param mixed $pre Short-circuit value; false means no short-circuit.
	 * @return mixed
	 */
	public static function blog_public( $pre ) {
		return self::is_listed_host() ? 0 : $pre;
	}
}
