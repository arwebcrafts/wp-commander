<?php
/**
 * WPC_Adapter_Elementor — reads and writes Elementor _elementor_data post meta.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Adapter_Elementor {

    // ── Update a widget setting ──────────────────────────────────────────
    public function update_widget_setting( int $post_id, string $widget_id, string $setting_key, mixed $value ): bool {
        $raw  = get_post_meta( $post_id, '_elementor_data', true );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) return false;

        $found = false;
        $data  = $this->find_and_update( $data, $widget_id, $setting_key, $value, $found );

        if ( ! $found ) return false;

        update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
        $this->clear_elementor_cache( $post_id );

        return true;
    }

    // ── Update all widgets of a given type ───────────────────────────────
    public function update_widgets_by_type( int $post_id, string $widget_type, string $setting_key, mixed $value ): int {
        $raw  = get_post_meta( $post_id, '_elementor_data', true );
        $data = json_decode( $raw, true );

        if ( ! is_array( $data ) ) return 0;

        $count = 0;
        $data  = $this->walk_and_update_type( $data, $widget_type, $setting_key, $value, $count );

        if ( $count > 0 ) {
            update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
            $this->clear_elementor_cache( $post_id );
        }

        return $count;
    }

    // ── Get a widget's current settings ─────────────────────────────────
    public function get_widget_settings( int $post_id, string $widget_id ): array {
        $raw  = get_post_meta( $post_id, '_elementor_data', true );
        $data = json_decode( $raw, true );
        if ( ! is_array( $data ) ) return [];
        return $this->find_widget( $data, $widget_id );
    }

    // ── Recursive: find widget by ID and update setting ──────────────────
    private function find_and_update( array $elements, string $widget_id, string $setting_key, mixed $value, bool &$found ): array {
        foreach ( $elements as &$element ) {
            if ( ( $element['id'] ?? '' ) === $widget_id ) {
                $element['settings'][ sanitize_key( $setting_key ) ] = $value;
                $found = true;
                continue;
            }
            if ( ! empty( $element['elements'] ) ) {
                $element['elements'] = $this->find_and_update( $element['elements'], $widget_id, $setting_key, $value, $found );
            }
        }
        return $elements;
    }

    // ── Recursive: update all widgets of a type ──────────────────────────
    private function walk_and_update_type( array $elements, string $type, string $key, mixed $value, int &$count ): array {
        foreach ( $elements as &$element ) {
            if ( ( $element['widgetType'] ?? '' ) === $type ) {
                $element['settings'][ sanitize_key( $key ) ] = $value;
                $count++;
            }
            if ( ! empty( $element['elements'] ) ) {
                $element['elements'] = $this->walk_and_update_type( $element['elements'], $type, $key, $value, $count );
            }
        }
        return $elements;
    }

    // ── Find widget by ID, return settings ───────────────────────────────
    private function find_widget( array $elements, string $widget_id ): array {
        foreach ( $elements as $element ) {
            if ( ( $element['id'] ?? '' ) === $widget_id ) {
                return $element['settings'] ?? [];
            }
            if ( ! empty( $element['elements'] ) ) {
                $result = $this->find_widget( $element['elements'], $widget_id );
                if ( $result ) return $result;
            }
        }
        return [];
    }

    // ── Clear Elementor cache ────────────────────────────────────────────
    private function clear_elementor_cache( int $post_id ): void {
        // Clear post-specific cache
        delete_post_meta( $post_id, '_elementor_css' );

        if ( class_exists( '\Elementor\Plugin' ) ) {
            try {
                \Elementor\Plugin::$instance->files_manager->clear_cache();
            } catch ( \Throwable $e ) {
                // Silently fail if Elementor not fully loaded
            }
        }

        // Also clear via filter for Elementor 3.x
        if ( function_exists( 'elementor_clear_css_cache' ) ) {
            elementor_clear_css_cache();
        }
    }
}
