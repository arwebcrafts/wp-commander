<?php
/**
 * WPC_Admin — settings registration, admin menu, API key storage.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Admin {

    private static ?WPC_Admin $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'admin_menu',            [ $this, 'add_menu' ] );
        add_action( 'admin_init',            [ $this, 'register_settings' ] );
        add_action( 'admin_post_wpc_save_settings', [ $this, 'handle_save' ] );
        add_filter( 'plugin_action_links_' . WPC_BASENAME, [ $this, 'action_links' ] );
    }

    // ── Menu ────────────────────────────────────────────────────────────────
    public function add_menu(): void {
        add_options_page(
            __( 'WP Commander Settings', 'wp-commander' ),
            __( 'WP Commander', 'wp-commander' ),
            'manage_options',
            'wp-commander',
            [ $this, 'render_settings_page' ]
        );
    }

    // ── Settings registration ───────────────────────────────────────────────
    public function register_settings(): void {
        register_setting( 'wpc_settings_group', 'wpc_settings', [
            'sanitize_callback' => [ $this, 'sanitize_settings' ],
        ] );
    }

    public function sanitize_settings( mixed $input ): array {
        $clean = [];
        $clean['ai_provider']       = sanitize_key( $input['ai_provider'] ?? 'openai' );
        $clean['ai_model']          = sanitize_text_field( $input['ai_model'] ?? 'gpt-4o' );
        $clean['enable_frontend']   = ! empty( $input['enable_frontend'] ) ? '1' : '0';
        $clean['allowed_roles']     = array_map( 'sanitize_key', (array) ( $input['allowed_roles'] ?? [] ) );
        $clean['bar_position']      = in_array( $input['bar_position'] ?? '', [ 'bottom-right', 'bottom-left' ], true )
                                      ? $input['bar_position']
                                      : 'bottom-right';
        $clean['rate_limit']        = absint( $input['rate_limit'] ?? 20 );
        $clean['custom_endpoint']   = esc_url_raw( $input['custom_endpoint'] ?? '' );
        $clean['custom_model_name'] = sanitize_text_field( $input['custom_model_name'] ?? '' );

        // Save API keys per provider (encrypted, separately)
        $providers = [ 'openai', 'anthropic', 'openrouter', 'perplexity', 'custom' ];
        foreach ( $providers as $p ) {
            $key_field = 'api_key_' . $p;
            if ( ! empty( $input[ $key_field ] ) && $input[ $key_field ] !== $this->masked_key( $p ) ) {
                WPC_AI_Engine::save_api_key( $p, sanitize_text_field( $input[ $key_field ] ) );
            }
        }

        return $clean;
    }

    // ── Handle save (non-options-api path for danger zone actions) ──────────
    public function handle_save(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Unauthorized', 'wp-commander' ) );
        }
        check_admin_referer( 'wpc_danger_zone' );

        if ( isset( $_POST['wpc_delete_css'] ) ) {
            global $wpdb;
            // Delete all per-page CSS options
            $wpdb->query( $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like( 'wpc_custom_css_' ) . '%'
            ) );
            delete_option( 'wpc_global_css' );
            wp_safe_redirect( add_query_arg( 'wpc_msg', 'css_deleted', admin_url( 'options-general.php?page=wp-commander' ) ) );
            exit;
        }

        wp_safe_redirect( admin_url( 'options-general.php?page=wp-commander' ) );
        exit;
    }

    // ── Plugin action links ─────────────────────────────────────────────────
    public function action_links( array $links ): array {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            esc_url( admin_url( 'options-general.php?page=wp-commander' ) ),
            esc_html__( 'Settings', 'wp-commander' )
        );
        array_unshift( $links, $settings_link );
        return $links;
    }

    // ── Render settings page ────────────────────────────────────────────────
    public function render_settings_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) return;
        require_once WPC_DIR . 'admin/settings-page.php';
        wpc_render_settings_page();
    }

    // ── Mask API key for display ────────────────────────────────────────────
    public function masked_key( string $provider ): string {
        $key_option = 'wpc_api_key_' . $provider;
        $stored     = get_option( $key_option, '' );
        return $stored ? '••••••••••••••••' : '';
    }

    public function has_api_key( string $provider ): bool {
        return ! empty( get_option( 'wpc_api_key_' . $provider, '' ) );
    }
}

// Boot admin
add_action( 'plugins_loaded', function () {
    if ( is_admin() ) {
        WPC_Admin::instance();
    }
} );
