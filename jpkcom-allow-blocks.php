<?php
/*
Plugin Name: JPKCom Allow Block Types
Description: Only allow certain types of blocks in Gutenberg for non admins.
Version: 1.0.1
Author: Jean Pierre Kolb <jpk@jpkc.com>
Author URI: https://www.jpkc.com
GitHub Plugin URI: https://github.com/JPKCom/jpkcom-allow-blocks
*/

// https://developer.wordpress.org/block-editor/reference-guides/core-blocks/


if ( !is_admin() ) {

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

    add_filter( 'allowed_block_types_all', 'jpkcom_allowed_block_types', 10, 2 );

}
