<?php
/**
 * Runtime block restriction
 *
 * Turns the stored deny list into the allow list WordPress expects.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_is_exempt' ) ) {

    /**
     * Whether the current user is exempt from any restriction.
     *
     * Expressed as a capability rather than a role slug so multisite super
     * admins are covered. Administrators are never restricted.
     *
     * @since 3.0.0
     *
     * @return bool True when no restriction applies.
     */
    function jpkcom_allow_blocks_is_exempt(): bool {
        /**
         * Filters whether the current user is exempt from block restrictions.
         *
         * @since 3.0.0
         *
         * @param bool $exempt Whether the user is exempt.
         */
        return (bool) apply_filters( 'jpkcom_allow_blocks_is_exempt', current_user_can( 'manage_options' ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_current_role_slugs' ) ) {

    /**
     * Role slugs of the current user.
     *
     * @since 3.0.0
     *
     * @return string[] Role slugs, empty when there is no user.
     */
    function jpkcom_allow_blocks_current_role_slugs(): array {
        if ( ! is_user_logged_in() ) {
            return array();
        }

        $user = wp_get_current_user();

        if ( ! isset( $user->roles ) || ! is_array( $user->roles ) ) {
            return array();
        }

        return array_values( array_filter( $user->roles, 'is_string' ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_all_block_names' ) ) {

    /**
     * Every block name this site knows about.
     *
     * The server-side registry, plus every name mentioned in the settings so a
     * deactivated plugin's blocks are not silently forgotten, plus whatever the
     * extension filter adds.
     *
     * Blocks registered only in JavaScript are invisible to PHP. Sites using
     * such blocks can add their names through
     * `jpkcom_allow_blocks_extra_block_names`.
     *
     * @since 3.0.0
     *
     * @param array $settings Validated settings.
     * @return string[] Unique block names.
     */
    function jpkcom_allow_blocks_all_block_names( array $settings ): array {
        $names = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );

        foreach ( $settings['roles'] as $blocked ) {
            $names = array_merge( $names, $blocked );
        }

        $names = array_merge( $names, array_keys( $settings['labels'] ) );

        /**
         * Filters the block names the allow list is built from.
         *
         * @since 3.0.0
         *
         * @param string[] $names Block names known to PHP.
         */
        $names = apply_filters( 'jpkcom_allow_blocks_extra_block_names', $names );

        if ( ! is_array( $names ) ) {
            return array();
        }

        return array_values( array_unique( array_filter( $names, 'jpkcom_allow_blocks_is_valid_block_name' ) ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_filter_allowed' ) ) {

    /**
     * Remove the blocked block types from the allowed list.
     *
     * Returns the incoming value untouched whenever nothing is blocked, so an
     * active but unconfigured plugin has no effect at all. An incoming array is
     * only ever reduced, never extended, so restrictions set by other plugins
     * are respected instead of overwritten.
     *
     * @since 3.0.0
     *
     * @param mixed $allowed Incoming value: true, false or an array of names.
     * @param mixed $context The block editor context. Unused.
     * @return mixed The allowed block types.
     */
    function jpkcom_allow_blocks_filter_allowed( mixed $allowed, mixed $context ): mixed {
        if ( false === $allowed ) {
            return false;
        }

        if ( jpkcom_allow_blocks_is_exempt() ) {
            return $allowed;
        }

        $settings = jpkcom_allow_blocks_get_settings();
        $blocked  = jpkcom_allow_blocks_blocked_for_roles( jpkcom_allow_blocks_current_role_slugs(), $settings );

        if ( array() === $blocked ) {
            return $allowed;
        }

        if ( is_array( $allowed ) ) {
            return array_values( array_diff( $allowed, $blocked ) );
        }

        return array_values( array_diff( jpkcom_allow_blocks_all_block_names( $settings ), $blocked ) );
    }

}

add_filter( 'allowed_block_types_all', 'jpkcom_allow_blocks_filter_allowed', 10, 2 );
