<?php
/**
 * ACF lockdown module.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening\Tests;

use Shakewell\HeadlessHardening\AcfLockdown;

final class AcfLockdownTest extends BaseCase {

	public function test_acf_namespace_is_refused_to_anonymous(): void {
		$this->assertSame( 401, $this->get( '/acf/v3/options/options' )->get_status() );
	}

	public function test_acf_namespace_is_refused_to_a_subscriber(): void {
		$this->assertSame( 403, $this->get( '/acf/v3/options/options', 'subscriber' )->get_status() );
	}

	public function test_acf_namespace_is_open_to_an_editor(): void {
		$response = $this->get( '/acf/v3/options/options', 'editor' );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'secret-1', $response->get_data()['acf']['api_key'] );
	}

	public function test_a_filter_that_returns_null_cannot_reopen_the_namespace(): void {
		// The failure mode this guards: a callback that ignores its argument and returns nothing.
		add_filter( 'rest_pre_dispatch', '__return_null', 10, 3 );
		add_filter( 'rest_pre_dispatch', '__return_null', PHP_INT_MAX, 3 );
		$this->assertSame( 401, $this->get( '/acf/v3/options/options' )->get_status() );
	}

	public function test_the_public_route_allow_list_exempts_a_route(): void {
		add_filter(
			'shakewell_headless_hardening_public_routes',
			static function () {
				return array( '/acf/v3/options' );
			}
		);
		$this->assertSame( 200, $this->get( '/acf/v3/options/options' )->get_status() );
	}

	public function test_secret_shaped_fields_are_blanked_for_anonymous_callers_at_any_depth(): void {
		$acf = $this->get( '/wp/v2/leaky' )->get_data()['acf'];
		$this->assertSame( 'kept', $acf['title'] );
		$this->assertSame( '', $acf['api_key'] );
		$this->assertSame( 'secret-2', $acf['custom_token'], 'custom_token is not matched by the built-in pattern' );
		$this->assertSame( '', $acf['nested']['db_password'] );
	}

	public function test_extra_secret_field_names_come_from_the_filter(): void {
		add_filter(
			'shakewell_headless_hardening_secret_fields',
			static function () {
				return array( 'custom_token' );
			}
		);
		$this->assertSame( '', $this->get( '/wp/v2/leaky' )->get_data()['acf']['custom_token'] );
	}

	public function test_editors_see_the_raw_fields(): void {
		$this->assertSame( 'secret-1', $this->get( '/wp/v2/leaky', 'editor' )->get_data()['acf']['api_key'] );
	}

	public function test_a_secret_nested_beyond_the_walk_limit_is_emptied_not_passed_through(): void {
		$deep = array( 'api_key' => 'secret' );
		for ( $i = 0; $i < 20; $i++ ) {
			$deep = array( 'level' => $deep );
		}
		$this->assertStringNotContainsString( 'secret', wp_json_encode( AcfLockdown::redact( $deep ) ) );
	}

	public function test_only_the_redacted_prefixes_are_walked(): void {
		add_filter(
			'shakewell_headless_hardening_redact_route_prefixes',
			static function () {
				return array( '/acf' );
			}
		);
		$this->assertSame( 'secret-1', $this->get( '/wp/v2/leaky' )->get_data()['acf']['api_key'] );
	}

	public function test_allow_listed_fields_replace_the_acf_object_on_post_types(): void {
		add_filter(
			'shakewell_headless_hardening_acf_rest_post_types',
			static function () {
				return array( 'page' );
			}
		);
		add_filter(
			'shakewell_headless_hardening_acf_rest_fields',
			static function () {
				return array( 'is_private', 'page_passcode', 'stripe_secret_key' );
			}
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		AcfLockdown::register_public_fields();
		$field = $GLOBALS['wp_rest_additional_fields']['page']['acf']['get_callback'];
		$this->assertSame( array( 'is_private' => 'value-is_private' ), $field( array( 'id' => $page ) ), 'secret-shaped names are never registered' );

		$locked = self::factory()->post->create(
			array(
				'post_type'     => 'page',
				'post_password' => 'pw',
			)
		);
		$this->assertSame( array( 'is_private' => null ), $field( array( 'id' => $locked ) ), 'a password-protected post gives nothing' );
	}

	public function test_secrets_inside_objects_are_blanked(): void {
		$data = AcfLockdown::redact( array( 'group' => (object) array( 'api_key' => 'hunter2', 'label' => 'kept', 'inner' => (object) array( 'client_secret' => 'hunter2' ) ) ) );
		$this->assertStringNotContainsString( 'hunter2', wp_json_encode( $data ) );
		$this->assertSame( 'kept', $data['group']['label'] );
	}

	public function test_common_credential_names_are_matched(): void {
		foreach ( array( 'auth_token', 'refresh_token', 'bearer_token', 'passwd', 'credentials', 'Stripe-Secret' ) as $name ) {
			$this->assertTrue( AcfLockdown::is_secret( $name ), $name );
		}
		$this->assertFalse( AcfLockdown::is_secret( 'title' ) );
	}

	public function test_the_unredacted_capability_is_configurable(): void {
		add_filter(
			'shakewell_headless_hardening_unredacted_capability',
			static function () {
				return 'manage_options';
			}
		);
		$this->assertSame( '', $this->get( '/wp/v2/leaky', 'editor' )->get_data()['acf']['api_key'] );
		$this->assertSame( 'secret-1', $this->get( '/wp/v2/leaky', 'administrator' )->get_data()['acf']['api_key'] );
	}

	public function test_nothing_is_registered_without_configuration(): void {
		AcfLockdown::register_public_fields();
		$this->assertArrayNotHasKey( 'acf', $GLOBALS['wp_rest_additional_fields']['page'] ?? array() );
	}
}
