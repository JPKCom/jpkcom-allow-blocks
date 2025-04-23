<?php
/*
Plugin Name: JPKCom Allow Block Types
Plugin URI: https://github.com/JPKCom/jpkcom-allow-blocks
Description: Only allow certain types of blocks in Gutenberg for non admins.
Version: 2.0.2
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
Contributors: JPKCom
Tags: Admin, Block, Bootstrap, Editor, Gutenberg
Requires at least: 6.7
Tested up to: 6.8
Requires PHP: 8.3
Stable tag: 2.0.2
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
GitHub Plugin URI: JPKCom/jpkcom-allow-blocks
Primary Branch: main
*/

if ( ! defined( constant_name: 'WPINC' ) ) {
	die;
}

// https://developer.wordpress.org/block-editor/reference-guides/core-blocks/


if ( !is_admin() ) {

    if ( ! function_exists( function: 'jpkcom_allowed_block_types' ) ) {

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
