<?php
/**
 * WPC_Adapter_Gutenberg — modifies Gutenberg block content.
 * Uses parse_blocks() / serialize_blocks() to update attributes.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Adapter_Gutenberg {

    // ── Update post content (full replacement) ───────────────────────────
    public function update_content( int $post_id, string $new_content ): bool {
        $result = wp_update_post( [
            'ID'           => $post_id,
            'post_content' => $new_content,
        ], true );

        return ! is_wp_error( $result );
    }

    // ── Update a specific block's attribute ──────────────────────────────
    public function update_block_attribute( int $post_id, string $block_name, string $attr_key, mixed $attr_value ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $blocks  = parse_blocks( $content );
        $changed = false;

        $blocks = $this->walk_blocks( $blocks, function ( array $block ) use ( $block_name, $attr_key, $attr_value, &$changed ) {
            if ( $block['blockName'] === $block_name ) {
                $block['attrs'][ $attr_key ] = $attr_value;
                $changed = true;
            }
            return $block;
        } );

        if ( ! $changed ) return false;

        return $this->update_content( $post_id, serialize_blocks( $blocks ) );
    }

    // ── Update block by className ────────────────────────────────────────
    public function update_block_by_class( int $post_id, string $class_name, string $attr_key, mixed $attr_value ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $blocks  = parse_blocks( $content );
        $changed = false;

        $blocks = $this->walk_blocks( $blocks, function ( array $block ) use ( $class_name, $attr_key, $attr_value, &$changed ) {
            $classes = $block['attrs']['className'] ?? '';
            if ( strpos( $classes, $class_name ) !== false ) {
                $block['attrs'][ $attr_key ] = $attr_value;
                $changed = true;
            }
            return $block;
        } );

        if ( ! $changed ) return false;

        return $this->update_content( $post_id, serialize_blocks( $blocks ) );
    }

    // ── Replace text in all paragraph/heading blocks ─────────────────────
    public function replace_text( int $post_id, string $search, string $replace ): bool {
        $content = get_post_field( 'post_content', $post_id );
        if ( empty( $content ) ) return false;

        $search  = esc_html( $search );
        $replace = wp_kses_post( $replace );
        $new     = str_replace( $search, $replace, $content );

        if ( $new === $content ) return false;

        return $this->update_content( $post_id, $new );
    }

    // ── Add a block to the end of a page ────────────────────────────────
    public function append_block( int $post_id, string $block_markup ): bool {
        $content = get_post_field( 'post_content', $post_id );
        return $this->update_content( $post_id, $content . "\n" . $block_markup );
    }

    // ── Prepend a block to the beginning ────────────────────────────────
    public function prepend_block( int $post_id, string $block_markup ): bool {
        $content = get_post_field( 'post_content', $post_id );
        return $this->update_content( $post_id, $block_markup . "\n" . $content );
    }

    // ── Recursive block walker ───────────────────────────────────────────
    private function walk_blocks( array $blocks, callable $callback ): array {
        return array_map( function ( array $block ) use ( $callback ) {
            $block = $callback( $block );
            if ( ! empty( $block['innerBlocks'] ) ) {
                $block['innerBlocks'] = $this->walk_blocks( $block['innerBlocks'], $callback );
            }
            return $block;
        }, $blocks );
    }
}
