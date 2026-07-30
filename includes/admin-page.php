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
     * explicitly asked for. A slug the store would refuse (anything
     * `sanitize_key()` would change, e.g. a custom role registered with
     * upper case or spaces) is never offered either: the UI must not put a
     * column on screen whose ticks `jpkcom_allow_blocks_sanitize_settings()`
     * would silently discard on save.
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

            if ( sanitize_key( $slug ) !== $slug ) {
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

if ( ! function_exists( function: 'jpkcom_allow_blocks_render_import_preview' ) ) {

    /**
     * Render the pending import preview, if a valid one exists.
     *
     * Reads the token the preview handler put in the `jpkcom-ab-import` query
     * argument, fetches the parsed settings it stashed in a transient under
     * that token, and renders a confirmation block inside the caller's
     * `.wrap` - never a standalone document, so the import flow never leaves
     * the admin chrome. A missing or expired transient renders an
     * explanatory notice instead and the normal screen still renders
     * underneath it.
     *
     * @since 3.0.0
     *
     * @return void
     */
    function jpkcom_allow_blocks_render_import_preview(): void {
        $token = isset( $_GET['jpkcom-ab-import'] ) && is_string( $_GET['jpkcom-ab-import'] )
            ? sanitize_text_field( wp_unslash( $_GET['jpkcom-ab-import'] ) )
            : '';

        if ( '' === $token ) {
            return;
        }

        $stashed = get_transient( 'jpkcom_allow_blocks_import_' . $token );

        if ( ! is_array( $stashed ) || ! isset( $stashed['settings'] ) || ! is_array( $stashed['settings'] ) ) {
            ?>
            <div class="notice notice-warning">
                <p><?php echo esc_html__( 'The import preview has expired. Please upload the file again.', 'jpkcom-allow-blocks' ); ?></p>
            </div>
            <?php
            return;
        }

        $incoming = $stashed['settings'];
        $rejected = isset( $stashed['rejected'] ) ? (int) $stashed['rejected'] : 0;

        $current      = jpkcom_allow_blocks_get_settings();
        $known_roles  = array_keys( jpkcom_allow_blocks_editable_roles( true ) );
        $known_blocks = array_keys( WP_Block_Type_Registry::get_instance()->get_all_registered() );
        $preview      = jpkcom_allow_blocks_import_preview( $current, $incoming, $known_roles, $known_blocks );
        $back_url     = admin_url( 'themes.php?page=' . jpkcom_allow_blocks_menu_slug() );

        ?>
        <div class="jpkcom-ab-import-preview">
            <h2><?php echo esc_html__( 'Import preview', 'jpkcom-allow-blocks' ); ?></h2>

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

            <?php if ( $rejected > 0 ) : ?>
                <div class="notice notice-warning">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of invalid entries found in the imported file. */
                                _n(
                                    '%d entry in the file was invalid and will be ignored.',
                                    '%d entries in the file were invalid and will be ignored.',
                                    $rejected,
                                    'jpkcom-allow-blocks'
                                ),
                                $rejected
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

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

            <form method="post" action="admin-post.php" class="jpkcom-ab-import-apply-form">
                <?php wp_nonce_field( 'jpkcom_allow_blocks_import_apply', 'jpkcom_allow_blocks_apply_nonce' ); ?>
                <input type="hidden" name="action" value="jpkcom_allow_blocks_import_apply" />
                <input type="hidden" name="token" value="<?php echo esc_attr( $token ); ?>" />
                <button type="submit" class="button button-primary"><?php echo esc_html__( 'Apply import', 'jpkcom-allow-blocks' ); ?></button>
                <a class="button" href="<?php echo esc_url( $back_url ); ?>"><?php echo esc_html__( 'Cancel', 'jpkcom-allow-blocks' ); ?></a>
            </form>
        </div>
        <?php
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_import_error_message' ) ) {

    /**
     * Translated message for a known import error code.
     *
     * The error travels through the query string as a short code rather than
     * free text, so a crafted link cannot put arbitrary words in front of an
     * administrator. A code this function does not recognise is ignored -
     * the caller shows no notice at all rather than falling back to
     * something generic.
     *
     * @since 3.0.0
     *
     * @param string $code Error code produced by includes/import-export.php.
     * @return string Translated message, or '' when the code is unknown.
     */
    function jpkcom_allow_blocks_import_error_message( string $code ): string {
        $messages = array(
            'no-file'      => __( 'No file was uploaded.', 'jpkcom-allow-blocks' ),
            'unverified'   => __( 'The uploaded file could not be verified.', 'jpkcom-allow-blocks' ),
            'too-large'    => __( 'The file is larger than the 1 MB limit.', 'jpkcom-allow-blocks' ),
            'unreadable'   => __( 'The file could not be read.', 'jpkcom-allow-blocks' ),
            'invalid-json' => __( 'The file is not valid JSON.', 'jpkcom-allow-blocks' ),
            'bad-schema'   => __( 'This file is not a recognised block permissions export.', 'jpkcom-allow-blocks' ),
            'no-roles'     => __( 'The file has no roles to import.', 'jpkcom-allow-blocks' ),
            'expired'      => __( 'The import preview has expired. Please upload the file again.', 'jpkcom-allow-blocks' ),
        );

        return $messages[ $code ] ?? '';
    }

}

if ( ! function_exists( function: 'jpkcom_allow_blocks_render_page' ) ) {

    /**
     * Render the settings screen.
     *
     * Emits the controls, the save form with its checkbox matrix, and the
     * import/export section, in that order. A checkbox is checked when the
     * block is not blocked for that role, i.e. it reflects the allow list
     * rather than the stored deny list.
     *
     * @since 3.0.0
     *
     * @return void
     */
    function jpkcom_allow_blocks_render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $include_non_editing = isset( $_GET['jpkcom-ab-show-all-roles'] ) && is_string( $_GET['jpkcom-ab-show-all-roles'] )
            && '1' === wp_unslash( $_GET['jpkcom-ab-show-all-roles'] );

        $settings = jpkcom_allow_blocks_get_settings();
        $roles    = jpkcom_allow_blocks_editable_roles( $include_non_editing );
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

            <?php if ( isset( $_GET['jpkcom-ab-imported'] ) ) : ?>
                <div class="notice notice-success is-dismissible">
                    <p><?php echo esc_html__( 'Block permissions imported.', 'jpkcom-allow-blocks' ); ?></p>
                </div>
            <?php endif; ?>

            <?php
            $rejected_count = isset( $_GET['jpkcom-ab-rejected'] ) && is_string( $_GET['jpkcom-ab-rejected'] )
                ? absint( wp_unslash( $_GET['jpkcom-ab-rejected'] ) )
                : 0;
            ?>
            <?php if ( $rejected_count > 0 ) : ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <?php
                        echo esc_html(
                            sprintf(
                                /* translators: %d: number of invalid entries found in the imported file. */
                                _n(
                                    '%d entry in the file was invalid and was ignored.',
                                    '%d entries in the file were invalid and were ignored.',
                                    $rejected_count,
                                    'jpkcom-allow-blocks'
                                ),
                                $rejected_count
                            )
                        );
                        ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php
            $import_error_code    = isset( $_GET['jpkcom-ab-import-error'] ) && is_string( $_GET['jpkcom-ab-import-error'] )
                ? sanitize_key( wp_unslash( $_GET['jpkcom-ab-import-error'] ) )
                : '';
            $import_error_message = '' !== $import_error_code ? jpkcom_allow_blocks_import_error_message( $import_error_code ) : '';
            ?>
            <?php if ( '' !== $import_error_message ) : ?>
                <div class="notice notice-error is-dismissible">
                    <p><?php echo esc_html( $import_error_message ); ?></p>
                </div>
            <?php endif; ?>

            <?php jpkcom_allow_blocks_render_import_preview(); ?>

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

                <form method="get" class="jpkcom-ab-roles-toggle-form">
                    <input type="hidden" name="page" value="<?php echo esc_attr( jpkcom_allow_blocks_menu_slug() ); ?>" />
                    <label class="jpkcom-ab-roles-toggle">
                        <input type="checkbox" id="jpkcom-ab-show-all-roles" name="jpkcom-ab-show-all-roles" value="1" <?php checked( $include_non_editing ); ?> />
                        <?php echo esc_html__( 'Show roles that cannot edit posts', 'jpkcom-allow-blocks' ); ?>
                    </label>
                </form>

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
                <input type="hidden" name="include_non_editing" value="<?php echo esc_attr( $include_non_editing ? '1' : '0' ); ?>" />

                <?php submit_button( __( 'Save changes', 'jpkcom-allow-blocks' ), 'primary', 'jpkcom-ab-save-top' ); ?>

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
                                        <label class="jpkcom-ab-forget">
                                            <input type="checkbox" name="forget[<?php echo esc_attr( $row['name'] ); ?>]" value="1" />
                                            <?php echo esc_html__( 'Forget this block', 'jpkcom-allow-blocks' ); ?>
                                        </label>
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

                <?php submit_button( __( 'Save changes', 'jpkcom-allow-blocks' ), 'primary', 'jpkcom-ab-save-bottom' ); ?>
            </form>

            <h2><?php echo esc_html__( 'Import and export', 'jpkcom-allow-blocks' ); ?></h2>

            <div class="jpkcom-ab-import-export">
                <form method="post" action="admin-post.php" class="jpkcom-ab-export-form jpkcom-ab-io-row">
                    <?php wp_nonce_field( 'jpkcom_allow_blocks_export', 'jpkcom_allow_blocks_export_nonce' ); ?>
                    <input type="hidden" name="action" value="jpkcom_allow_blocks_export" />
                    <button type="submit" class="button"><?php echo esc_html__( 'Export', 'jpkcom-allow-blocks' ); ?></button>
                </form>

                <form method="post" action="admin-post.php" enctype="multipart/form-data" class="jpkcom-ab-import-form jpkcom-ab-io-row">
                    <?php wp_nonce_field( 'jpkcom_allow_blocks_import_preview', 'jpkcom_allow_blocks_import_nonce' ); ?>
                    <input type="hidden" name="action" value="jpkcom_allow_blocks_import_preview" />
                    <label for="jpkcom-ab-import-file" class="screen-reader-text"><?php echo esc_html__( 'Block permissions file', 'jpkcom-allow-blocks' ); ?></label>
                    <input type="file" id="jpkcom-ab-import-file" name="jpkcom_allow_blocks_file" accept="application/json,.json" required="required" />
                    <button type="submit" class="button"><?php echo esc_html__( 'Import…', 'jpkcom-allow-blocks' ); ?></button>
                </form>
            </div>
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
     * An unregistered row can be dropped for good in two ways: ticking
     * `forget[<name>]` removes it from **every role the stored settings
     * currently know about** and from `labels` unconditionally, regardless
     * of the per-role checkboxes on the same row and regardless of whether
     * a given role was even rendered this save (see below); and, even
     * without ticking it, a row that ends up blocked by no role at all
     * after this save has its label dropped automatically once the block
     * is not registered - a name nobody blocks and nothing registers has
     * no reason to persist. A registered block's label is always refreshed
     * from its current title, never from the block name: a block without a
     * real title in its registration is not given one here either.
     *
     * Forget deliberately purges more broadly than the checkbox diff does.
     * The checkbox diff below is scoped to `$roles` because that has to
     * match exactly what was rendered - see the parameter doc. But "forget
     * this block" is a stronger promise than "uncheck every box I can see":
     * a role hidden by the "show roles without edit_posts" toggle can still
     * block the same name, and if forget only cleared the rendered roles,
     * the name would keep blocking silently in the hidden role while
     * disappearing from `labels` - unregistered, unreachable, and still
     * growing the option, the exact failure this mechanism exists to close.
     *
     * `$rendered` is only deduplicated here, not validated: an invalid name
     * moving through this function does no harm because
     * {@see jpkcom_allow_blocks_sanitize_settings()} always filters the role
     * lists and labels it returns against the block-name grammar, so a
     * second filter pass here would be provably redundant rather than a
     * second line of defence.
     *
     * @since 3.0.0
     *
     * @param array                              $settings Current validated settings.
     * @param string[]                            $rendered Block names the form rendered.
     * @param array<string,array<string,mixed>>   $allowed  Ticked boxes, keyed role then block name.
     * @param array<string,mixed>                 $forget   Rows whose "forget this block" box was ticked, keyed by block name.
     * @param string[]|null                       $roles    Role slugs the checkbox diff processes, matching what was
     *                                                       rendered. Defaults to every editable role, including ones
     *                                                       that cannot edit posts. Does not limit the forget purge,
     *                                                       which always reaches every role already present in
     *                                                       `$settings['roles']` in addition to these.
     * @return array New settings, not yet stored.
     */
    function jpkcom_allow_blocks_apply_form( array $settings, array $rendered, array $allowed, array $forget = array(), ?array $roles = null ): array {
        $rendered = array_values( array_unique( $rendered ) );
        $roles    = $roles ?? array_keys( jpkcom_allow_blocks_editable_roles( true ) );

        $forgotten = array_values(
            array_intersect(
                array_filter( array_keys( $forget ), 'jpkcom_allow_blocks_is_valid_block_name' ),
                $rendered
            )
        );

        foreach ( $roles as $role ) {
            $previous = $settings['roles'][ $role ] ?? array();
            $kept     = array_values( array_diff( $previous, $rendered ) );
            $ticked   = $allowed[ $role ] ?? array();

            foreach ( $rendered as $name ) {
                if ( in_array( $name, $forgotten, true ) ) {
                    continue;
                }

                if ( ! isset( $ticked[ $name ] ) ) {
                    $kept[] = $name;
                }
            }

            $settings['roles'][ $role ] = array_values( array_unique( $kept ) );
        }

        /*
         * A forgotten block is purged from every role the stored settings
         * currently know about, not only the roles this save's checkbox
         * diff covers: the toggle that hides roles without `edit_posts`
         * means $roles can legitimately be a subset of what is actually
         * stored (e.g. `subscriber` blocks the same name but is not
         * rendered). Ticking "forget" is a promise to remove the name
         * everywhere, so leaving it behind in an unrendered role would
         * silently reintroduce the exact stale-row problem this exists to
         * close: the name resurfaces as unregistered but unreachable
         * because no rendered row still names it as blocked.
         */
        if ( array() !== $forgotten ) {
            foreach ( array_unique( array_merge( array_keys( $settings['roles'] ), $roles ) ) as $role ) {
                $settings['roles'][ $role ] = array_values( array_diff( $settings['roles'][ $role ] ?? array(), $forgotten ) );
            }
        }

        $registered = WP_Block_Type_Registry::get_instance()->get_all_registered();

        foreach ( $rendered as $name ) {
            if ( in_array( $name, $forgotten, true ) ) {
                unset( $settings['labels'][ $name ] );
                continue;
            }

            if ( isset( $registered[ $name ] ) ) {
                $title = (string) ( $registered[ $name ]->title ?? '' );

                if ( '' !== $title ) {
                    $settings['labels'][ $name ] = $title;
                }

                continue;
            }

            if ( ! isset( $settings['labels'][ $name ] ) ) {
                continue;
            }

            $still_blocked = false;

            foreach ( $settings['roles'] as $blocked ) {
                if ( in_array( $name, $blocked, true ) ) {
                    $still_blocked = true;
                    break;
                }
            }

            if ( ! $still_blocked ) {
                unset( $settings['labels'][ $name ] );
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

    $forget = isset( $_POST['forget'] ) && is_array( $_POST['forget'] )
        ? wp_unslash( $_POST['forget'] )
        : array();

    /*
     * The role set used to compute the diff must match what was rendered
     * exactly, or saving would drop settings for roles the form never
     * showed. The hidden field carries the same value the page was
     * rendered with.
     */
    $include_non_editing = isset( $_POST['include_non_editing'] ) && '1' === $_POST['include_non_editing'];
    $roles                = array_keys( jpkcom_allow_blocks_editable_roles( $include_non_editing ) );

    jpkcom_allow_blocks_save_settings(
        jpkcom_allow_blocks_apply_form(
            jpkcom_allow_blocks_get_settings(),
            $rendered,
            is_array( $allowed ) ? $allowed : array(),
            is_array( $forget ) ? $forget : array(),
            $roles
        )
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
