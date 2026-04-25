<?php
/**
 * Unit tests for AbilityGuard\Support\Redactor.
 *
 * @package AbilityGuard
 */

declare( strict_types=1 );

namespace AbilityGuard\Tests\Unit\Support;

use AbilityGuard\Support\Redactor;
use Brain\Monkey;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests - no WordPress, no database.
 */
final class RedactorTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_keys_returns_non_empty_array(): void {
		$keys = Redactor::default_keys();
		$this->assertNotEmpty( $keys );
		$this->assertContains( 'password', $keys );
		$this->assertContains( 'api_key', $keys );
		$this->assertContains( 'token', $keys );
	}

	public function test_flat_key_replaced_with_sentinel(): void {
		$input  = array(
			'username' => 'alice',
			'password' => 'secret123',
		);
		$result = Redactor::redact( $input, array( 'password' ) );

		$this->assertSame( 'alice', $result['username'] );
		$this->assertSame( Redactor::SENTINEL, $result['password'] );
	}

	public function test_multiple_flat_keys_all_redacted(): void {
		$input  = array(
			'api_key'  => 'abc',
			'token'    => 'tok',
			'username' => 'bob',
		);
		$result = Redactor::redact( $input, array( 'api_key', 'token' ) );

		$this->assertSame( Redactor::SENTINEL, $result['api_key'] );
		$this->assertSame( Redactor::SENTINEL, $result['token'] );
		$this->assertSame( 'bob', $result['username'] );
	}

	public function test_missing_key_in_input_is_ignored(): void {
		$input  = array( 'foo' => 'bar' );
		$result = Redactor::redact( $input, array( 'password' ) );

		$this->assertSame( array( 'foo' => 'bar' ), $result );
	}

	public function test_case_insensitive_key_match(): void {
		$input  = array(
			'Password' => 'secret',
			'API_KEY'  => 'key123',
		);
		$result = Redactor::redact( $input, array( 'password', 'api_key' ) );

		$this->assertSame( Redactor::SENTINEL, $result['Password'] );
		$this->assertSame( Redactor::SENTINEL, $result['API_KEY'] );
	}

	public function test_path_segment_case_insensitive(): void {
		$input  = array( 'Actor' => array( 'Principal' => 'admin' ) );
		$result = Redactor::redact( $input, array( 'actor.principal' ) );

		$this->assertSame( Redactor::SENTINEL, $result['Actor']['Principal'] );
	}

	public function test_dot_path_single_level_nesting(): void {
		$input  = array(
			'auth' => array(
				'token' => 'secret',
				'user'  => 'alice',
			),
		);
		$result = Redactor::redact( $input, array( 'auth.token' ) );

		$this->assertSame( Redactor::SENTINEL, $result['auth']['token'] );
		$this->assertSame( 'alice', $result['auth']['user'] );
	}

	public function test_dot_path_deep_nesting(): void {
		$input  = array(
			'actor' => array(
				'principal' => array(
					'credential' => 'secret-cred',
					'name'       => 'alice',
				),
			),
		);
		$result = Redactor::redact( $input, array( 'actor.principal.credential' ) );

		$this->assertSame( Redactor::SENTINEL, $result['actor']['principal']['credential'] );
		$this->assertSame( 'alice', $result['actor']['principal']['name'] );
	}

	public function test_flat_and_dot_paths_combined(): void {
		$input  = array(
			'password'   => 'plain_secret',
			'connection' => array(
				'api_key' => 'conn_key',
				'host'    => 'localhost',
			),
		);
		$result = Redactor::redact( $input, array( 'password', 'connection.api_key' ) );

		$this->assertSame( Redactor::SENTINEL, $result['password'] );
		$this->assertSame( Redactor::SENTINEL, $result['connection']['api_key'] );
		$this->assertSame( 'localhost', $result['connection']['host'] );
	}

	public function test_recursion_through_nested_arrays(): void {
		$input  = array(
			'items' => array(
				array(
					'password' => 'p1',
					'name'     => 'n1',
				),
				array(
					'password' => 'p2',
					'name'     => 'n2',
				),
			),
		);
		$result = Redactor::redact( $input, array( 'password' ) );

		$this->assertSame( Redactor::SENTINEL, $result['items'][0]['password'] );
		$this->assertSame( Redactor::SENTINEL, $result['items'][1]['password'] );
		$this->assertSame( 'n1', $result['items'][0]['name'] );
	}

	public function test_scalar_at_root_returned_unchanged(): void {
		$this->assertSame( 'hello', Redactor::redact( 'hello', array( 'key' ) ) );
		$this->assertSame( 42, Redactor::redact( 42, array( 'key' ) ) );
		$this->assertSame( true, Redactor::redact( true, array( 'key' ) ) );
	}

	public function test_null_at_root_returned_unchanged(): void {
		$this->assertNull( Redactor::redact( null, array( 'password' ) ) );
	}

	public function test_custom_placeholder_is_used(): void {
		$input  = array( 'secret' => 'value' );
		$result = Redactor::redact( $input, array( 'secret' ), '***' );

		$this->assertSame( '***', $result['secret'] );
	}

	public function test_empty_key_list_leaves_value_intact(): void {
		$input  = array( 'password' => 'should_stay' );
		$result = Redactor::redact( $input, array() );

		$this->assertSame( 'should_stay', $result['password'] );
	}

	public function test_empty_array_returns_empty_array(): void {
		$this->assertSame( array(), Redactor::redact( array(), array( 'password' ) ) );
	}

	public function test_object_values_are_handled(): void {
		$obj           = new \stdClass();
		$obj->password = 'hidden';
		$obj->name     = 'alice';

		$result = Redactor::redact( $obj, array( 'password' ) );

		$this->assertSame( Redactor::SENTINEL, $result->password );
		$this->assertSame( 'alice', $result->name );
	}
}
