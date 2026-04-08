<?php
/**
 * WPC_Adapter_Generic — fallback adapter for plain HTML / classic editor posts.
 * Updates post content via wp_update_post().
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Adapter_Generic {

    // ── Update full post content ─────────────────────────────────────────
    public function update( int $post_id, string $new_content ): bool {
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => wp_kses_post( $new_content ),
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Replace a substring in content ──────────────────────────────────
    public function replace_text( int $post_id, string $search, string $replace ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $search  = wp_kses_post( $search );
        $replace = wp_kses_post( $replace );

        $new = str_replace( $search, $replace, $content );
        if ( $new === $content ) return false;

        return $this->update( $post_id, $new );
    }

    // ── Append HTML to content ───────────────────────────────────────────
    public function append( int $post_id, string $html ): bool {
        $content = get_post_field( 'post_content', $post_id );
        return $this->update( $post_id, $content . "\n" . wp_kses_post( $html ) );
    }

    // ── Prepend HTML to content ──────────────────────────────────────────
    public function prepend( int $post_id, string $html ): bool {
        $content = get_post_field( 'post_content', $post_id );
        return $this->update( $post_id, wp_kses_post( $html ) . "\n" . $content );
    }

    // ── Update post title ────────────────────────────────────────────────
    public function update_title( int $post_id, string $new_title ): bool {
        $result = wp_update_post( [
            'ID'         => $post_id,
            'post_title' => sanitize_text_field( $new_title ),
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Update post excerpt ──────────────────────────────────────────────
    public function update_excerpt( int $post_id, string $new_excerpt ): bool {
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_excerpt' => sanitize_textarea_field( $new_excerpt ),
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Update a custom field (post meta) ───────────────────────────────
    public function update_meta( int $post_id, string $meta_key, mixed $meta_value ): bool {
        $meta_key = sanitize_key( $meta_key );
        if ( empty( $meta_key ) ) return false;

        // Prevent writing to sensitive meta keys
        $blocked = [ '_edit_lock', '_edit_last', '_wp_trash_meta_status', 'user_pass', 'user_email' ];
        if ( in_array( $meta_key, $blocked, true ) ) return false;

        update_post_meta( $post_id, $meta_key, $meta_value );
        return true;
    }
}
