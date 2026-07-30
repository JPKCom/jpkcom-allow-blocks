<?php
/**
 * Import and export
 *
 * Serialises the settings to a portable JSON payload so a site's block
 * permissions can be moved to another install, and reads that payload back
 * in with a preview step before anything is written.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_export_payload' ) ) {

    /**
     * Build the exportable payload for a settings structure.
     *
     * Adds provenance fields so an import can tell where a file came from:
     * which plugin version wrote it, which site it was exported from, and
     * when.
     *
     * @since 3.0.0
     *
     * @param array $settings Validated settings.
     * @return array The settings plus `plugin_version`, `site_url` and `exported`.
     */
    function jpkcom_allow_blocks_export_payload( array $settings ): array {
        $payload                   = jpkcom_allow_blocks_sanitize_settings( $settings );
        $payload['plugin_version'] = JPKCOM_ALLOW_BLOCKS_VERSION;
        $payload['site_url']       = home_url();
        $payload['exported']       = current_time( 'c' );

        return $payload;
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_export_filename' ) ) {

    /**
     * Filename offered for a downloaded export.
     *
     * @since 3.0.0
     *
     * @return string Filename ending in `.json`.
     */
    function jpkcom_allow_blocks_export_filename(): string {
        return sanitize_file_name( 'jpkcom-allow-blocks-' . gmdate( 'Y-m-d' ) . '.json' );
    }

}

/** Maximum size, in bytes, an uploaded import file may have. */
if ( ! defined( constant_name: 'JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES' ) ) {
    define( constant_name: 'JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES', value: 1048576 );
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_parse_import' ) ) {

    /**
     * Decode and validate an import payload.
     *
     * Every rejection path leaves `settings` empty and carries a translated
     * reason: the caller must be able to show the user why nothing happened,
     * and nothing here writes anything.
     *
     * @since 3.0.0
     *
     * @param string $json Raw file contents.
     * @return array{ok:bool,error:string,settings:array} Parse result.
     */
    function jpkcom_allow_blocks_parse_import( string $json ): array {
        $decoded = json_decode( $json, true );

        if ( ! is_array( $decoded ) ) {
            return array(
                'ok'       => false,
                'error'    => __( 'The file is not valid JSON.', 'jpkcom-allow-blocks' ),
                'settings' => array(),
            );
        }

        if ( ! isset( $decoded['schema'] ) || 1 !== $decoded['schema'] ) {
            return array(
                'ok'       => false,
                'error'    => __( 'This file is not a recognised block permissions export.', 'jpkcom-allow-blocks' ),
                'settings' => array(),
            );
        }

        if ( ! isset( $decoded['roles'] ) || ! is_array( $decoded['roles'] ) ) {
            return array(
                'ok'       => false,
                'error'    => __( 'The file has no roles to import.', 'jpkcom-allow-blocks' ),
                'settings' => array(),
            );
        }

        return array(
            'ok'       => true,
            'error'    => '',
            'settings' => jpkcom_allow_blocks_sanitize_settings( $decoded ),
        );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_merge_import' ) ) {

    /**
     * Merge an imported settings structure into the current one.
     *
     * The unit is the role, not the individual block: a role present in
     * `$incoming` replaces that role's whole list, a role absent from it is
     * left completely untouched. A role that does not exist on this site is
     * stored anyway - it may be created later, or come from a plugin that is
     * currently deactivated - which mirrors why this plugin stores a deny
     * list at all: nothing is ever pruned. Labels are merged with the
     * incoming file winning for keys it contains.
     *
     * @since 3.0.0
     *
     * @param array $current  Current validated settings.
     * @param array $incoming Validated settings decoded from an import file.
     * @return array Merged settings, not yet stored.
     */
    function jpkcom_allow_blocks_merge_import( array $current, array $incoming ): array {
        $current  = jpkcom_allow_blocks_sanitize_settings( $current );
        $incoming = jpkcom_allow_blocks_sanitize_settings( $incoming );

        $merged = $current;

        foreach ( $incoming['roles'] as $slug => $blocked ) {
            $merged['roles'][ $slug ] = $blocked;
        }

        $merged['labels'] = array_merge( $current['labels'], $incoming['labels'] );

        return jpkcom_allow_blocks_sanitize_settings( $merged );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_import_preview' ) ) {

    /**
     * Describe what an import would change, before anything is written.
     *
     * Counts are taken per role: a role counts as changed when its list
     * would differ from what is currently stored, whether or not the role
     * exists on this site. `unknown_roles` names incoming roles absent from
     * `$known_roles` so the import is not silently doing more than it
     * appears to. `unknown_blocks` names blocks the incoming file mentions
     * that are not in `$known_blocks` - the live block registry, passed in
     * rather than read here so this stays a pure, testable function. That
     * includes a block already present in `$current`: a plugin that is
     * currently switched off still leaves its block name in the stored
     * settings, and the whole point of this count is to surface exactly
     * that "this install cannot currently offer it" case.
     *
     * @since 3.0.0
     *
     * @param array    $current     Current validated settings.
     * @param array    $incoming    Validated settings decoded from an import file.
     * @param string[] $known_roles Role slugs that exist on this site.
     * @param string[] $known_blocks Block names registered on this site. Defaults to
     *                                empty, which marks every incoming block unknown;
     *                                callers should always pass the real registry list.
     * @return array{roles_changed:int,blocks_changed:int,unknown_roles:string[],unknown_blocks:string[]} Preview summary.
     */
    function jpkcom_allow_blocks_import_preview( array $current, array $incoming, array $known_roles, array $known_blocks = array() ): array {
        $current  = jpkcom_allow_blocks_sanitize_settings( $current );
        $incoming = jpkcom_allow_blocks_sanitize_settings( $incoming );

        $incoming_blocks = array_values( array_unique( array_merge( array(), ...array_values( $incoming['roles'] ) ) ) );

        $roles_changed  = 0;
        $blocks_changed = 0;

        foreach ( $incoming['roles'] as $slug => $blocked ) {
            $before  = $current['roles'][ $slug ] ?? array();
            $added   = array_diff( $blocked, $before );
            $removed = array_diff( $before, $blocked );

            if ( array() !== $added || array() !== $removed ) {
                ++$roles_changed;
            }

            $blocks_changed += count( $added ) + count( $removed );
        }

        return array(
            'roles_changed'  => $roles_changed,
            'blocks_changed' => $blocks_changed,
            'unknown_roles'  => array_values( array_diff( array_keys( $incoming['roles'] ), $known_roles ) ),
            'unknown_blocks' => array_values( array_diff( $incoming_blocks, $known_blocks ) ),
        );
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_import_error_redirect' ) ) {

    /**
     * Redirect back to the settings page with an import error notice.
     *
     * Used in place of wp_die() for anything short of a failed capability or
     * nonce check, so a rejected upload never throws the user out of the
     * admin interface - they land back on the settings screen with an
     * explanation instead of a bare error page.
     *
     * @since 3.0.0
     *
     * @param string $message  Translated error message to show.
     * @param string $back_url Settings page URL to redirect to.
     * @return never
     */
    function jpkcom_allow_blocks_import_error_redirect( string $message, string $back_url ): never {
        wp_safe_redirect( add_query_arg( 'jpkcom-ab-import-error', rawurlencode( $message ), $back_url ) );
        exit;
    }

}

add_action( 'admin_post_jpkcom_allow_blocks_export', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to export block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_export', 'jpkcom_allow_blocks_export_nonce' );

    $payload  = jpkcom_allow_blocks_export_payload( jpkcom_allow_blocks_get_settings() );
    $filename = jpkcom_allow_blocks_export_filename();

    header( 'Content-Type: application/json' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    nocache_headers();

    echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
} );

add_action( 'admin_post_jpkcom_allow_blocks_import_preview', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to import block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_import_preview', 'jpkcom_allow_blocks_import_nonce' );

    $back_url = admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() );
    $file     = $_FILES['jpkcom_allow_blocks_file'] ?? null;

    if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'] ) || ! is_string( $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'No file was uploaded.', 'jpkcom-allow-blocks' ), $back_url );
    }

    if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'The uploaded file could not be verified.', 'jpkcom-allow-blocks' ), $back_url );
    }

    $size = filesize( $file['tmp_name'] );

    if ( false === $size || $size > JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'The file is larger than the 1 MB limit.', 'jpkcom-allow-blocks' ), $back_url );
    }

    $json = file_get_contents( $file['tmp_name'] );

    if ( false === $json ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'The file could not be read.', 'jpkcom-allow-blocks' ), $back_url );
    }

    $parsed = jpkcom_allow_blocks_parse_import( $json );

    if ( ! $parsed['ok'] ) {
        jpkcom_allow_blocks_import_error_redirect( $parsed['error'], $back_url );
    }

    /*
     * The parsed settings are stashed server-side rather than round-tripped
     * through the browser: the preview screen only ever needs the token to
     * find them again, which also keeps the whole uploaded payload from
     * being reflected back into the page.
     */
    $token = wp_generate_password( 20, false, false );

    set_transient( 'jpkcom_allow_blocks_import_' . $token, $parsed['settings'], 15 * MINUTE_IN_SECONDS );

    wp_safe_redirect( add_query_arg( 'jpkcom-ab-import', $token, $back_url ) );
    exit;
} );

add_action( 'admin_post_jpkcom_allow_blocks_import_apply', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to import block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_import_apply', 'jpkcom_allow_blocks_apply_nonce' );

    $back_url = admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() );
    $token    = isset( $_POST['token'] ) && is_string( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';

    if ( '' === $token ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'The import preview has expired. Please upload the file again.', 'jpkcom-allow-blocks' ), $back_url );
    }

    $transient_key = 'jpkcom_allow_blocks_import_' . $token;
    $incoming      = get_transient( $transient_key );

    if ( ! is_array( $incoming ) ) {
        jpkcom_allow_blocks_import_error_redirect( __( 'The import preview has expired. Please upload the file again.', 'jpkcom-allow-blocks' ), $back_url );
    }

    $merged = jpkcom_allow_blocks_merge_import( jpkcom_allow_blocks_get_settings(), $incoming );

    jpkcom_allow_blocks_save_settings( $merged );

    delete_transient( $transient_key );

    wp_safe_redirect( add_query_arg( 'jpkcom-ab-imported', '1', $back_url ) );
    exit;
} );
