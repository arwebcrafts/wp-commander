<?php
/**
 * WPC_Adapter_Divi — reads and writes Divi shortcode post content.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Adapter_Divi {

    // ── Update an attribute on a Divi module ─────────────────────────────
    public function update_module( int $post_id, string $module_tag, array $changes ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $changed = false;

        // Match the specific module shortcode and update its attributes
        $pattern = '/\[(' . preg_quote( $module_tag, '/' ) . ')([^\]]*)\]/';

        $new_content = preg_replace_callback( $pattern, function ( array $m ) use ( $changes, &$changed ) {
            $tag    = $m[1];
            $attrs  = $m[2];
            $changed = true;

            foreach ( $changes as $attr_key => $attr_value ) {
                $attr_key   = sanitize_key( $attr_key );
                $attr_value = addslashes( sanitize_text_field( $attr_value ) );

                // Replace existing attribute value
                if ( preg_match( '/' . preg_quote( $attr_key, '/' ) . '=["\'][^"\']*["\']/', $attrs ) ) {
                    $attrs = preg_replace(
                        '/' . preg_quote( $attr_key, '/' ) . '=["\'][^"\']*["\']/',
                        $attr_key . '="' . $attr_value . '"',
                        $attrs
                    );
                } else {
                    // Append new attribute
                    $attrs .= ' ' . $attr_key . '="' . $attr_value . '"';
                }
            }

            return '[' . $tag . $attrs . ']';
        }, $content );

        if ( ! $changed || $new_content === $content ) return false;

        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Update a specific attribute across all modules ───────────────────
    public function update_attribute_global( int $post_id, string $attr_key, string $old_value, string $new_value ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $attr_key  = sanitize_key( $attr_key );
        $old_value = sanitize_text_field( $old_value );
        $new_value = sanitize_text_field( $new_value );

        // Replace attribute value across all shortcodes
        $new_content = preg_replace(
            '/' . preg_quote( $attr_key, '/' ) . '=["\']' . preg_quote( $old_value, '/' ) . '["\']/',
            $attr_key . '="' . addslashes( $new_value ) . '"',
            $content
        );

        if ( $new_content === $content ) return false;

        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Extract all unique colors used in Divi content ───────────────────
    public function extract_colors( int $post_id ): array {
        $content = get_post_field( 'post_content', $post_id );
        $colors  = [];

        preg_match_all( '/(?:background_color|text_color|button_bg_color|button_text_color|custom_button_bg_color)=["\']([^"\']+)["\']/', $content, $matches );

        foreach ( $matches[1] as $color ) {
            if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) {
                $colors[] = strtolower( $color );
            }
        }

        return array_unique( $colors );
    }

    // ── Get list of Divi modules on a page ───────────────────────────────
    public function get_modules( int $post_id ): array {
        $content = get_post_field( 'post_content', $post_id );
        $modules = [];

        preg_match_all( '/\[et_pb_(\w+)/', $content, $matches );

        foreach ( $matches[1] as $module ) {
            $modules[] = 'et_pb_' . $module;
        }

        return array_unique( $modules );
    }
}
