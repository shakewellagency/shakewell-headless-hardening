<?php
/**
 * Module switches, the XML-RPC module and the Gravity Forms module.
 *
 * @package Shakewell\HeadlessHardening
 */

namespace Shakewell\HeadlessHardening\Tests;

use Shakewell\HeadlessHardening\GravityFormsApiV1Off;
use Shakewell\HeadlessHardening\Plugin;
use WP_UnitTestCase;

final class PluginTest extends WP_UnitTestCase {

	public function test_plugin_boots_on_plugins_loaded(): void {
		$this->assertNotFalse( has_action( 'plugins_loaded', array( Plugin::class, 'boot' ) ) );
	}

	public function test_default_states(): void {
		$this->assertTrue( Plugin::enabled( 'acf_lockdown' ) );
		$this->assertTrue( Plugin::enabled( 'rest_boundaries' ) );
		$this->assertTrue( Plugin::enabled( 'xmlrpc_off' ) );
		$this->assertFalse( Plugin::enabled( 'gravity_forms_api_v1_off' ) );
		$this->assertFalse( Plugin::enabled( 'no_such_module' ) );
	}

	public function test_the_filter_switches_a_module(): void {
		add_filter(
			'shakewell_headless_hardening_module_enabled',
			static function ( $enabled, $module ) {
				return 'xmlrpc_off' === $module ? false : $enabled;
			},
			10,
			2
		);
		$this->assertFalse( Plugin::enabled( 'xmlrpc_off' ) );
		$this->assertTrue( Plugin::enabled( 'acf_lockdown' ) );
	}

	public function test_a_constant_switches_a_module(): void {
		// Constants cannot be undefined, so use module keys no real module has.
		define( 'SHAKEWELL_HEADLESS_HARDENING_TEST_ON', true );
		define( 'SHAKEWELL_HEADLESS_HARDENING_TEST_OFF', false );
		$this->assertTrue( Plugin::enabled( 'test_on' ) );
		$this->assertFalse( Plugin::enabled( 'test_off' ) );
	}

	public function test_xmlrpc_filters_are_on(): void {
		$this->assertFalse( apply_filters( 'xmlrpc_enabled', true ) );
		$this->assertSame( array(), apply_filters( 'xmlrpc_methods', array( 'system.multicall' => 'x' ) ) );
	}

	/**
	 * Runs the XML-RPC module in a separate PHP process, with core stubbed out.
	 *
	 * @param string $prefix PHP to run before the module boots.
	 */
	private function run_xmlrpc_module( string $prefix ): string {
		$file = dirname( __DIR__, 2 ) . '/src/XmlRpcOff.php';
		$code = 'namespace Shakewell\HeadlessHardening { define("ABSPATH", "/"); function add_filter() {} ' . $prefix
			. ' require ' . var_export( $file, true ) . '; XmlRpcOff::boot(); echo "CONTINUED"; }';
		return (string) shell_exec( escapeshellarg( PHP_BINARY ) . ' -d display_errors=0 -r ' . escapeshellarg( $code ) . ' 2>/dev/null' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_shell_exec
	}

	public function test_an_xmlrpc_request_is_ended_with_a_refusal(): void {
		$out = $this->run_xmlrpc_module( 'define("XMLRPC_REQUEST", true);' );
		$this->assertStringContainsString( 'XML-RPC is disabled.', $out );
		$this->assertStringNotContainsString( 'CONTINUED', $out );
	}

	public function test_any_other_request_carries_on(): void {
		$this->assertStringContainsString( 'CONTINUED', $this->run_xmlrpc_module( '' ) );
	}

	public function test_legacy_gravity_forms_paths_are_matched(): void {
		$this->assertTrue( GravityFormsApiV1Off::is_legacy_path( '/gravityformsapi/forms/1' ) );
		$this->assertTrue( GravityFormsApiV1Off::is_legacy_path( '/GravityFormsAPI' ) );
		$this->assertFalse( GravityFormsApiV1Off::is_legacy_path( '/gravityformsapi-docs' ) );
		$this->assertFalse( GravityFormsApiV1Off::is_legacy_path( '/wp-json/gf/v2/forms' ) );
	}

	public function test_gravity_forms_module_turns_v1_off_and_answers_404(): void {
		GravityFormsApiV1Off::boot();
		$this->assertFalse( apply_filters( 'gform_is_rest_api_v1_enabled', true ) );

		add_filter( 'wp_doing_ajax', '__return_true' );
		add_filter(
			'wp_die_ajax_handler',
			static function () {
				return static function () {
					throw new \RuntimeException( 'died' );
				};
			}
		);
		$_SERVER['REQUEST_URI'] = '/gravityformsapi/forms/1';
		$status                 = null;
		add_filter(
			'status_header',
			static function ( $header, $code ) use ( &$status ) {
				$status = $code;
				return $header;
			},
			10,
			2
		);
		ob_start();
		try {
			do_action( 'parse_request', new \WP() );
			$this->fail( 'the request should have been ended' );
		} catch ( \RuntimeException $e ) {
			$body = ob_get_clean();
		}
		$this->assertSame( '{"status":404,"response":"Not found"}', $body );

		$_SERVER['REQUEST_URI'] = '/about/';
		do_action( 'parse_request', new \WP() );
		$this->assertTrue( true );
	}
}
