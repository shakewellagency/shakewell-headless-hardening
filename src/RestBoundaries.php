<?php
/**
 * Stops anonymous enumeration of users and refuses configured namespaces.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening;

defined( 'ABSPATH' ) || exit;

/**
 * Closes every path to the same data together.
 *
 * - /wp/vN/users and /wp/vN/coauthors, and any other base listed in the filter, answer staff only.
 *   The default users route hands out slugs, which on many sites derive from email addresses.
 * - Author archives answer 404 to non-staff, the core users sitemap is dropped and oEmbed drops author_url and author_name, because
 *   they confirm the same slugs by another route. Gating one path and leaving the others would
 *   move the exposure, not remove it.
 * - Namespaces listed in the filter answer staff only, including routes added later by someone who
 *   forgets a permission callback.
 *
 * Filters:
 * - shakewell_headless_hardening_guarded_namespaces: route prefixes such as /example/v1. Empty by default.
 * - shakewell_headless_hardening_guarded_wp_bases: bases under /wp/vN. Defaults to users and coauthors.
 * - shakewell_headless_hardening_public_routes and shakewell_headless_hardening_staff_capability, see Guard.
 */
final class RestBoundaries {

	/**
	 * Registers the guards.
	 */
	public static function boot(): void {
		Guard::refuse_non_staff( array( self::class, 'is_guarded' ) );
		add_action( 'template_redirect', array( self::class, 'hide_author_archive' ), 1 );
		add_filter( 'wp_sitemaps_add_provider', array( self::class, 'drop_users_sitemap' ), 10, 2 );
		add_filter( 'oembed_response_data', array( self::class, 'strip_oembed_author' ), PHP_INT_MAX - 1 );
	}

	/**
	 * Whether a route is one a non-staff caller must not reach.
	 *
	 * @param string $route Request route.
	 */
	public static function is_guarded( string $route ): bool {
		/**
		 * Filters the namespaces that answer staff only.
		 *
		 * @param string[] $namespaces Route prefixes. Empty by default.
		 */
		foreach ( (array) apply_filters( 'shakewell_headless_hardening_guarded_namespaces', array() ) as $prefix ) {
			if ( is_string( $prefix ) && '' !== trim( $prefix, '/' ) && Guard::under( $route, $prefix ) ) {
				return true;
			}
		}
		/**
		 * Filters the bases under /wp/vN that answer staff only.
		 *
		 * @param string[] $bases Defaults to users and coauthors.
		 */
		foreach ( (array) apply_filters( 'shakewell_headless_hardening_guarded_wp_bases', array( 'users', 'coauthors' ) ) as $base ) {
			if ( is_string( $base ) && '' !== trim( $base, '/' ) && 1 === preg_match( '#^/wp/v\d+/' . preg_quote( trim( $base, '/' ), '#' ) . '(/|$|\?)#i', $route ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Answers 404 on an author archive to anyone who is not staff.
	 */
	public static function hide_author_archive(): void {
		if ( is_author() && ! Guard::is_staff() ) {
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	}

	/**
	 * Drops the core users sitemap, which lists every author archive URL.
	 *
	 * @param mixed  $provider Sitemap provider.
	 * @param string $name     Provider name.
	 * @return mixed
	 */
	public static function drop_users_sitemap( $provider, $name ) {
		return 'users' === $name ? false : $provider;
	}

	/**
	 * Removes the author link and name from oEmbed data.
	 *
	 * @param mixed $data oEmbed response data.
	 * @return mixed
	 */
	public static function strip_oembed_author( $data ) {
		if ( is_array( $data ) ) {
			unset( $data['author_url'], $data['author_name'] );
		}
		return $data;
	}
}
