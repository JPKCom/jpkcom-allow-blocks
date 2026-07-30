<?php
/**
 * Settings store
 *
 * Reads, writes and validates the single option this plugin owns. Every other
 * module goes through here, so validation cannot be bypassed by adding a caller.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_option_name' ) ) {

    /**
     * Name of the option holding all settings.
     *
     * @since 3.0.0
     *
     * @return string Option name.
     */
    function jpkcom_allow_blocks_option_name(): string {
        return 'jpkcom_allow_blocks_settings';
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_is_valid_block_name' ) ) {

    /**
     * Whether a string is a syntactically valid block name.
     *
     * Follows the WordPress block name grammar: a lowercase namespace and name
     * separated by exactly one slash.
     *
     * @since 3.0.0
     *
     * @param string $name Candidate block name.
     * @return bool True when the name may be stored.
     */
    function jpkcom_allow_blocks_is_valid_block_name( string $name ): bool {
        return 1 === preg_match( '#^[a-z0-9][a-z0-9-]*/[a-z0-9][a-z0-9-]*$#', $name );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_empty_settings' ) ) {

    /**
     * The empty settings structure.
     *
     * @since 3.0.0
     *
     * @return array{schema:int,updated:string,roles:array<string,string[]>,labels:array<string,string>}
     */
    function jpkcom_allow_blocks_empty_settings(): array {
        return array(
            'schema'  => 1,
            'updated' => '',
            'roles'   => array(),
            'labels'  => array(),
        );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_sanitize_settings' ) ) {

    /**
     * Coerce any input into a valid settings structure.
     *
     * Entries that fail validation are dropped rather than stored. Never throws,
     * so a corrupt option can only ever mean "nothing is blocked".
     *
     * @since 3.0.0
     *
     * @param mixed $raw Value from the database, an import file or a form.
     * @return array The validated structure.
     */
    function jpkcom_allow_blocks_sanitize_settings( mixed $raw ): array {
        $clean = jpkcom_allow_blocks_empty_settings();

        if ( ! is_array( $raw ) ) {
            return $clean;
        }

        if ( isset( $raw['updated'] ) && is_string( $raw['updated'] ) ) {
            $clean['updated'] = sanitize_text_field( $raw['updated'] );
        }

        if ( isset( $raw['roles'] ) && is_array( $raw['roles'] ) ) {
            foreach ( $raw['roles'] as $role => $blocked ) {
                if ( ! is_string( $role ) || ! is_array( $blocked ) ) {
                    continue;
                }

                $slug = sanitize_key( $role );

                if ( '' === $slug || $slug !== $role ) {
                    continue;
                }

                $names = array();

                foreach ( $blocked as $name ) {
                    if ( is_string( $name ) && jpkcom_allow_blocks_is_valid_block_name( $name ) ) {
                        $names[] = $name;
                    }
                }

                $clean['roles'][ $slug ] = array_values( array_unique( $names ) );
            }
        }

        if ( isset( $raw['labels'] ) && is_array( $raw['labels'] ) ) {
            foreach ( $raw['labels'] as $name => $label ) {
                if ( ! is_string( $name ) || ! is_string( $label ) || ! jpkcom_allow_blocks_is_valid_block_name( $name ) ) {
                    continue;
                }

                $clean['labels'][ $name ] = mb_substr( sanitize_text_field( $label ), 0, 120 );
            }
        }

        return $clean;
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_get_settings' ) ) {

    /**
     * Read the validated settings.
     *
     * @since 3.0.0
     *
     * @return array The validated structure.
     */
    function jpkcom_allow_blocks_get_settings(): array {
        return jpkcom_allow_blocks_sanitize_settings( get_option( jpkcom_allow_blocks_option_name(), array() ) );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_save_settings' ) ) {

    /**
     * Validate and store the settings.
     *
     * Autoload is off: the option is only read in the admin area, so the front
     * end should not carry it on every request.
     *
     * @since 3.0.0
     *
     * @param array $settings Structure to store.
     * @return bool True when the option was written.
     */
    function jpkcom_allow_blocks_save_settings( array $settings ): bool {
        $clean            = jpkcom_allow_blocks_sanitize_settings( $settings );
        $clean['updated'] = current_time( 'c' );

        return (bool) update_option( jpkcom_allow_blocks_option_name(), $clean, false );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_blocked_for_roles' ) ) {

    /**
     * Block names blocked for every one of the given roles.
     *
     * The intersection, not the union: a block is blocked only when all of the
     * user's roles block it. This mirrors WordPress capability semantics, where
     * holding more roles never means holding fewer rights. A role with no entry
     * blocks nothing, so it empties the intersection.
     *
     * @since 3.0.0
     *
     * @param string[]   $role_slugs Roles of the user.
     * @param array|null $settings   Settings to use, or null to read them.
     * @return string[] Blocked block names, re-indexed.
     */
    function jpkcom_allow_blocks_blocked_for_roles( array $role_slugs, ?array $settings = null ): array {
        if ( array() === $role_slugs ) {
            return array();
        }

        $settings = null === $settings ? jpkcom_allow_blocks_get_settings() : jpkcom_allow_blocks_sanitize_settings( $settings );
        $blocked  = null;

        foreach ( $role_slugs as $slug ) {
            $for_role = $settings['roles'][ $slug ] ?? array();

            if ( array() === $for_role ) {
                return array();
            }

            $blocked = null === $blocked ? $for_role : array_intersect( $blocked, $for_role );

            if ( array() === $blocked ) {
                return array();
            }
        }

        return array_values( $blocked ?? array() );
    }

}
