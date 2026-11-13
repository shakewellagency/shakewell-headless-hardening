<?php
/**
 * The noindex hosts module and the Gravity Forms addresses module.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening\Tests;

use Shakewell\HeadlessHardening\GravityFormsAddresses;
use Shakewell\HeadlessHardening\NoindexHosts;
use Shakewell\HeadlessHardening\Plugin;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

final class NoindexAndFormsTest extends WP_UnitTestCase {

	public function set_up(): void {
		parent::set_up();
		NoindexHosts::boot();
		GravityFormsAddresses::boot();
	}

	public function tear_down(): void {
		unset( $_SERVER['HTTP_HOST'] );
		parent::tear_down();
	}

	private function hosts( array $hosts ): void {
		add_filter( 'shakewell_headless_hardening_noindex_hosts', static fn() => $hosts );
	}

	public function test_module_defaults(): void {
		$this->assertTrue( Plugin::enabled( 'noindex_hosts' ) );
		$this->assertFalse( Plugin::enabled( 'gravity_forms_addresses' ) );
	}

	public function test_no_hosts_means_nothing_changes(): void {
		$_SERVER['HTTP_HOST'] = 'check.example.test';
		$this->assertSame( array(), NoindexHosts::hosts() );
		$this->assertArrayNotHasKey( 'X-Robots-Tag', apply_filters( 'wp_headers', array() ) );
	}

	public function test_host_matching(): void {
		$this->assertTrue( NoindexHosts::matches( 'Check.Example.test:8443', array( 'check.example.test' ) ) );
		$this->assertTrue( NoindexHosts::matches( 'a.b.example.test', array( '*.example.test' ) ) );
		$this->assertFalse( NoindexHosts::matches( 'example.test', array( '*.example.test' ) ) );
		$this->assertFalse( NoindexHosts::matches( 'notexample.test', array( '*.example.test' ) ) );
		$this->assertFalse( NoindexHosts::matches( 'live.test', array( 'check.example.test' ) ) );
		$this->assertFalse( NoindexHosts::matches( '', array( 'check.example.test' ) ) );
	}

	public function test_listed_host_gets_every_signal(): void {
		$this->hosts( array( 'check.example.test' ) );
		$_SERVER['HTTP_HOST'] = 'check.example.test';

		$this->assertSame( 'noindex, nofollow', apply_filters( 'wp_headers', array() )['X-Robots-Tag'] );
		ob_start();
		do_action( 'wp_head' );
		$this->assertStringContainsString( 'content="noindex, nofollow"', (string) ob_get_clean() );
		$this->assertSame( "User-agent: *\nDisallow: /\n", apply_filters( 'robots_txt', 'x', true ) );
		$this->assertSame( 0, (int) get_option( 'blog_public' ) );
	}

	public function test_other_hosts_are_untouched(): void {
		$this->hosts( array( 'check.example.test' ) );
		$_SERVER['HTTP_HOST'] = 'www.example.test';

		$this->assertArrayNotHasKey( 'X-Robots-Tag', apply_filters( 'wp_headers', array() ) );
		$this->assertStringNotContainsString( 'Disallow: /' . "\n", apply_filters( 'robots_txt', 'x', true ) );
		$this->assertSame( 1, (int) get_option( 'blog_public' ) );
	}

	public function test_form_routes_are_matched(): void {
		foreach ( array( '/gf/v2/forms', '/gf/v2/forms/', '/gf/v2/forms/12', '/GF/V2/FORMS/12' ) as $route ) {
			$this->assertTrue( GravityFormsAddresses::is_definition_route( $route ), $route );
		}
		foreach ( array( '/gf/v2/forms-extra', '/gf/v2/forms/12/entries', '/gf/v2/entries', '/wp/v2/forms' ) as $route ) {
			$this->assertFalse( GravityFormsAddresses::is_definition_route( $route ), $route );
		}
	}

	private function dispatch( string $route, $data ) {
		return apply_filters( 'rest_post_dispatch', new WP_REST_Response( $data ), rest_get_server(), new WP_REST_Request( 'GET', $route ) )->get_data();
	}

	public function test_a_single_form_loses_only_its_notifications(): void {
		$form = array(
			'id'            => 3,
			'fields'        => array( array( 'id' => 1, 'notifications' => 'kept: not a form key' ) ),
			'confirmations' => array( 'c1' => array( 'message' => 'Thanks' ) ),
			'notifications' => array( 'n1' => array( 'to' => 'staff@example.test' ) ),
		);
		$out  = $this->dispatch( '/gf/v2/forms/3', $form );
		$this->assertArrayNotHasKey( 'notifications', $out );
		$this->assertSame( $form['fields'], $out['fields'] );
		$this->assertSame( $form['confirmations'], $out['confirmations'] );
	}

	public function test_a_collection_and_a_delete_echo_are_cleaned_and_objects_are_not_mutated(): void {
		$shared = (object) array( 'id' => 2, 'notifications' => array( 'to' => 'staff@example.test' ), 'title' => 'T' );
		$out    = $this->dispatch( '/gf/v2/forms', array( 1 => array( 'id' => 1, 'notifications' => array( 'x' ) ), 2 => $shared ) );
		$this->assertArrayNotHasKey( 'notifications', $out[1] );
		$this->assertFalse( property_exists( $out[2], 'notifications' ) );
		$this->assertSame( 'T', $out[2]->title );
		$this->assertTrue( property_exists( $shared, 'notifications' ), 'the original object is left alone' );

		$out = $this->dispatch( '/gf/v2/forms/3', array( 'deleted' => true, 'previous' => array( 'id' => 3, 'notifications' => array( 'x' ) ) ) );
		$this->assertArrayNotHasKey( 'notifications', $out['previous'] );
	}

	public function test_other_routes_are_untouched(): void {
		$data = array( 'notifications' => array( 'keep' ) );
		$this->assertSame( $data, $this->dispatch( '/gf/v2/forms/3/entries', $data ) );
		$this->assertSame( $data, $this->dispatch( '/wp/v2/posts', $data ) );
	}
}
