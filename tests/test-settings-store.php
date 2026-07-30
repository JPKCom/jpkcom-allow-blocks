<?php
/**
 * Regression tests for the settings store of jpkcom-allow-blocks.
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

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';

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

echo "jpkcom-allow-blocks: settings store\n";

/* Block name grammar. */
foreach ( array( 'core/paragraph', 'areoi/accordion-item', 'my-plugin/block-1' ) as $name ) {
    jpkcom_check( sprintf( '%s is a valid block name', $name ), jpkcom_allow_blocks_is_valid_block_name( $name ) );
}

foreach ( array( 'core', 'Core/Paragraph', 'core/', '/paragraph', 'core/para graph', '', 'core/para/graph' ) as $name ) {
    jpkcom_check( sprintf( '%s is rejected', var_export( $name, true ) ), ! jpkcom_allow_blocks_is_valid_block_name( $name ) );
}

/* Sanitising never throws and always returns the full structure. */
foreach ( array( 'garbage', 42, null, array(), array( 'roles' => 'nope' ) ) as $raw ) {
    $out = jpkcom_allow_blocks_sanitize_settings( $raw );
    jpkcom_check(
        sprintf( 'sanitize keeps the structure for %s', gettype( $raw ) ),
        isset( $out['schema'], $out['roles'], $out['labels'] ) && is_array( $out['roles'] ) && is_array( $out['labels'] )
    );
}

/* Invalid entries are dropped, valid ones survive. */
$out = jpkcom_allow_blocks_sanitize_settings(
    array(
        'schema' => 1,
        'roles'  => array(
            'editor'   => array( 'core/code', 'NOT A BLOCK', 'core/html' ),
            'Bad Role' => array( 'core/code' ),
            'author'   => 'not-an-array',
        ),
        'labels' => array( 'core/code' => '<b>Code</b>', 'bad name' => 'x' ),
    )
);

jpkcom_check( 'valid role kept', isset( $out['roles']['editor'] ) );
jpkcom_check( 'invalid block name dropped', $out['roles']['editor'] === array( 'core/code', 'core/html' ), json_encode( $out['roles']['editor'] ?? null ) );
jpkcom_check( 'role slug is sanitised', ! isset( $out['roles']['Bad Role'] ) );
jpkcom_check( 'non-array role value dropped', ! isset( $out['roles']['author'] ) );
jpkcom_check( 'label is stripped of markup', ( $out['labels']['core/code'] ?? '' ) === 'Code' );
jpkcom_check( 'label for invalid block name dropped', ! isset( $out['labels']['bad name'] ) );

/* Round trip through the option. */
jpkcom_check( 'unset option reads as empty', jpkcom_allow_blocks_get_settings()['roles'] === array() );

jpkcom_allow_blocks_save_settings(
    array( 'roles' => array( 'editor' => array( 'core/code' ) ), 'labels' => array( 'core/code' => 'Code' ) )
);

$stored = jpkcom_allow_blocks_get_settings();
jpkcom_check( 'save then read returns the same names', $stored['roles']['editor'] === array( 'core/code' ) );
jpkcom_check( 'save stamps updated', $stored['updated'] !== '' );

/* A corrupt option must not be fatal. */
$GLOBALS['jpkcom_options'][ jpkcom_allow_blocks_option_name() ] = 'corrupt';
jpkcom_check( 'corrupt option reads as empty', jpkcom_allow_blocks_get_settings()['roles'] === array() );

/* Intersection across roles: blocked only when every role blocks it. */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array(
        'roles' => array(
            'editor'      => array( 'core/code', 'core/html' ),
            'contributor' => array( 'core/code', 'core/table' ),
            'shop'        => array(),
        ),
    )
);

jpkcom_check( 'single role returns its own list', jpkcom_allow_blocks_blocked_for_roles( array( 'editor' ), $settings ) === array( 'core/code', 'core/html' ) );
jpkcom_check( 'two roles intersect', jpkcom_allow_blocks_blocked_for_roles( array( 'editor', 'contributor' ), $settings ) === array( 'core/code' ) );
jpkcom_check( 'a role blocking nothing empties the intersection', jpkcom_allow_blocks_blocked_for_roles( array( 'editor', 'shop' ), $settings ) === array() );
jpkcom_check( 'an unknown role blocks nothing', jpkcom_allow_blocks_blocked_for_roles( array( 'nope' ), $settings ) === array() );
jpkcom_check( 'no roles blocks nothing', jpkcom_allow_blocks_blocked_for_roles( array(), $settings ) === array() );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
