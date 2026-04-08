<?php
/**
 * WPC_Core — plugin bootstrap, REST routes, asset enqueueing.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Core {

    // ── Singleton ───────────────────────────────────────────────────────────
    private static ?WPC_Core $instance = null;

    public static function instance(): self {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action( 'init',            [ $this, 'load_textdomain' ] );
        add_action( 'rest_api_init',   [ $this, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts',    [ $this, 'enqueue_frontend' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_admin' ] );
        add_action( 'wp_footer',       [ $this, 'render_command_bar' ] );
        add_action( 'admin_footer',    [ $this, 'render_command_bar' ] );
        add_action( 'wp_head',         [ $this, 'output_custom_css' ] );
    }

    // ── Textdomain ──────────────────────────────────────────────────────────
    public function load_textdomain(): void {
        load_plugin_textdomain( 'wp-commander', false, WPC_DIR . 'languages' );
    }

    // ── Assets ──────────────────────────────────────────────────────────────
    public function enqueue_frontend(): void {
        if ( ! $this->user_can_use() ) {
            return;
        }
        $settings = get_option( 'wpc_settings', [] );
        if ( empty( $settings['enable_frontend'] ) ) {
            return;
        }
        $this->enqueue_assets();
    }

    public function enqueue_admin(): void {
        if ( ! $this->user_can_use() ) {
            return;
        }
        $this->enqueue_assets();
    }

    private function enqueue_assets(): void {
        wp_enqueue_style(
            'wpc-command-bar',
            WPC_URL . 'assets/css/command-bar.css',
            [],
            WPC_VERSION
        );

        wp_enqueue_script(
            'wpc-command-bar',
            WPC_URL . 'assets/js/command-bar.js',
            [],
            WPC_VERSION,
            true
        );

        // Pass data to JS (NO API keys — all AI calls are server-side)
        wp_localize_script( 'wpc-command-bar', 'WPC_Data', [
            'rest_url'   => esc_url_raw( rest_url( 'wp-commander/v1/' ) ),
            'nonce'      => wp_create_nonce( 'wp_rest' ),
            'wpc_nonce'  => wp_create_nonce( 'wp_commander_nonce' ),
            'post_id'    => get_the_ID() ?: 0,
            'is_admin'   => is_admin() ? 1 : 0,
            'position'   => esc_attr( get_option( 'wpc_settings', [] )['bar_position'] ?? 'bottom-right' ),
            'user_id'    => get_current_user_id(),
            'ajax_url'   => admin_url( 'admin-ajax.php' ),
        ] );
    }

    // ── Capability check ────────────────────────────────────────────────────
    public function user_can_use(): bool {
        if ( ! is_user_logged_in() ) {
            return false;
        }
        $settings = get_option( 'wpc_settings', [] );
        $roles    = $settings['allowed_roles'] ?? [ 'administrator', 'editor' ];
        $user     = wp_get_current_user();
        foreach ( (array) $roles as $role ) {
            if ( in_array( $role, (array) $user->roles, true ) ) {
                return true;
            }
        }
        return current_user_can( 'edit_posts' );
    }

    // ── Render floating bar markup ──────────────────────────────────────────
    public function render_command_bar(): void {
        if ( ! $this->user_can_use() ) {
            return;
        }
        // On frontend, check enable_frontend setting
        if ( ! is_admin() ) {
            $settings = get_option( 'wpc_settings', [] );
            if ( empty( $settings['enable_frontend'] ) ) {
                return;
            }
        }
        ?>
        <div id="wpc-root" class="wpc-root" data-position="<?php echo esc_attr( get_option( 'wpc_settings', [] )['bar_position'] ?? 'bottom-right' ); ?>" role="complementary" aria-label="<?php esc_attr_e( 'WP Commander', 'wp-commander' ); ?>">
            <!-- Floating trigger button -->
            <button id="wpc-trigger" class="wpc-trigger" aria-label="<?php esc_attr_e( 'Open WP Commander', 'wp-commander' ); ?>" title="<?php esc_attr_e( 'WP Commander (Ctrl+Shift+K)', 'wp-commander' ); ?>">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <path d="M9.5 2L4 7.5 9.5 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M14.5 2L20 7.5 14.5 13" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M3 20h18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M7 20v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M12 20v-7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <path d="M17 20v-2" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
                <span class="wpc-trigger-label">AI</span>
            </button>

            <!-- Modal overlay -->
            <div id="wpc-modal" class="wpc-modal" role="dialog" aria-modal="true" aria-labelledby="wpc-modal-title" hidden>
                <div class="wpc-modal-backdrop" id="wpc-backdrop"></div>
                <div class="wpc-modal-container">

                    <!-- Header -->
                    <div class="wpc-modal-header">
                        <div class="wpc-modal-brand">
                            <span class="wpc-brand-icon" aria-hidden="true">⚡</span>
                            <span id="wpc-modal-title" class="wpc-brand-name">WP Commander</span>
                        </div>
                        <div class="wpc-tabs" role="tablist">
                            <button class="wpc-tab wpc-tab--active" role="tab" aria-selected="true" data-tab="edit" id="tab-edit" aria-controls="panel-edit">
                                <?php esc_html_e( 'Edit Site', 'wp-commander' ); ?>
                            </button>
                            <button class="wpc-tab" role="tab" aria-selected="false" data-tab="generate" id="tab-generate" aria-controls="panel-generate">
                                <?php esc_html_e( 'Generate Site', 'wp-commander' ); ?>
                            </button>
                        </div>
                        <button class="wpc-close" id="wpc-close" aria-label="<?php esc_attr_e( 'Close', 'wp-commander' ); ?>">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                                <line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/>
                            </svg>
                        </button>
                    </div>

                    <!-- Edit panel -->
                    <div class="wpc-panel wpc-panel--active" id="panel-edit" role="tabpanel" aria-labelledby="tab-edit">
                        <div class="wpc-input-wrap">
                            <textarea
                                id="wpc-edit-input"
                                class="wpc-input"
                                placeholder="<?php esc_attr_e( 'e.g. "Change the hero button color to orange"', 'wp-commander' ); ?>"
                                rows="2"
                                aria-label="<?php esc_attr_e( 'Edit command', 'wp-commander' ); ?>"
                            ></textarea>
                            <button class="wpc-btn wpc-btn--primary" id="wpc-edit-submit">
                                <span class="wpc-btn-text"><?php esc_html_e( 'Apply', 'wp-commander' ); ?></span>
                                <span class="wpc-spinner" aria-hidden="true" hidden></span>
                            </button>
                        </div>
                        <div id="wpc-edit-feedback" class="wpc-feedback" aria-live="polite" hidden></div>
                        <div id="wpc-undo-wrap" class="wpc-undo-wrap" hidden>
                            <button class="wpc-btn wpc-btn--ghost wpc-btn--sm" id="wpc-undo">
                                ↩ <?php esc_html_e( 'Undo Last Change', 'wp-commander' ); ?>
                            </button>
                        </div>
                        <div class="wpc-history-wrap" id="wpc-edit-history" hidden>
                            <p class="wpc-history-label"><?php esc_html_e( 'Recent commands', 'wp-commander' ); ?></p>
                            <ul class="wpc-history-list" id="wpc-edit-history-list" role="list"></ul>
                        </div>
                    </div>

                    <!-- Generate panel -->
                    <div class="wpc-panel" id="panel-generate" role="tabpanel" aria-labelledby="tab-generate" hidden>
                        <div class="wpc-input-wrap">
                            <textarea
                                id="wpc-gen-input"
                                class="wpc-input"
                                placeholder="<?php esc_attr_e( 'e.g. "Create a modern dentist website with booking"', 'wp-commander' ); ?>"
                                rows="2"
                                aria-label="<?php esc_attr_e( 'Site generation prompt', 'wp-commander' ); ?>"
                            ></textarea>
                            <button class="wpc-btn wpc-btn--primary" id="wpc-gen-submit">
                                <span class="wpc-btn-text"><?php esc_html_e( 'Generate', 'wp-commander' ); ?></span>
                                <span class="wpc-spinner" aria-hidden="true" hidden></span>
                            </button>
                        </div>
                        <div class="wpc-reference-row">
                            <input type="url" id="wpc-ref-url" class="wpc-input wpc-input--sm"
                                placeholder="<?php esc_attr_e( 'Reference URL (optional)', 'wp-commander' ); ?>"
                                aria-label="<?php esc_attr_e( 'Reference site URL', 'wp-commander' ); ?>"
                            />
                        </div>
                        <div id="wpc-gen-feedback" class="wpc-feedback" aria-live="polite" hidden></div>

                        <!-- Plugin installer panel (shown after blueprint returned) -->
                        <div id="wpc-plugins-panel" class="wpc-plugins-panel" hidden>
                            <h3 class="wpc-plugins-title"><?php esc_html_e( 'Required Plugins', 'wp-commander' ); ?></h3>
                            <ul class="wpc-plugins-list" id="wpc-plugins-list" role="list"></ul>
                            <div class="wpc-plugins-actions">
                                <button class="wpc-btn wpc-btn--primary" id="wpc-install-all"><?php esc_html_e( 'Install &amp; Activate All', 'wp-commander' ); ?></button>
                                <button class="wpc-btn wpc-btn--secondary" id="wpc-install-missing"><?php esc_html_e( 'Skip Already Installed', 'wp-commander' ); ?></button>
                                <button class="wpc-btn wpc-btn--ghost" id="wpc-install-skip"><?php esc_html_e( "I'll do it manually", 'wp-commander' ); ?></button>
                            </div>
                        </div>

                        <!-- Progress panel -->
                        <div id="wpc-gen-progress" class="wpc-gen-progress" hidden>
                            <div class="wpc-progress-bar-wrap">
                                <div class="wpc-progress-bar" id="wpc-progress-bar" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100" style="width:0%"></div>
                            </div>
                            <p class="wpc-progress-label" id="wpc-progress-label"><?php esc_html_e( 'Building your site…', 'wp-commander' ); ?></p>
                            <ul class="wpc-progress-steps" id="wpc-progress-steps" role="list"></ul>
                        </div>

                        <div class="wpc-history-wrap" id="wpc-gen-history" hidden>
                            <p class="wpc-history-label"><?php esc_html_e( 'Recent generations', 'wp-commander' ); ?></p>
                            <ul class="wpc-history-list" id="wpc-gen-history-list" role="list"></ul>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="wpc-modal-footer">
                        <span class="wpc-shortcut-hint">
                            <kbd>Ctrl</kbd><kbd>Shift</kbd><kbd>K</kbd> <?php esc_html_e( 'to toggle', 'wp-commander' ); ?>
                        </span>
                        <span class="wpc-ai-badge" id="wpc-ai-badge"></span>
                    </div>

                </div><!-- .wpc-modal-container -->
            </div><!-- .wpc-modal -->
        </div><!-- #wpc-root -->
        <?php
    }

    // ── Output custom CSS in <head> ─────────────────────────────────────────
    public function output_custom_css(): void {
        // Global site CSS from site generator / edits
        $global_css = get_option( 'wpc_global_css', '' );
        if ( $global_css ) {
            echo '<style id="wpc-global-css">' . wp_strip_all_tags( $global_css ) . '</style>' . "\n";
        }

        // Per-page CSS
        $post_id = get_the_ID();
        if ( $post_id ) {
            $page_css = get_option( 'wpc_custom_css_' . $post_id, '' );
            if ( $page_css ) {
                echo '<style id="wpc-page-css-' . absint( $post_id ) . '">' . wp_strip_all_tags( $page_css ) . '</style>' . "\n";
            }
        }
    }

    // ── REST API Routes ─────────────────────────────────────────────────────
    public function register_routes(): void {
        $ns = 'wp-commander/v1';

        register_rest_route( $ns, '/execute-command', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_execute_command' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( $ns, '/generate-site', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_generate_site' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( $ns, '/generation-status', [
            'methods'             => 'GET',
            'callback'            => [ $this, 'rest_generation_status' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( $ns, '/undo-last', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_undo_last' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( $ns, '/analyze-url', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_analyze_url' ],
            'permission_callback' => [ $this, 'rest_permission' ],
        ] );

        register_rest_route( $ns, '/install-plugins', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'rest_install_plugins' ],
            'permission_callback' => [ $this, 'rest_install_permission' ],
        ] );
    }

    // ── Permission callbacks ────────────────────────────────────────────────
    public function rest_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ), 'wp_rest' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'wp-commander' ), [ 'status' => 403 ] );
        }
        if ( ! current_user_can( 'edit_posts' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Insufficient permissions.', 'wp-commander' ), [ 'status' => 403 ] );
        }
        return true;
    }

    public function rest_install_permission( WP_REST_Request $request ): bool|WP_Error {
        if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ) ?: $request->get_param( '_wpnonce' ), 'wp_rest' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'Invalid nonce.', 'wp-commander' ), [ 'status' => 403 ] );
        }
        if ( ! current_user_can( 'install_plugins' ) ) {
            return new WP_Error( 'rest_forbidden', __( 'You need install_plugins capability.', 'wp-commander' ), [ 'status' => 403 ] );
        }
        return true;
    }

    // ── REST Handlers ───────────────────────────────────────────────────────
    public function rest_execute_command( WP_REST_Request $req ): WP_REST_Response {
        $command = sanitize_text_field( $req->get_param( 'command' ) );
        $post_id = absint( $req->get_param( 'post_id' ) );

        if ( empty( $command ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Command is empty.', 'wp-commander' ) ], 400 );
        }

        // Rate limit
        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $rate_check->get_error_message() ], 429 );
        }

        $scanner  = new WPC_Site_Scanner();
        $page_map = $scanner->get_page_map( $post_id );

        $ai     = new WPC_AI_Engine();
        $action = $ai->execute_command( $command, $page_map );

        if ( is_wp_error( $action ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $action->get_error_message() ], 500 );
        }

        $executor = new WPC_Executor();
        $result   = $executor->run( $action, $post_id );

        $this->increment_rate_limit();

        return new WP_REST_Response( $result );
    }

    public function rest_generate_site( WP_REST_Request $req ): WP_REST_Response {
        $prompt  = sanitize_text_field( $req->get_param( 'prompt' ) );
        $ref_url = esc_url_raw( $req->get_param( 'reference_url' ) ?? '' );

        if ( empty( $prompt ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Prompt is empty.', 'wp-commander' ) ], 400 );
        }

        $rate_check = $this->check_rate_limit();
        if ( is_wp_error( $rate_check ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $rate_check->get_error_message() ], 429 );
        }

        $reference_data = [];
        if ( $ref_url ) {
            $analyzer       = new WPC_URL_Analyzer();
            $reference_data = $analyzer->analyze( $ref_url );
        }

        $ai        = new WPC_AI_Engine();
        $blueprint = $ai->generate_site_blueprint( $prompt, $reference_data );

        if ( is_wp_error( $blueprint ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => $blueprint->get_error_message() ], 500 );
        }

        // Store blueprint for polling + incremental generation
        set_transient( 'wpc_blueprint_' . get_current_user_id(), $blueprint, HOUR_IN_SECONDS );
        set_transient( 'wpc_gen_status_' . get_current_user_id(), [
            'status'   => 'blueprint_ready',
            'progress' => 0,
            'steps'    => [],
        ], HOUR_IN_SECONDS );

        $this->increment_rate_limit();

        return new WP_REST_Response( [
            'success'          => true,
            'blueprint'        => $blueprint,
            'required_plugins' => $blueprint['required_plugins'] ?? [],
        ] );
    }

    public function rest_generation_status( WP_REST_Request $req ): WP_REST_Response {
        $job_id = sanitize_text_field( $req->get_param( 'job_id' ) ?? '' );
        $uid    = get_current_user_id();
        $status = get_transient( 'wpc_gen_status_' . $uid ) ?: [ 'status' => 'idle', 'progress' => 0 ];
        return new WP_REST_Response( $status );
    }

    public function rest_undo_last( WP_REST_Request $req ): WP_REST_Response {
        $post_id  = absint( $req->get_param( 'post_id' ) );
        $executor = new WPC_Executor();
        $result   = $executor->undo_last( $post_id );
        return new WP_REST_Response( $result );
    }

    public function rest_analyze_url( WP_REST_Request $req ): WP_REST_Response {
        $url = esc_url_raw( $req->get_param( 'url' ) );
        if ( empty( $url ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => __( 'URL is empty.', 'wp-commander' ) ], 400 );
        }
        $analyzer = new WPC_URL_Analyzer();
        $data     = $analyzer->analyze( $url );
        return new WP_REST_Response( [ 'success' => true, 'data' => $data ] );
    }

    public function rest_install_plugins( WP_REST_Request $req ): WP_REST_Response {
        $plugins = $req->get_param( 'plugins' );
        if ( ! is_array( $plugins ) ) {
            return new WP_REST_Response( [ 'success' => false, 'message' => __( 'Invalid plugins list.', 'wp-commander' ) ], 400 );
        }

        // Sanitize each plugin entry
        $clean = [];
        foreach ( $plugins as $p ) {
            $clean[] = [
                'slug'      => sanitize_key( $p['slug'] ?? '' ),
                'main_file' => sanitize_text_field( $p['main_file'] ?? '' ),
                'name'      => sanitize_text_field( $p['name'] ?? '' ),
            ];
        }

        $installer = new WPC_Plugin_Installer();
        $results   = $installer->install_and_activate_batch( $clean );
        $all_done  = ! in_array( false, array_column( $results, 'status' ), true );

        // After plugins installed, trigger site build if blueprint exists
        $uid       = get_current_user_id();
        $blueprint = get_transient( 'wpc_blueprint_' . $uid );
        $build_started = false;
        if ( $blueprint ) {
            $generator = new WPC_Site_Generator();
            $generator->build_async( $blueprint, $uid );
            $build_started = true;
        }

        return new WP_REST_Response( [
            'success'       => true,
            'results'       => $results,
            'all_done'      => $all_done,
            'build_started' => $build_started,
        ] );
    }

    // ── Rate limiting ───────────────────────────────────────────────────────
    private function check_rate_limit(): true|WP_Error {
        $uid     = get_current_user_id();
        $key     = 'wpc_rate_' . $uid;
        $count   = (int) get_transient( $key );
        $limit   = (int) ( get_option( 'wpc_settings', [] )['rate_limit'] ?? 20 );
        if ( $count >= $limit ) {
            return new WP_Error( 'rate_limit', sprintf(
                __( 'Rate limit reached: %d AI calls per hour.', 'wp-commander' ),
                $limit
            ) );
        }
        return true;
    }

    private function increment_rate_limit(): void {
        $uid = get_current_user_id();
        $key = 'wpc_rate_' . $uid;
        $n   = (int) get_transient( $key );
        set_transient( $key, $n + 1, HOUR_IN_SECONDS );
    }
}
