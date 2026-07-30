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

if ( ! function_exists( function: 'wp_parse_url' ) ) {
    function wp_parse_url( string $url, int $component = -1 ): mixed {
        return parse_url( $url, $component );
    }
}

if ( ! function_exists( function: 'wp_json_encode' ) ) {
    function wp_json_encode( mixed $data, int $options = 0, int $depth = 512 ): string|false {
        return json_encode( $data, $options, $depth );
    }
}

if ( ! function_exists( function: '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
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
jpkcom_check( 'the filename embeds the site host', str_contains( haystack: jpkcom_allow_blocks_export_filename(), needle: 'example.test' ), jpkcom_allow_blocks_export_filename() );

echo "\njpkcom-allow-blocks: import\n";

/*
 * Parsing rejects anything that is not a valid payload, without changing
 * state. The error travels as a short, stable code rather than translated
 * text - admin-page.php maps the code to a message, and a code this test
 * does not expect here would mean that mapping silently broke.
 */
$bad_inputs = array(
    'not json'          => 'invalid-json',
    '[]'                => 'bad-schema',
    '{"schema":99}'     => 'bad-schema',
    '{"schema":1}'      => 'no-roles',
);

foreach ( $bad_inputs as $bad => $expected_code ) {
    $parsed = jpkcom_allow_blocks_parse_import( $bad );
    jpkcom_check( sprintf( 'rejects %s', var_export( $bad, true ) ), false === $parsed['ok'], $parsed['error'] );
    jpkcom_check( sprintf( 'rejecting %s carries the code "%s"', var_export( $bad, true ), $expected_code ), $expected_code === $parsed['error'], $parsed['error'] );
}

$good   = wp_json_encode( jpkcom_allow_blocks_export_payload( $settings ) );
$parsed = jpkcom_allow_blocks_parse_import( (string) $good );
jpkcom_check( 'accepts its own export', true === $parsed['ok'], $parsed['error'] );
jpkcom_check( 'a round trip reproduces the roles', $parsed['settings']['roles'] === $settings['roles'] );
jpkcom_check( 'a clean export reports nothing rejected', 0 === $parsed['rejected'] );

/* A file with invalid entries reports how many were rejected. */
$dirty  = wp_json_encode(
    array(
        'schema' => 1,
        'roles'  => array( 'editor' => array( 'core/code', 'NOT A BLOCK', 'also bad' ) ),
    )
);
$parsed = jpkcom_allow_blocks_parse_import( (string) $dirty );
jpkcom_check( 'a file with invalid entries is still accepted', true === $parsed['ok'], $parsed['error'] );
jpkcom_check( 'the invalid entries are counted', 2 === $parsed['rejected'], (string) $parsed['rejected'] );

/* Merge is per role: a role in the file replaces, a role absent stays. */
$current = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ), 'author' => array( 'core/html' ) ) )
);
$incoming = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/table' ), 'shop_manager' => array( 'core/video' ) ) )
);

$merged = jpkcom_allow_blocks_merge_import( $current, $incoming );

jpkcom_check( 'a role in the file replaces its list', $merged['roles']['editor'] === array( 'core/table' ) );
jpkcom_check( 'a role absent from the file is untouched', $merged['roles']['author'] === array( 'core/html' ) );
jpkcom_check( 'a role unknown here is stored anyway', ( $merged['roles']['shop_manager'] ?? array() ) === array( 'core/video' ) );

/* The preview names what will happen before anything is written. */
$preview = jpkcom_allow_blocks_import_preview( $current, $incoming, array( 'editor', 'author' ) );

jpkcom_check( 'preview counts the changed roles', $preview['roles_changed'] === 2, json_encode( $preview ) );
jpkcom_check( 'preview names roles unknown here', $preview['unknown_roles'] === array( 'shop_manager' ) );
jpkcom_check( 'preview counts changed blocks', $preview['blocks_changed'] > 0 );

/*
 * unknown_blocks is scored against the live block registry, passed in as
 * $known_blocks, not against what is already in the stored settings.
 */
$incoming_blocks = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/paragraph', 'gone/block' ) ) )
);

$preview = jpkcom_allow_blocks_import_preview( $current, $incoming_blocks, array( 'editor', 'author' ), array( 'core/paragraph' ) );
jpkcom_check( 'preview names blocks unknown to the registry', $preview['unknown_blocks'] === array( 'gone/block' ), json_encode( $preview['unknown_blocks'] ) );

/* A block already stored here (from $current) still counts as unknown when it is not in the registry. */
$incoming_known_here = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ) ) )
);

$preview = jpkcom_allow_blocks_import_preview( $current, $incoming_known_here, array( 'editor', 'author' ), array( 'core/paragraph' ) );
jpkcom_check(
    'a block already in the stored settings still counts as unknown when off the registry',
    $preview['unknown_blocks'] === array( 'core/code' ),
    json_encode( $preview['unknown_blocks'] )
);

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
