<?php
/**
 * WPC_Executor — routes AI actions to the correct adapter + manages undo stack.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Executor {

    // Maximum undo entries per user/post
    const MAX_UNDO = 20;

    // ── Run an action returned by AI ─────────────────────────────────────
    public function run( array $action, int $post_id ): array {
        $action_type = sanitize_key( $action['action'] ?? 'style_update' );
        $builder     = sanitize_key( $action['builder'] ?? 'generic' );

        // Always apply CSS adapter first (live preview)
        $css_result = $this->apply_css( $action, $post_id );

        // Then route to builder-specific adapter for persistence
        $persist_result = $this->apply_to_builder( $action, $builder, $post_id );

        // Save undo entry
        $undo_key = $this->save_undo_entry( $action, $post_id );

        // Bust page scan cache
        WPC_Site_Scanner::bust_cache( $post_id );

        return [
            'success'  => $css_result || $persist_result,
            'message'  => $this->build_success_message( $action ),
            'undo_key' => $undo_key,
            'css'      => $this->get_page_css( $post_id ),
            'action'   => $action_type,
        ];
    }

    // ── CSS adapter ──────────────────────────────────────────────────────
    private function apply_css( array $action, int $post_id ): bool {
        if ( empty( $action['changes'] ) && empty( $action['target_selector'] ) ) {
            return false;
        }

        $adapter = new WPC_Adapter_CSS();

        if ( ! empty( $action['changes'] ) && ! empty( $action['target_selector'] ) ) {
            return $adapter->inject(
                $post_id,
                sanitize_text_field( $action['target_selector'] ),
                $action['changes'],
                $action['undo_key'] ?? 'wpc-' . time()
            );
        }

        return false;
    }

    // ── Builder-specific persistence ──────────────────────────────────────
    private function apply_to_builder( array $action, string $builder, int $post_id ): bool {
        switch ( $builder ) {
            case 'elementor':
                $adapter = new WPC_Adapter_Elementor();
                if ( ! empty( $action['elementor_widget_id'] ) && ! empty( $action['elementor_setting_key'] ) ) {
                    $value = $action['changes'][ $action['elementor_setting_key'] ] ?? reset( $action['changes'] );
                    $adapter->update_widget_setting(
                        $post_id,
                        sanitize_text_field( $action['elementor_widget_id'] ),
                        sanitize_text_field( $action['elementor_setting_key'] ),
                        $value
                    );
                    return true;
                }
                break;

            case 'gutenberg':
                if ( ! empty( $action['content_update'] ) ) {
                    $adapter = new WPC_Adapter_Gutenberg();
                    $adapter->update_content( $post_id, wp_kses_post( $action['content_update'] ) );
                    return true;
                }
                break;

            case 'divi':
                if ( ! empty( $action['divi_module'] ) ) {
                    $adapter = new WPC_Adapter_Divi();
                    $adapter->update_module(
                        $post_id,
                        sanitize_text_field( $action['divi_module'] ),
                        $action['changes'] ?? []
                    );
                    return true;
                }
                break;
        }

        // Generic fallback — update via WP REST/wp_update_post
        if ( ! empty( $action['content_update'] ) ) {
            $adapter = new WPC_Adapter_Generic();
            return $adapter->update( $post_id, wp_kses_post( $action['content_update'] ) );
        }

        return true; // CSS-only changes are still successful
    }

    // ── Undo ──────────────────────────────────────────────────────────────
    public function undo_last( int $post_id ): array {
        $uid      = get_current_user_id();
        $stack    = $this->get_undo_stack( $uid, $post_id );

        if ( empty( $stack ) ) {
            return [ 'success' => false, 'message' => __( 'Nothing to undo.', 'wp-commander' ) ];
        }

        $entry = array_shift( $stack );
        $this->save_undo_stack( $uid, $post_id, $stack );

        // Remove this CSS change from stored CSS
        $css_adapter = new WPC_Adapter_CSS();
        $css_adapter->remove_by_key( $post_id, $entry['undo_key'] ?? '' );

        // Reverse builder change if snapshot saved
        if ( ! empty( $entry['snapshot'] ) ) {
            $builder = $entry['builder'] ?? 'generic';
            $this->restore_snapshot( $post_id, $builder, $entry['snapshot'] );
        }

        WPC_Site_Scanner::bust_cache( $post_id );

        return [
            'success' => true,
            'message' => __( 'Last change undone.', 'wp-commander' ),
            'css'     => $this->get_page_css( $post_id ),
        ];
    }

    // ── Snapshot helpers for undo ────────────────────────────────────────
    private function save_undo_entry( array $action, int $post_id ): string {
        $uid      = get_current_user_id();
        $undo_key = 'wpc-' . $post_id . '-' . time() . '-' . wp_rand( 100, 999 );
        $builder  = $action['builder'] ?? 'generic';

        // Take snapshot of current builder data
        $snapshot = $this->take_snapshot( $post_id, $builder );

        $entry = [
            'undo_key' => $undo_key,
            'action'   => $action,
            'builder'  => $builder,
            'snapshot' => $snapshot,
            'time'     => time(),
        ];

        $stack = $this->get_undo_stack( $uid, $post_id );
        array_unshift( $stack, $entry );
        if ( count( $stack ) > self::MAX_UNDO ) {
            $stack = array_slice( $stack, 0, self::MAX_UNDO );
        }
        $this->save_undo_stack( $uid, $post_id, $stack );

        return $undo_key;
    }

    private function take_snapshot( int $post_id, string $builder ): ?string {
        switch ( $builder ) {
            case 'elementor':
                return get_post_meta( $post_id, '_elementor_data', true ) ?: null;
            case 'gutenberg':
            case 'divi':
            case 'generic':
                return get_post_field( 'post_content', $post_id ) ?: null;
            default:
                return null;
        }
    }

    private function restore_snapshot( int $post_id, string $builder, string $snapshot ): void {
        switch ( $builder ) {
            case 'elementor':
                update_post_meta( $post_id, '_elementor_data', wp_slash( $snapshot ) );
                if ( class_exists( '\Elementor\Plugin' ) ) {
                    \Elementor\Plugin::$instance->files_manager->clear_cache();
                }
                break;
            case 'gutenberg':
            case 'divi':
            case 'generic':
                wp_update_post( [
                    'ID'           => $post_id,
                    'post_content' => $snapshot,
                ] );
                break;
        }
    }

    // ── Undo stack storage ───────────────────────────────────────────────
    private function get_undo_stack( int $uid, int $post_id ): array {
        return get_transient( 'wpc_undo_' . $uid . '_' . $post_id ) ?: [];
    }

    private function save_undo_stack( int $uid, int $post_id, array $stack ): void {
        set_transient( 'wpc_undo_' . $uid . '_' . $post_id, $stack, DAY_IN_SECONDS );
    }

    // ── Get current page CSS for live refresh ───────────────────────────
    private function get_page_css( int $post_id ): string {
        return (string) get_option( 'wpc_custom_css_' . $post_id, '' );
    }

    // ── Build human-readable success message ─────────────────────────────
    private function build_success_message( array $action ): string {
        $type = $action['action'] ?? 'change';
        switch ( $type ) {
            case 'style_update':
                $props = array_keys( $action['changes'] ?? [] );
                return sprintf(
                    __( 'Updated %s on %s', 'wp-commander' ),
                    implode( ', ', array_map( 'esc_html', $props ) ),
                    esc_html( $action['target_selector'] ?? 'element' )
                );
            case 'content_update':
                return __( 'Content updated successfully.', 'wp-commander' );
            default:
                return __( 'Change applied successfully.', 'wp-commander' );
        }
    }
}
