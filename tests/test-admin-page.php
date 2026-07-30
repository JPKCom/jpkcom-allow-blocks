<?php
/**
 * Regression tests for the settings screen data helpers.
 *
 * @package JPKCom_Allow_Blocks
 * @since 3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    define( constant_name: 'ABSPATH', value: __DIR__ . '/' );
}

$GLOBALS['jpkcom_options'] = array();
$GLOBALS['jpkcom_hooks']   = array();
$GLOBALS['jpkcom_filters'] = array();

$GLOBALS['jpkcom_wp_roles'] = array(
    'administrator' => array( 'name' => 'Administrator', 'capabilities' => array( 'edit_posts' => true, 'manage_options' => true ) ),
    'editor'        => array( 'name' => 'Editor', 'capabilities' => array( 'edit_posts' => true ) ),
    'subscriber'    => array( 'name' => 'Subscriber', 'capabilities' => array( 'read' => true ) ),
);

$GLOBALS['jpkcom_registry'] = array(
    'core/paragraph' => array( 'title' => 'Paragraph', 'category' => 'text' ),
    'core/heading'   => array( 'title' => 'Heading', 'category' => 'text' ),
    'core/image'     => array( 'title' => 'Image', 'category' => 'media' ),
);

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

if ( ! function_exists( function: 'add_action' ) ) {
    function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {
        $GLOBALS['jpkcom_hooks'][ $hook ][] = array( $callback, $priority );
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

if ( ! function_exists( function: '__' ) ) {
    function __( string $text, string $domain = 'default' ): string {
        return $text;
    }
}

if ( ! function_exists( function: 'wp_roles' ) ) {
    function wp_roles(): object {
        return (object) array( 'roles' => $GLOBALS['jpkcom_wp_roles'] );
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
            foreach ( $GLOBALS['jpkcom_registry'] as $name => $data ) {
                $out[ $name ] = (object) array( 'name' => $name, 'title' => $data['title'], 'category' => $data['category'] );
            }
            return $out;
        }
    }
}

require_once dirname( path: __DIR__ ) . '/includes/settings-store.php';
require_once dirname( path: __DIR__ ) . '/includes/admin-page.php';

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

echo "jpkcom-allow-blocks: settings screen data\n";

/* Roles. */
$roles = jpkcom_allow_blocks_editable_roles();

jpkcom_check( 'administrator is never offered', ! isset( $roles['administrator'] ) );
jpkcom_check( 'editor is offered', isset( $roles['editor'] ) );
jpkcom_check( 'a role without edit_posts is hidden by default', ! isset( $roles['subscriber'] ) );

$roles = jpkcom_allow_blocks_editable_roles( true );
jpkcom_check( 'a role without edit_posts can be shown', isset( $roles['subscriber'] ) );
jpkcom_check( 'administrator stays hidden even then', ! isset( $roles['administrator'] ) );

/* Rows. */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array(
        'roles'  => array( 'editor' => array( 'gone/block' ) ),
        'labels' => array( 'gone/block' => 'Retired Block' ),
    )
);

$rows  = jpkcom_allow_blocks_block_rows( $settings );
$names = array_column( $rows, 'name' );

jpkcom_check( 'registered blocks are listed', in_array( 'core/paragraph', $names, true ) );
jpkcom_check( 'a block only known from settings is listed', in_array( 'gone/block', $names, true ) );

$by_name = array_column( $rows, null, 'name' );

jpkcom_check( 'a registered row is marked registered', true === $by_name['core/paragraph']['registered'] );
jpkcom_check( 'a settings-only row is marked unregistered', false === $by_name['gone/block']['registered'] );
jpkcom_check( 'a registered row uses the block title', $by_name['core/paragraph']['title'] === 'Paragraph' );
jpkcom_check( 'a settings-only row falls back to the stored label', $by_name['gone/block']['title'] === 'Retired Block' );
jpkcom_check( 'a registered row carries its category', $by_name['core/paragraph']['category'] === 'text' );
jpkcom_check( 'a settings-only row gets the unregistered category', $by_name['gone/block']['category'] === 'jpkcom-unregistered' );

/* Sorting is stable: category first, then title. */
$categories = array_column( $rows, 'category' );
$sorted     = $categories;
sort( $sorted );
jpkcom_check( 'rows are grouped by category', $categories === $sorted, json_encode( $categories ) );

/* The menu is registered under Appearance. */
jpkcom_check( 'the admin_menu hook is used', ( $GLOBALS['jpkcom_hooks']['admin_menu'] ?? array() ) !== array() );

/*
 * Saving computes the difference over the rendered rows only. Without that, a
 * save with an active search filter would wipe every setting not on screen.
 */
$settings = jpkcom_allow_blocks_sanitize_settings(
    array( 'roles' => array( 'editor' => array( 'core/code', 'core/html' ) ) )
);

$result = jpkcom_allow_blocks_apply_form(
    $settings,
    array( 'core/code', 'core/paragraph' ),
    array( 'editor' => array( 'core/code' => '1' ) )
);

jpkcom_check(
    'a row rendered and ticked is unblocked',
    ! in_array( 'core/code', $result['roles']['editor'], true )
);
jpkcom_check(
    'a row rendered and unticked is blocked',
    in_array( 'core/paragraph', $result['roles']['editor'], true )
);
jpkcom_check(
    'a row that was not rendered keeps its setting',
    in_array( 'core/html', $result['roles']['editor'], true ),
    json_encode( $result['roles']['editor'] )
);

$result = jpkcom_allow_blocks_apply_form( $settings, array( 'core/code' ), array() );
jpkcom_check(
    'a role absent from the submission still loses its rendered rows',
    in_array( 'core/code', $result['roles']['editor'], true )
);

/*
 * There used to be a test here asserting that an invalid rendered name
 * ('bad name') does not survive into $result['roles']. It passed even with
 * the array_filter() it was meant to pin deleted from
 * jpkcom_allow_blocks_apply_form(), because jpkcom_allow_blocks_sanitize_settings()
 * - always called on the way out - filters every role list against the
 * block-name grammar regardless. That filter step in apply_form() was
 * genuinely redundant, so it was removed from the source rather than kept
 * for a test that could not actually tell it apart from its absence.
 */

/* A registered block gets its title recorded as a label. */
$result = jpkcom_allow_blocks_apply_form( $settings, array( 'core/paragraph' ), array( 'editor' => array() ) );
jpkcom_check(
    'a registered block still records its title',
    ( $result['labels']['core/paragraph'] ?? '' ) === 'Paragraph'
);

/*
 * An unregistered row that is ticked allowed for every role and marked
 * "forget" disappears from the stored settings entirely - both the roles
 * that used to block it and the label that kept it on screen. Without this,
 * the row could never be removed: saving with every box ticked leaves the
 * deny lists empty, but the old code re-recorded a label using the block
 * name as its own title, which brought the row straight back on the next
 * render.
 */
$settings_with_unregistered = jpkcom_allow_blocks_sanitize_settings(
    array(
        'roles'  => array(
            'editor' => array( 'gone/block' ),
        ),
        'labels' => array( 'gone/block' => 'Retired Block' ),
    )
);

$result = jpkcom_allow_blocks_apply_form(
    $settings_with_unregistered,
    array( 'gone/block' ),
    array( 'editor' => array( 'gone/block' => '1' ) ),
    array( 'gone/block' => '1' ),
    array( 'editor' )
);

jpkcom_check(
    'a forgotten unregistered row leaves every role deny list',
    ! in_array( 'gone/block', $result['roles']['editor'], true )
);
jpkcom_check(
    'a forgotten unregistered row loses its label',
    ! isset( $result['labels']['gone/block'] )
);

/*
 * Even without ticking "forget", an unregistered row that ends up blocked
 * by no role after the save loses its label automatically: a name nobody
 * blocks and nothing registers has no reason to persist. $roles is
 * restricted to 'editor' here, the only role the fixture ever blocked the
 * row for, so the outcome is not muddied by other roles the stub declares.
 */
$result = jpkcom_allow_blocks_apply_form(
    $settings_with_unregistered,
    array( 'gone/block' ),
    array( 'editor' => array( 'gone/block' => '1' ) ),
    array(),
    array( 'editor' )
);

jpkcom_check(
    'an unregistered row unblocked everywhere loses its label without forget',
    ! isset( $result['labels']['gone/block'] )
);

/* Roles offered as columns are filtered to slugs the store would accept. */
$GLOBALS['jpkcom_wp_roles']['Shop_Manager'] = array( 'name' => 'Shop Manager', 'capabilities' => array( 'edit_posts' => true ) );

$roles = jpkcom_allow_blocks_editable_roles( true );
jpkcom_check( 'a role slug sanitize_key() would change is not offered as a column', ! isset( $roles['Shop_Manager'] ) );

unset( $GLOBALS['jpkcom_wp_roles']['Shop_Manager'] );

printf( "\n  %d passed, %d failed\n", $passed, $failed );

exit( $failed > 0 ? 1 : 0 );
