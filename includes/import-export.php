<?php
/**
 * Import and export
 *
 * Serialises the settings to a portable JSON payload so a site's block
 * permissions can be moved to another install. This file currently only
 * carries the export half; import lands in a later task.
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

add_action( 'admin_post_jpkcom_allow_blocks_export', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to export block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_export' );

    $payload  = jpkcom_allow_blocks_export_payload( jpkcom_allow_blocks_get_settings() );
    $filename = jpkcom_allow_blocks_export_filename();

    header( 'Content-Type: application/json' );
    header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
    nocache_headers();

    echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    exit;
} );
