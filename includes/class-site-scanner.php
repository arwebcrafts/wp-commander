<?php
/**
 * WPC_Site_Scanner — detects page builder and builds a page map JSON.
 * Token-efficient: caches scans per post/user to avoid redundant AI context.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Site_Scanner {

    // ── Builder detection ───────────────────────────────────────────────
    public function detect_builder( int $post_id ): string {
        if ( get_post_meta( $post_id, '_elementor_data', true ) )           return 'elementor';
        if ( get_post_meta( $post_id, '_fl_builder_data', true ) )          return 'beaver';
        if ( get_post_meta( $post_id, '_bricks_page_content_2', true ) )    return 'bricks';

        $content = get_post_field( 'post_content', $post_id );
        if ( $content && strpos( $content, '[et_pb_section' ) !== false )   return 'divi';
        if ( $content && has_blocks( $content ) )                           return 'gutenberg';

        return 'generic';
    }

    // ── Main entry: returns cached or fresh page map ─────────────────────
    public function get_page_map( int $post_id ): array {
        // Token efficiency: return cached scan if post hasn't changed
        $cache_key = 'wpc_pagemap_' . $post_id;
        $post_mod  = get_post_modified_time( 'U', true, $post_id );
        $cached    = get_transient( $cache_key );

        if ( $cached && isset( $cached['_modified'] ) && $cached['_modified'] === $post_mod ) {
            return $cached;
        }

        $map = $this->build_page_map( $post_id );
        $map['_modified'] = $post_mod;
        set_transient( $cache_key, $map, 30 * MINUTE_IN_SECONDS );

        return $map;
    }

    // ── Build page map ───────────────────────────────────────────────────
    private function build_page_map( int $post_id ): array {
        $builder = $this->detect_builder( $post_id );
        $post    = get_post( $post_id );

        $map = [
            'post_id'    => $post_id,
            'post_type'  => $post ? $post->post_type : 'unknown',
            'post_title' => $post ? esc_html( $post->post_title ) : '',
            'builder'    => $builder,
            'url'        => get_permalink( $post_id ) ?: '',
            'sections'   => [],
            'theme'      => $this->get_theme_info(),
            'css_vars'   => $this->get_css_variables( $post_id ),
        ];

        switch ( $builder ) {
            case 'elementor':
                $map['sections'] = $this->map_elementor( $post_id );
                break;
            case 'gutenberg':
                $map['sections'] = $this->map_gutenberg( $post_id );
                break;
            case 'divi':
                $map['sections'] = $this->map_divi( $post_id );
                break;
            case 'beaver':
                $map['sections'] = $this->map_beaver( $post_id );
                break;
            default:
                $map['sections'] = $this->map_generic( $post_id );
        }

        return $map;
    }

    // ── Elementor mapper ─────────────────────────────────────────────────
    private function map_elementor( int $post_id ): array {
        $raw  = get_post_meta( $post_id, '_elementor_data', true );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return [];

        $sections = [];
        foreach ( $data as $section ) {
            $sections[] = $this->flatten_elementor_section( $section );
        }
        return $sections;
    }

    private function flatten_elementor_section( array $section ): array {
        $out = [
            'id'       => $section['id'] ?? '',
            'type'     => 'section',
            'settings' => $this->safe_settings( $section['settings'] ?? [] ),
            'columns'  => [],
        ];

        foreach ( $section['elements'] ?? [] as $column ) {
            $col = [ 'id' => $column['id'] ?? '', 'widgets' => [] ];
            foreach ( $column['elements'] ?? [] as $widget ) {
                $col['widgets'][] = [
                    'id'          => $widget['id'] ?? '',
                    'widgetType'  => $widget['widgetType'] ?? '',
                    'settings'    => $this->safe_settings( $widget['settings'] ?? [] ),
                ];
            }
            $out['columns'][] = $col;
        }

        return $out;
    }

    // ── Gutenberg mapper ─────────────────────────────────────────────────
    private function map_gutenberg( int $post_id ): array {
        $content = get_post_field( 'post_content', $post_id );
        $blocks  = parse_blocks( $content );
        return array_map( [ $this, 'flatten_block' ], $blocks );
    }

    private function flatten_block( array $block ): array {
        return [
            'blockName'   => $block['blockName'] ?? '',
            'attrs'       => $block['attrs'] ?? [],
            'innerBlocks' => array_map( [ $this, 'flatten_block' ], $block['innerBlocks'] ?? [] ),
            'innerHTML_preview' => substr( wp_strip_all_tags( $block['innerHTML'] ?? '' ), 0, 120 ),
        ];
    }

    // ── Divi mapper ──────────────────────────────────────────────────────
    private function map_divi( int $post_id ): array {
        $content = get_post_field( 'post_content', $post_id );
        preg_match_all( '/\[et_pb_(\w+)([^\]]*)\]/', $content, $matches );

        $sections = [];
        foreach ( $matches[1] as $i => $module ) {
            $sections[] = [
                'module'  => $module,
                'attrs'   => $this->parse_shortcode_attrs( $matches[2][ $i ] ?? '' ),
            ];
        }
        return $sections;
    }

    private function parse_shortcode_attrs( string $attrs_str ): array {
        $attrs = [];
        preg_match_all( '/(\w+)=["\']([^"\']*)["\']/', $attrs_str, $m );
        foreach ( $m[1] as $i => $key ) {
            $attrs[ $key ] = $m[2][ $i ];
        }
        return $attrs;
    }

    // ── Beaver Builder mapper ────────────────────────────────────────────
    private function map_beaver( int $post_id ): array {
        $data = get_post_meta( $post_id, '_fl_builder_data', true );
        if ( ! is_array( $data ) ) return [];

        $sections = [];
        foreach ( $data as $node_id => $node ) {
            if ( ( $node->type ?? '' ) === 'row' ) {
                $sections[] = [
                    'id'       => $node_id,
                    'type'     => 'row',
                    'settings' => (array) ( $node->settings ?? [] ),
                ];
            }
        }
        return $sections;
    }

    // ── Generic mapper ───────────────────────────────────────────────────
    private function map_generic( int $post_id ): array {
        $content = get_post_field( 'post_content', $post_id );
        return [ [
            'type'    => 'raw_content',
            'preview' => substr( wp_strip_all_tags( $content ), 0, 400 ),
            'length'  => strlen( $content ),
        ] ];
    }

    // ── Theme info ───────────────────────────────────────────────────────
    private function get_theme_info(): array {
        $theme = wp_get_theme();
        return [
            'name'    => esc_html( $theme->get( 'Name' ) ),
            'version' => esc_html( $theme->get( 'Version' ) ),
        ];
    }

    // ── CSS variable extraction ──────────────────────────────────────────
    private function get_css_variables( int $post_id ): array {
        $vars = [];

        // From theme mods
        $primary = get_theme_mod( 'primary_color', '' );
        if ( $primary ) $vars['--primary-color'] = sanitize_hex_color( $primary );

        // From WPC stored global CSS
        $global_css = get_option( 'wpc_global_css', '' );
        if ( $global_css ) {
            preg_match_all( '/--([\w-]+)\s*:\s*([^;]+);/', $global_css, $m );
            foreach ( $m[1] as $i => $var_name ) {
                $vars[ '--' . $var_name ] = trim( $m[2][ $i ] );
            }
        }

        return $vars;
    }

    // ── Sanitize settings for output (avoid leaking sensitive data) ───────
    private function safe_settings( array $settings ): array {
        // Only keep styling-relevant keys; remove content that wastes tokens
        $allowed_keys = [
            'background_color', 'background_image', 'color', 'text_color',
            'button_background_color', 'button_text_color', 'font_size',
            'border_radius', 'padding', 'margin', 'width', 'height',
            'typography_font_family', 'typography_font_size', 'typography_font_weight',
            'title', 'heading_tag', 'align', 'text_align',
            'custom_css_classes', 'custom_id',
        ];

        $safe = [];
        foreach ( $allowed_keys as $key ) {
            if ( isset( $settings[ $key ] ) ) {
                $safe[ $key ] = is_string( $settings[ $key ] )
                    ? substr( $settings[ $key ], 0, 200 )
                    : $settings[ $key ];
            }
        }
        return $safe;
    }

    // ── Invalidate cache after post update ───────────────────────────────
    public static function bust_cache( int $post_id ): void {
        delete_transient( 'wpc_pagemap_' . $post_id );
    }
}

add_action( 'save_post', [ 'WPC_Site_Scanner', 'bust_cache' ] );
