<?php
/**
 * REST boundaries module.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening\Tests;

use Shakewell\HeadlessHardening\RestBoundaries;

final class RestBoundariesTest extends BaseCase {

	private function guard_example_namespace(): void {
		add_filter(
			'shakewell_headless_hardening_guarded_namespaces',
			static function () {
				return array( '/example/v1/admin' );
			}
		);
	}

	public function test_users_collection_is_refused_to_anonymous(): void {
		$this->assertSame( 401, $this->get( '/wp/v2/users' )->get_status() );
	}

	public function test_a_single_user_is_refused_to_anonymous(): void {
		$id = self::factory()->user->create();
		$this->assertSame( 401, $this->get( '/wp/v2/users/' . $id )->get_status() );
	}

	public function test_users_are_refused_to_a_subscriber_and_open_to_an_editor(): void {
		$this->assertSame( 403, $this->get( '/wp/v2/users', 'subscriber' )->get_status() );
		$this->assertSame( 200, $this->get( '/wp/v2/users', 'editor' )->get_status() );
	}

	public function test_coauthors_route_is_refused_to_anonymous(): void {
		$this->assertSame( 401, $this->get( '/wp/v2/coauthors' )->get_status() );
	}

	public function test_a_route_that_only_starts_like_a_guarded_base_is_left_alone(): void {
		$this->assertFalse( RestBoundaries::is_guarded( '/wp/v2/users-online' ) );
		$this->assertTrue( RestBoundaries::is_guarded( '/wp/v3/users/4' ) );
	}

	public function test_guarded_bases_come_from_the_filter(): void {
		add_filter(
			'shakewell_headless_hardening_guarded_wp_bases',
			static function () {
				return array( 'leaky' );
			}
		);
		$this->assertSame( 401, $this->get( '/wp/v2/leaky' )->get_status() );
		$this->assertSame( 200, $this->get( '/wp/v2/coauthors' )->get_status() );
	}

	public function test_a_configured_namespace_is_refused_to_anonymous_and_open_to_staff(): void {
		$this->guard_example_namespace();
		$this->assertSame( 401, $this->get( '/example/v1/admin/list' )->get_status() );
		$this->assertSame( 200, $this->get( '/example/v1/open/list' )->get_status() );
		$this->assertSame( 200, $this->get( '/example/v1/admin/list', 'editor' )->get_status() );
	}

	public function test_a_filter_that_returns_null_cannot_reopen_a_guarded_route(): void {
		$this->guard_example_namespace();
		add_filter( 'rest_pre_dispatch', '__return_null', PHP_INT_MAX, 3 );
		$this->assertSame( 401, $this->get( '/example/v1/admin/list' )->get_status() );
	}

	public function test_the_public_route_allow_list_wins(): void {
		$this->guard_example_namespace();
		add_filter(
			'shakewell_headless_hardening_public_routes',
			static function () {
				return array( '/example/v1/admin/list' );
			}
		);
		$this->assertSame( 200, $this->get( '/example/v1/admin/list' )->get_status() );
	}

	public function test_the_staff_capability_is_configurable(): void {
		add_filter(
			'shakewell_headless_hardening_staff_capability',
			static function () {
				return 'manage_options';
			}
		);
		$this->assertSame( 403, $this->get( '/wp/v2/users', 'editor' )->get_status() );
		$this->assertSame( 200, $this->get( '/wp/v2/users', 'administrator' )->get_status() );
	}

	public function test_author_archive_is_404_for_anonymous_and_normal_for_staff(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $author ) );

		$this->go_to( get_author_posts_url( $author ) );
		do_action( 'template_redirect' );
		$this->assertTrue( is_404() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->go_to( get_author_posts_url( $author ) );
		do_action( 'template_redirect' );
		$this->assertTrue( is_author() );
		$this->assertFalse( is_404() );
	}

	public function test_the_users_sitemap_provider_is_dropped(): void {
		$this->assertFalse( RestBoundaries::drop_users_sitemap( new \stdClass(), 'users' ) );
		$this->assertInstanceOf( \stdClass::class, RestBoundaries::drop_users_sitemap( new \stdClass(), 'posts' ) );
		$this->assertNotFalse( has_filter( 'wp_sitemaps_add_provider', array( RestBoundaries::class, 'drop_users_sitemap' ) ) );
	}

	public function test_oembed_loses_the_author_link_and_name(): void {
		$data = RestBoundaries::strip_oembed_author(
			array(
				'title'       => 'x',
				'author_url'  => 'https://example.test/author/jo/',
				'author_name' => 'Jo',
			)
		);
		$this->assertSame( array( 'title' => 'x' ), $data );
		$this->assertNotFalse( has_filter( 'oembed_response_data', array( RestBoundaries::class, 'strip_oembed_author' ) ) );
	}
}
