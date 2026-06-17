<?php
/*
Plugin Name: JPKCom Allow Block Types
Plugin URI: https://github.com/JPKCom/jpkcom-allow-blocks
Description: Only allow certain types of blocks in Gutenberg for non admins.
Version: 2.0.3
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Admin, Block, Bootstrap, Editor, Gutenberg
Requires at least: 6.9
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 2.0.3
License: GPL-2.0-or-later
License URI: https://www.gnu.org/licenses/gpl-2.0.html
*/

declare(strict_types=1);

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}


/**
 * Plugin Constants
 *
 * @since 2.0.3
 */
if ( ! defined( 'JPKCOM_ALLOW_BLOCKS_VERSION' ) ) {
    define( 'JPKCOM_ALLOW_BLOCKS_VERSION', '2.0.3' );
}


/**
 * Initialize Plugin Updater
 *
 * Loads and initializes the GitHub-based plugin updater with SHA256 checksum verification.
 *
 * @since 2.0.3
 *
 * @return void
 */
add_action( 'init', static function (): void {
    $updater_file = plugin_dir_path( __FILE__ ) . 'includes/class-plugin-updater.php';

    if ( file_exists( $updater_file ) ) {
        require_once $updater_file;

        if ( class_exists( 'JPKComAllowBlocksGitUpdate\\JPKComGitPluginUpdater' ) ) {
            new \JPKComAllowBlocksGitUpdate\JPKComGitPluginUpdater(
                plugin_file: __FILE__,
                current_version: JPKCOM_ALLOW_BLOCKS_VERSION,
                manifest_url: 'https://jpkcom.github.io/jpkcom-allow-blocks/plugin_jpkcom-allow-blocks.json'
            );
        }
    }
}, 5 );

// https://developer.wordpress.org/block-editor/reference-guides/core-blocks/


if ( ! is_admin() ) {

    if ( ! function_exists( function: 'jpkcom_allowed_block_types' ) ) {

        /**
         * Restrict the editor to a curated list of allowed block types.
         *
         * @since 1.0.0
         *
         * @param bool|string[]            $allowed_block_types  Array of allowed block types, or boolean to enable/disable all.
         * @param \WP_Block_Editor_Context $block_editor_context The current block editor context.
         * @return string[] Allowed block type names.
         */
        function jpkcom_allowed_block_types( $allowed_block_types, $block_editor_context ): array {

            $allowed_block_types = array(
                'areoi/accordion-item',
                'areoi/accordion',
                'areoi/button',
                'areoi/carousel-item',
                'areoi/carousel',
                'areoi/column-break',
                'areoi/column',
                'areoi/container',
                'areoi/div',
                'areoi/row',
                'areoi/strip',
                'core/gallery',
                'core/heading',
                'core/image',
                'core/list-item',
                'core/list',
                'core/paragraph',
            );

            return $allowed_block_types;
        }

    }

    add_filter( 'allowed_block_types_all', 'jpkcom_allowed_block_types', 10, 2 );

}
