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

add_action( 'admin_post_jpkcom_allow_blocks_import_preview', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to import block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_import_preview' );

    $back_url = admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() );
    $file     = $_FILES['jpkcom_allow_blocks_file'] ?? null;

    if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['error'] ) || ! is_string( $file['tmp_name'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
        wp_die( esc_html__( 'No file was uploaded.', 'jpkcom-allow-blocks' ), '', array( 'response' => 400 ) );
    }

    if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
        wp_die( esc_html__( 'The uploaded file could not be verified.', 'jpkcom-allow-blocks' ), '', array( 'response' => 400 ) );
    }

    $size = filesize( $file['tmp_name'] );

    if ( false === $size || $size > JPKCOM_ALLOW_BLOCKS_IMPORT_MAX_BYTES ) {
        wp_die( esc_html__( 'The file is larger than the 1 MB limit.', 'jpkcom-allow-blocks' ), '', array( 'response' => 400 ) );
    }

    $json = file_get_contents( $file['tmp_name'] );

    if ( false === $json ) {
        wp_die( esc_html__( 'The file could not be read.', 'jpkcom-allow-blocks' ), '', array( 'response' => 400 ) );
    }

    $parsed = jpkcom_allow_blocks_parse_import( $json );

    if ( ! $parsed['ok'] ) {
        wp_die( esc_html( $parsed['error'] ), '', array( 'response' => 400 ) );
    }

    $payload_json = wp_json_encode( $parsed['settings'] );

    if ( false === $payload_json ) {
        wp_die( esc_html__( 'The file could not be re-encoded for confirmation.', 'jpkcom-allow-blocks' ), '', array( 'response' => 400 ) );
    }

    $known_roles  = array_keys( jpkcom_allow_blocks_editable_roles( true ) );
    $known_blocks = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
    $preview      = jpkcom_allow_blocks_import_preview( jpkcom_allow_blocks_get_settings(), $parsed['settings'], $known_roles, $known_blocks );

    ?>
    <!DOCTYPE html>
    <html <?php language_attributes(); ?>>
    <head>
        <meta charset="<?php bloginfo( 'charset' ); ?>" />
        <title><?php echo esc_html__( 'Import preview', 'jpkcom-allow-blocks' ); ?></title>
    </head>
    <body class="wp-admin">
        <div class="wrap jpkcom-ab-wrap">
            <h1><?php echo esc_html__( 'Import preview', 'jpkcom-allow-blocks' ); ?></h1>

            <p>
                <?php
                echo esc_html(
                    sprintf(
                        /* translators: 1: number of roles that will change, 2: number of block entries that will change. */
                        __( '%1$d role(s) will change, touching %2$d block permission entry/entries.', 'jpkcom-allow-blocks' ),
                        $preview['roles_changed'],
                        $preview['blocks_changed']
                    )
                );
                ?>
            </p>

            <?php if ( array() !== $preview['unknown_roles'] ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: comma-separated role slugs. */
                                __( 'These roles do not exist on this site and will be stored anyway: %s', 'jpkcom-allow-blocks' ),
                                implode( ', ', $preview['unknown_roles'] )
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( array() !== $preview['unknown_blocks'] ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %s: comma-separated block names. */
                                __( 'These blocks are not currently tracked on this site: %s', 'jpkcom-allow-blocks' ),
                                implode( ', ', $preview['unknown_blocks'] )
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <?php wp_nonce_field( 'jpkcom_allow_blocks_import_apply' ); ?>
                <input type="hidden" name="action" value="jpkcom_allow_blocks_import_apply" />
                <input type="hidden" name="payload" value="<?php echo esc_attr( $payload_json ); ?>" />
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Apply import', 'jpkcom-allow-blocks' ); ?></button>
                <a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html__( 'Cancel', 'jpkcom-allow-blocks' ); ?></a>
            </form>
        </div>
    </body>
    </html>
    <?php
    exit;
} );

add_action( 'admin_post_jpkcom_allow_blocks_import_apply', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to import block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_import_apply' );

    $payload = isset( $_POST['payload'] ) && is_string( $_POST['payload'] ) ? wp_unslash( $_POST['payload'] ) : '';
    $parsed  = jpkcom_allow_blocks_parse_import( $payload );

    if ( ! $parsed['ok'] ) {
        wp_die( esc_html( $parsed['error'] ), '', array( 'response' => 400 ) );
    }

    $merged = jpkcom_allow_blocks_merge_import( jpkcom_allow_blocks_get_settings(), $parsed['settings'] );

    jpkcom_allow_blocks_save_settings( $merged );

    wp_safe_redirect( add_query_arg( 'jpkcom-ab-imported', '1', admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() ) ) );
    exit;
} );
