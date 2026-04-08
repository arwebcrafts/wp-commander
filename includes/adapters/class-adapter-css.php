<?php
/**
 * WPC_Adapter_CSS — universal CSS injection adapter.
 * Stores per-page CSS in wp_options, outputs via wp_head.
 * Each rule is tagged with a comment key for undo support.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Adapter_CSS {

    // ── Inject a CSS rule ────────────────────────────────────────────────
    public function inject( int $post_id, string $selector, array $changes, string $undo_key ): bool {
        if ( empty( $selector ) || empty( $changes ) ) {
            return false;
        }

        $selector   = $this->sanitize_selector( $selector );
        $properties = $this->build_css_properties( $changes );

        if ( empty( $properties ) ) {
            return false;
        }

        $rule = sprintf(
            "\n/* WPC-CHANGE-%s */\n%s {\n%s}\n",
            esc_attr( $undo_key ),
            $selector,
            $properties
        );

        // Determine scope: global if selector targets body/root, else per-page
        if ( $this->is_global_selector( $selector ) ) {
            $this->append_global_css( $rule );
        } else {
            $this->append_page_css( $post_id, $rule );
        }

        return true;
    }

    // ── Inject raw CSS (for site generator) ─────────────────────────────
    public function inject_raw( int $post_id, string $css, string $undo_key = '' ): void {
        if ( $undo_key ) {
            $css = "/* WPC-CHANGE-{$undo_key} */\n" . $css;
        }
        $this->append_page_css( $post_id, $css );
    }

    // ── Remove rule by undo key ──────────────────────────────────────────
    public function remove_by_key( int $post_id, string $undo_key ): bool {
        if ( empty( $undo_key ) ) return false;

        $pattern = '/\/\* WPC-CHANGE-' . preg_quote( $undo_key, '/' ) . ' \*\/.*?(?=\/\* WPC-CHANGE-|$)/s';

        // Remove from page CSS
        $key      = 'wpc_custom_css_' . $post_id;
        $page_css = get_option( $key, '' );
        if ( $page_css ) {
            $new_css = preg_replace( $pattern, '', $page_css );
            update_option( $key, $new_css );
        }

        // Remove from global CSS
        $global = get_option( 'wpc_global_css', '' );
        if ( $global ) {
            $new_global = preg_replace( $pattern, '', $global );
            update_option( 'wpc_global_css', $new_global );
        }

        return true;
    }

    // ── Get current page CSS ─────────────────────────────────────────────
    public function get_page_css( int $post_id ): string {
        return (string) get_option( 'wpc_custom_css_' . $post_id, '' );
    }

    // ── Delete all WP Commander CSS ──────────────────────────────────────
    public static function delete_all(): void {
        global $wpdb;
        $wpdb->query( $wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            $wpdb->esc_like( 'wpc_custom_css_' ) . '%'
        ) );
        delete_option( 'wpc_global_css' );
    }

    // ── Append to per-page CSS option ────────────────────────────────────
    private function append_page_css( int $post_id, string $css ): void {
        $key      = 'wpc_custom_css_' . $post_id;
        $existing = (string) get_option( $key, '' );
        update_option( $key, $existing . $css, false );
    }

    // ── Append to global CSS option ──────────────────────────────────────
    private function append_global_css( string $css ): void {
        $existing = (string) get_option( 'wpc_global_css', '' );
        update_option( 'wpc_global_css', $existing . $css, false );
    }

    // ── Build CSS property block ─────────────────────────────────────────
    private function build_css_properties( array $changes ): string {
        $lines = '';
        foreach ( $changes as $prop => $value ) {
            $prop  = $this->sanitize_css_prop( (string) $prop );
            $value = $this->sanitize_css_value( (string) $value );
            if ( $prop && $value ) {
                $lines .= '    ' . $prop . ': ' . $value . " !important;\n";
            }
        }
        return $lines;
    }

    // ── Sanitize CSS selector ────────────────────────────────────────────
    private function sanitize_selector( string $sel ): string {
        // Allow common CSS selector characters; strip PHP/HTML injection
        $sel = strip_tags( $sel );
        $sel = preg_replace( '/[<>{}]/', '', $sel );
        return trim( $sel );
    }

    // ── Sanitize CSS property name ───────────────────────────────────────
    private function sanitize_css_prop( string $prop ): string {
        // Allow letters, digits, hyphens, leading --
        return preg_match( '/^-?-?[a-zA-Z][a-zA-Z0-9-]*$/', $prop ) ? $prop : '';
    }

    // ── Sanitize CSS value ───────────────────────────────────────────────
    private function sanitize_css_value( string $value ): string {
        // Block expression(), url(javascript:), etc.
        $value = preg_replace( '/expression\s*\(/i', '', $value );
        $value = preg_replace( '/javascript\s*:/i', '', $value );
        $value = strip_tags( $value );
        return trim( $value );
    }

    // ── Determine if selector targets the whole site ─────────────────────
    private function is_global_selector( string $selector ): bool {
        $global_patterns = [ 'body', ':root', 'html', '*' ];
        foreach ( $global_patterns as $p ) {
            if ( strpos( $selector, $p ) !== false ) {
                return true;
            }
        }
        return false;
    }
}
