<?php
/*
Plugin Name: JPKCom Allow Block Types
Plugin URI: https://github.com/JPKCom/jpkcom-allow-blocks
Description: Only allow certain types of blocks in Gutenberg for non admins.
Version: 3.0.0
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Admin, Block, Bootstrap, Editor, Gutenberg
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.3
Stable tag: 3.0.0
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
    define( 'JPKCOM_ALLOW_BLOCKS_VERSION', '3.0.0' );
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

if ( ! defined( 'JPKCOM_ALLOW_BLOCKS_PATH' ) ) {
    define( constant_name: 'JPKCOM_ALLOW_BLOCKS_PATH', value: plugin_dir_path( __FILE__ ) );
}

/**
 * Load the plugin modules.
 *
 * Loaded on plugins_loaded so the settings store exists before anything reads
 * it, and well before `allowed_block_types_all` is applied when the editor
 * assembles its settings in wp-admin.
 *
 * @since 3.0.0
 *
 * @return void
 */
add_action( 'plugins_loaded', static function (): void {

    $modules = array(
        'includes/settings-store.php',
        'includes/block-filter.php',
    );

    if ( is_admin() ) {
        $modules[] = 'includes/admin-page.php';
        $modules[] = 'includes/import-export.php';
    }

    foreach ( $modules as $module ) {
        $file = JPKCOM_ALLOW_BLOCKS_PATH . $module;

        if ( file_exists( $file ) ) {
            require_once $file;
        }
    }

}, 5 );
