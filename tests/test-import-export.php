<?php
/**
 * Regression tests for the export module of jpkcom-allow-blocks.
 *
 * Runs standalone (no WordPress): the functions the module touches are stubbed,
 * the module is required, and its functions are called directly.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

if ( ! defined( constant_name: 'JPKCOM_ALLOW_BLOCKS_VERSION' ) ) {
    define( constant_name: 'JPKCOM_ALLOW_BLOCKS_VERSION', value: '3.0.0' );
}

/** Option store used by the stubs. */
$GLOBALS['jpkcom_options'] = array();

if ( ! function_exists( function: 'get_option' ) ) {
    function get_option( string $option, mixed $default_value = false ): mixed {
        return $GLOBALS['jpkcom_options'][ $option ] ?? $default_value;
    }
}

if ( ! function_exists( function: 'update_option' ) ) {
    function update_option( string $option, mixed $value, mixed $autoload = null ): bool {
        $GLOBALS['jpkcom_options'][ $option ] = $value;
        return true;
    }
}

if ( ! function_exists( function: 'sanitize_key' ) ) {
    function sanitize_key( string $key ): string {
        return strtolower( (string) preg_replace( '/[^a-zA-Z0-9_\-]/', '', $key ) );
    }
}

if ( ! function_exists( function: 'sanitize_text_field' ) ) {
    function sanitize_text_field( string $str ): string {
        return trim( (string) preg_replace( '/[\r\n\t]+/', ' ', strip_tags( $str ) ) );
    }
}

if ( ! function_exists( function: 'current_time' ) ) {
    function current_time( string $type, int|bool $gmt = 0 ): string {
        return '2026-07-30T12:00:00+00:00';
    }
}

if ( ! function_exists( function: 'sanitize_file_name' ) ) {
    function sanitize_file_name( string $filename ): string {
        return (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $filename );
    }
}

if ( ! function_exists( function: 'home_url' ) ) {
    function home_url( string $path = '' ): string {
        return 'https://example.test' . $path;
    }
}

if ( ! function_exists( function: 'wp_json_encode' ) ) {
    function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
        return json_encode( $data, $options, $depth );
    }
}

/* Registering the export handler at require time needs add_action(). */
$GLOBALS['jpkcom_hooks'] = array();

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';
require_once dirname( path: __DIR__ ) . '/includes/import-export.php';

$failed = 0;
$passed = 0;

/**
 * Assert a condition and report it.
 *
 * @param string $name Case name.
 * @param bool   $ok   Whether the case passed.
 * @param string $note Extra detail printed on failure.
 * @return void
 */
function jpkcom_check( string $name, bool $ok, string $note = '' ): void {
    global $failed, $passed;

    if ( $ok ) {
        ++$passed;
        printf( "  ok   %s\n", $name );
        return;
    }

    ++$failed;
    printf( "  FAIL %s%s\n", $name, $note !== '' ? ' -- ' . $note : '' );
}

echo "jpkcom-allow-blocks: export\n";

$settings = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ) ), 'labels' => array( 'core/code' => 'Code' ) )
);

$payload = jpkcom_allow_blocks_export_payload( $settings );

jpkcom_check( 'the payload carries the schema', ( $payload['schema'] ?? 0 ) === 1 );
jpkcom_check( 'the payload carries the roles', ( $payload['roles']['editor'] ?? array() ) === array( 'core/code' ) );
jpkcom_check( 'the payload records the plugin version', ( $payload['plugin_version'] ?? '' ) === '3.0.0' );
jpkcom_check( 'the payload records the site', ( $payload['site_url'] ?? '' ) === 'https://example.test' );
jpkcom_check( 'the payload is JSON-encodable', is_string( wp_json_encode( $payload ) ) );
jpkcom_check( 'the filename ends in .json', str_ends_with( haystack: jpkcom_allow_blocks_export_filename(), needle: '.json' ) );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
