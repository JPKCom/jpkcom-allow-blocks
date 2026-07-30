<?php
/**
 * Settings screen
 *
 * A matrix of every known block against every editable role, rendered under
 * Appearance. Read-only in the markup it emits: submitting the form is
 * handled by the admin-post.php callback registered elsewhere.
 *
 * @package JPKCom_Allow_Blocks
 * @since   3.0.0
 */

declare(strict_types=1);

if ( ! defined( constant_name: 'ABSPATH' ) ) {
    exit;
}

if ( ! function_exists( function: 'jpkcom_allow_blocks_menu_slug' ) ) {

    /**
     * Slug of the settings page.
     *
     * @since 3.0.0
     *
     * @return string Menu slug.
     */
    function jpkcom_allow_blocks_menu_slug(): string {
        return 'jpkcom-allow-blocks';
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_editable_roles' ) ) {

    /**
     * Roles that can be restricted from the settings screen.
     *
     * Administrators are never offered: they always bypass the filter, so a
     * checkbox for them would be a lie. Roles without `edit_posts` never see
     * the block editor, so they are hidden by default and only surfaced when
     * explicitly asked for.
     *
     * @since 3.0.0
     *
     * @param bool $include_non_editing Whether to also include roles that cannot edit posts.
     * @return array<string,string> Role slug to display name.
     */
    function jpkcom_allow_blocks_editable_roles( bool $include_non_editing = false ): array {
        $roles = wp_roles()->roles;
        $out   = array();

        foreach ( $roles as $slug => $role ) {
            if ( 'administrator' === $slug ) {
                continue;
            }

            $can_edit = ! empty( $role['capabilities']['edit_posts'] );

            if ( ! $can_edit && ! $include_non_editing ) {
                continue;
            }

            $out[ $slug ] = $role['name'] ?? $slug;
        }

        return $out;
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_block_rows' ) ) {

    /**
     * Rows for the block matrix.
     *
     * The union of the registered blocks and every block name mentioned in the
     * settings, so a block from a deactivated plugin still shows up instead of
     * silently vanishing from the screen that would otherwise unblock it.
     *
     * @since 3.0.0
     *
     * @param array $settings Validated settings.
     * @return array<int,array{name:string,title:string,category:string,registered:bool}> Rows, sorted by category then title.
     */
    function jpkcom_allow_blocks_block_rows( array $settings ): array {
        $registered = WP_Block_Type_Registry::get_instance()->get_all_registered();

        $names = array_keys( $registered );

        foreach ( $settings['roles'] as $blocked ) {
            $names = array_merge( $names, $blocked );
        }

        $names = array_merge( $names, array_keys( $settings['labels'] ) );
        $names = array_values( array_unique( $names ) );

        $rows = array();

        foreach ( $names as $name ) {
            $is_registered = isset( $registered[ $name ] );

            if ( $is_registered ) {
                $title    = (string) ( $registered[ $name ]->title ?? $name );
                $category = (string) ( $registered[ $name ]->category ?? 'uncategorized' );
                $category = '' === $category ? 'uncategorized' : $category;
            } else {
                $title    = $settings['labels'][ $name ] ?? $name;
                $category = 'jpkcom-unregistered';
            }

            $rows[] = array(
                'name'       => $name,
                'title'      => '' === $title ? $name : $title,
                'category'   => $category,
                'registered' => $is_registered,
            );
        }

        usort(
            $rows,
            static function ( array $a, array $b ): int {
                $by_category = $a['category'] <=> $b['category'];

                if ( 0 !== $by_category ) {
                    return $by_category;
                }

                return $a['title'] <=> $b['title'];
            }
        );

        return $rows;
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_render_page' ) ) {

    /**
     * Render the settings screen.
     *
     * Purely presentational: emits the form and checkbox matrix, but the form
     * has no working submit handler until the admin-post.php action from
     * Task 5 exists. A checkbox is checked when the block is not blocked for
     * that role, i.e. it reflects the allow list rather than the stored deny
     * list.
     *
     * @since 3.0.0
     *
     * @return void
     */
    function jpkcom_allow_blocks_render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = jpkcom_allow_blocks_get_settings();
        $roles    = jpkcom_allow_blocks_editable_roles( true );
        $rows     = jpkcom_allow_blocks_block_rows( $settings );

        $categories = array_values( array_unique( array_column( $rows, 'category' ) ) );
        sort( $categories );

        $blocked_by_role = array();

        foreach ( $roles as $slug => $label ) {
            $blocked_by_role[ $slug ] = jpkcom_allow_blocks_blocked_for_roles( array( $slug ), $settings );
        }

        ?>
        <div class="wrap jpkcom-ab-wrap">
            <h1><?php echo esc_html__( 'Block permissions', 'jpkcom-allow-blocks' ); ?></h1>

            <p><?php echo esc_html__( 'Choose which blocks each role may insert. Administrators always have every block.', 'jpkcom-allow-blocks' ); ?></p>

            <?php if ( isset( $_GET['jpkcom-ab-saved'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Block permissions saved.', 'jpkcom-allow-blocks' ); ?></p>
                </div>
            <?php endif; ?>

            <form method="post" action="admin-post.php" class="jpkcom-ab-export-form">
                <?php wp_nonce_field( 'jpkcom_allow_blocks_export' ); ?>
                <input type="hidden" name="action" value="jpkcom_allow_blocks_export" />
                <button type="submit" class="button"><?php echo esc_html__( 'Export', 'jpkcom-allow-blocks' ); ?></button>
            </form>

            <div class="jpkcom-ab-controls">
                <p class="search-box">
                    <label for="jpkcom-ab-search" class="screen-reader-text"><?php echo esc_html__( 'Search blocks', 'jpkcom-allow-blocks' ); ?></label>
                    <input type="search" id="jpkcom-ab-search" placeholder="<?php echo esc_attr__( 'Search blocks…', 'jpkcom-allow-blocks' ); ?>" />
                </p>

                <p class="jpkcom-ab-category-box">
                    <label for="jpkcom-ab-category"><?php echo esc_html__( 'Category', 'jpkcom-allow-blocks' ); ?></label>
                    <select id="jpkcom-ab-category">
                        <option value=""><?php echo esc_html__( 'All categories', 'jpkcom-allow-blocks' ); ?></option>
                        <?php foreach ( $categories as $category ) : ?>
                            <option value="<?php echo esc_attr( $category ); ?>"><?php echo esc_html( $category ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </p>

                <p class="jpkcom-ab-columns-box">
                    <?php foreach ( $roles as $slug => $label ) : ?>
                        <label class="jpkcom-ab-column-toggle">
                            <input type="checkbox" class="jpkcom-ab-toggle-column" data-role="<?php echo esc_attr( $slug ); ?>" checked="checked" />
                            <?php echo esc_html( $label ); ?>
                        </label>
                    <?php endforeach; ?>
                </p>
            </div>

            <form method="post" action="admin-post.php">
                <?php wp_nonce_field( 'jpkcom_allow_blocks_save', 'jpkcom_allow_blocks_nonce' ); ?>
                <input type="hidden" name="action" value="jpkcom_allow_blocks_save" />

                <table class="widefat striped jpkcom-ab-table">
                    <thead>
                        <tr>
                            <th class="jpkcom-ab-col-title"><?php echo esc_html__( 'Block', 'jpkcom-allow-blocks' ); ?></th>
                            <th class="jpkcom-ab-col-category"><?php echo esc_html__( 'Category', 'jpkcom-allow-blocks' ); ?></th>
                            <?php foreach ( $roles as $slug => $label ) : ?>
                                <th class="jpkcom-ab-col-role" data-role="<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $label ); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ( $rows as $row ) : ?>
                            <tr class="<?php echo $row['registered'] ? '' : 'jpkcom-ab-unregistered'; ?>" data-category="<?php echo esc_attr( $row['category'] ); ?>">
                                <td class="jpkcom-ab-col-title">
                                    <?php echo esc_html( $row['title'] ); ?>
                                    <code><?php echo esc_html( $row['name'] ); ?></code>
                                    <?php if ( ! $row['registered'] ) : ?>
                                        <span class="jpkcom-ab-warning" title="<?php echo esc_attr__( 'Not currently registered on this site.', 'jpkcom-allow-blocks' ); ?>">
                                            <?php echo esc_html__( 'Unregistered', 'jpkcom-allow-blocks' ); ?>
                                        </span>
                                    <?php endif; ?>
                                    <input type="hidden" name="rendered[]" value="<?php echo esc_attr( $row['name'] ); ?>" />
                                </td>
                                <td class="jpkcom-ab-col-category"><?php echo esc_html( $row['category'] ); ?></td>
                                <?php foreach ( $roles as $slug => $label ) : ?>
                                    <?php $is_allowed = ! in_array( $row['name'], $blocked_by_role[ $slug ], true ); ?>
                                    <td class="jpkcom-ab-col-role" data-role="<?php echo esc_attr( $slug ); ?>">
                                        <label class="screen-reader-text" for="jpkcom-ab-<?php echo esc_attr( $slug . '-' . $row['name'] ); ?>">
                                            <?php
                                            /* translators: 1: block title, 2: role name. */
                                            echo esc_html( sprintf( __( 'Allow %1$s for %2$s', 'jpkcom-allow-blocks' ), $row['title'], $label ) );
                                            ?>
                                        </label>
                                        <input
                                            type="checkbox"
                                            id="jpkcom-ab-<?php echo esc_attr( $slug . '-' . $row['name'] ); ?>"
                                            name="allowed[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $row['name'] ); ?>]"
                                            value="1"
                                            <?php checked( $is_allowed ); ?>
                                        />
                                    </td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </form>
        </div>
        <?php
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_apply_form' ) ) {

    /**
     * Compute new settings from a settings-screen submission.
     *
     * The difference is taken over the rendered rows only: names the form did
     * not show keep whatever they had. A save while the table is filtered must
     * not wipe the rows that were scrolled out of view.
     *
     * @since 3.0.0
     *
     * @param array                        $settings Current validated settings.
     * @param string[]                     $rendered Block names the form rendered.
     * @param array<string,array<string,mixed>> $allowed Ticked boxes, keyed role then block name.
     * @return array New settings, not yet stored.
     */
    function jpkcom_allow_blocks_apply_form( array $settings, array $rendered, array $allowed ): array {
        $rendered = array_values( array_unique( array_filter( $rendered, 'jpkcom_allow_blocks_is_valid_block_name' ) ) );
        $rows     = array_column( jpkcom_allow_blocks_block_rows( $settings ), null, 'name' );

        foreach ( array_keys( jpkcom_allow_blocks_editable_roles( true ) ) as $role ) {
            $previous = $settings['roles'][ $role ] ?? array();
            $kept     = array_values( array_diff( $previous, $rendered ) );
            $ticked   = $allowed[ $role ] ?? array();

            foreach ( $rendered as $name ) {
                if ( ! isset( $ticked[ $name ] ) ) {
                    $kept[] = $name;
                }
            }

            $settings['roles'][ $role ] = array_values( array_unique( $kept ) );
        }

        foreach ( $rendered as $name ) {
            $title = $rows[ $name ]['title'] ?? $name;

            if ( is_string( $title ) && '' !== $title ) {
                $settings['labels'][ $name ] = $title;
            }
        }

        return jpkcom_allow_blocks_sanitize_settings( $settings );
    }

}

add_action( 'admin_post_jpkcom_allow_blocks_save', static function (): void {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'You are not allowed to change block permissions.', 'jpkcom-allow-blocks' ), '', array( 'response' => 403 ) );
    }

    check_admin_referer( 'jpkcom_allow_blocks_save', 'jpkcom_allow_blocks_nonce' );

    $rendered = isset( $_POST['rendered'] ) && is_array( $_POST['rendered'] )
        ? array_map( 'sanitize_text_field', wp_unslash( $_POST['rendered'] ) )
        : array();

    $allowed = isset( $_POST['allowed'] ) && is_array( $_POST['allowed'] )
        ? wp_unslash( $_POST['allowed'] )
        : array();

    jpkcom_allow_blocks_save_settings(
        jpkcom_allow_blocks_apply_form( jpkcom_allow_blocks_get_settings(), $rendered, is_array( $allowed ) ? $allowed : array() )
    );

    wp_safe_redirect( add_query_arg( 'jpkcom-ab-saved', '1', admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() ) ) );
    exit;
} );

add_action( 'admin_menu', static function (): void {
    $hook = add_submenu_page(
        'themes.php',
        __( 'Block permissions', 'jpkcom-allow-blocks' ),
        __( 'Block permissions', 'jpkcom-allow-blocks' ),
        'manage_options',
        jpkcom_allow_blocks_menu_slug(),
        'jpkcom_allow_blocks_render_page'
    );

    if ( ! $hook ) {
        return;
    }

    add_action( 'admin_print_styles-' . $hook, static function (): void {
        wp_enqueue_style(
            'jpkcom-allow-blocks-admin',
            plugins_url( 'assets/admin.css', JPKCOM_ALLOW_BLOCKS_PATH . 'jpkcom-allow-blocks.php' ),
            array(),
            JPKCOM_ALLOW_BLOCKS_VERSION
        );
        wp_enqueue_script(
            'jpkcom-allow-blocks-admin',
            plugins_url( 'assets/admin.js', JPKCOM_ALLOW_BLOCKS_PATH . 'jpkcom-allow-blocks.php' ),
            array(),
            JPKCOM_ALLOW_BLOCKS_VERSION,
            true
        );
    } );
} );
