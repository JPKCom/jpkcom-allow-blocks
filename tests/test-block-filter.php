<?php
/**
 * Regression tests for the allowed_block_types_all filter.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

$GLOBALS['jpkcom_options']  = array();
$GLOBALS['jpkcom_caps']     = array( 'manage_options' => false );
$GLOBALS['jpkcom_roles']    = array( 'editor' );
$GLOBALS['jpkcom_registry'] = array( 'core/paragraph', 'core/heading', 'core/code', 'core/html' );
$GLOBALS['jpkcom_hooks']    = array();
$GLOBALS['jpkcom_filters']  = array();

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
        return trim( strip_tags( $str ) );
    }
}

if ( ! function_exists( function: 'current_time' ) ) {
    function current_time( string $type, int|bool $gmt = 0 ): string {
        return '2026-07-30T12:00:00+00:00';
    }
}

if ( ! function_exists( function: 'current_user_can' ) ) {
    function current_user_can( string $capability, mixed ...$args ): bool {
        return (bool) ( $GLOBALS['jpkcom_caps'][ $capability ] ?? false );
    }
}

if ( ! function_exists( function: 'is_user_logged_in' ) ) {
    function is_user_logged_in(): bool {
        return true;
    }
}

if ( ! function_exists( function: 'wp_get_current_user' ) ) {
    function wp_get_current_user(): object {
        return (object) array( 'roles' => $GLOBALS['jpkcom_roles'] );
    }
}

if ( ! function_exists( function: 'add_filter' ) ) {
    function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
    }
}

if ( ! function_exists( function: 'apply_filters' ) ) {
    function apply_filters( string $hook, mixed $value, mixed ...$args ): mixed {
        foreach ( $GLOBALS['jpkcom_filters'][ $hook ] ?? array() as $cb ) {
            $value = $cb( $value, ...$args );
        }
        return $value;
    }
}

if ( ! class_exists( class: 'WP_Block_Type_Registry' ) ) {
    class WP_Block_Type_Registry {
        public static function get_instance(): self {
            return new self();
        }

        /** @return array<string,object> */
        public function get_all_registered(): array {
            $out = array();
            foreach ( $GLOBALS['jpkcom_registry'] as $name ) {
                $out[ $name ] = (object) array( 'name' => $name );
            }
            return $out;
        }
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';
require_once dirname( path: __DIR__ ) . '/includes/block-filter.php';

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

echo "jpkcom-allow-blocks: runtime filter\n";

jpkcom_check(
    'the filter is registered on allowed_block_types_all',
    ( $GLOBALS['jpkcom_hooks']['allowed_block_types_all'] ?? array() ) !== array()
);

/* Nothing configured: the plugin must be completely inert. */
jpkcom_check( 'inert for true', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
jpkcom_check( 'inert for an array', jpkcom_allow_blocks_filter_allowed( array( 'core/paragraph' ), null ) === array( 'core/paragraph' ) );
jpkcom_check( 'inert for false', jpkcom_allow_blocks_filter_allowed( false, null ) === false );

jpkcom_allow_blocks_save_settings(
    array( 'roles' => array( 'editor' => array( 'core/code', 'core/html' ) ) )
);

/* Administrators are exempt, by capability. */
$GLOBALS['jpkcom_caps']['manage_options'] = true;
jpkcom_check( 'an administrator is exempt', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
$GLOBALS['jpkcom_caps']['manage_options'] = false;

/* Incoming true: build from the registry and subtract. */
$result = jpkcom_allow_blocks_filter_allowed( true, null );
sort( $result );
jpkcom_check( 'true becomes the registry minus the blocked names', $result === array( 'core/heading', 'core/paragraph' ), json_encode( $result ) );

/* Incoming array: subtract only, never add. */
$result = jpkcom_allow_blocks_filter_allowed( array( 'core/paragraph', 'core/code' ), null );
jpkcom_check( 'an incoming array is only reduced', $result === array( 'core/paragraph' ), json_encode( $result ) );

/* Incoming false stays false. */
jpkcom_check( 'false is left alone', jpkcom_allow_blocks_filter_allowed( false, null ) === false );

/* A block nobody registered but that is blocked must not appear. */
$result = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'blocked names never reappear', ! in_array( 'core/code', $result, true ) );

/* Extra names can be added for blocks PHP cannot see. */
$GLOBALS['jpkcom_filters']['jpkcom_allow_blocks_extra_block_names'] = array(
    static function ( array $names ): array {
        $names[] = 'js-only/widget';
        return $names;
    },
);
$result = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'extra block names are included', in_array( 'js-only/widget', $result, true ), json_encode( $result ) );
$GLOBALS['jpkcom_filters'] = array();

/* The exemption is overridable. */
$GLOBALS['jpkcom_filters']['jpkcom_allow_blocks_is_exempt'] = array(
    static function ( bool $exempt ): bool {
        return true;
    },
);
jpkcom_check( 'the exemption filter wins', jpkcom_allow_blocks_filter_allowed( true, null ) === true );
$GLOBALS['jpkcom_filters'] = array();

/* Two roles: blocked only where both block it. */
$GLOBALS['jpkcom_roles'] = array( 'editor', 'shop' );
$result                  = jpkcom_allow_blocks_filter_allowed( true, null );
jpkcom_check( 'a second role that blocks nothing lifts the restriction', $result === true );
$GLOBALS['jpkcom_roles'] = array( 'editor' );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
