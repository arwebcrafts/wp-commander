<?php
/**
 * WPC_Plugin_Installer — installs and activates WordPress.org plugins silently.
 * Only free plugins from the WordPress.org repository are allowed.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Plugin_Installer {

    // ── Status constants ────────────────────────────────────────────────
    const STATUS_ALREADY_ACTIVE    = 'already_active';
    const STATUS_ACTIVATED         = 'activated';
    const STATUS_INSTALL_FAILED    = 'install_failed';
    const STATUS_ACTIVATION_FAILED = 'activation_failed';

    // ── Check if plugin is installed ─────────────────────────────────────
    public function is_installed( string $main_file ): bool {
        return file_exists( WP_PLUGIN_DIR . '/' . $main_file );
    }

    // ── Check if plugin is active ────────────────────────────────────────
    public function is_active( string $main_file ): bool {
        if ( ! function_exists( 'is_plugin_active' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        return is_plugin_active( $main_file );
    }

    // ── Install a single plugin from WordPress.org ───────────────────────
    public function install_plugin( string $slug ): array {
        $slug = sanitize_key( $slug );

        if ( empty( $slug ) ) {
            return [ 'success' => false, 'message' => __( 'Empty plugin slug.', 'wp-commander' ) ];
        }

        // Load required WP upgrade classes
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Fetch plugin info from WordPress.org API
        $api = plugins_api( 'plugin_information', [
            'slug'   => $slug,
            'fields' => [
                'short_description' => false,
                'sections'          => false,
                'reviews'           => false,
                'banners'           => false,
                'icons'             => false,
                'downloaded'        => false,
                'homepage'          => false,
                'tags'              => false,
                'donate_link'       => false,
            ],
        ] );

        if ( is_wp_error( $api ) ) {
            return [
                'success' => false,
                'message' => sprintf(
                    __( 'Plugin "%s" not found on WordPress.org: %s', 'wp-commander' ),
                    $slug,
                    $api->get_error_message()
                ),
            ];
        }

        // Security: only install from WordPress.org download link
        if ( empty( $api->download_link ) || strpos( $api->download_link, 'downloads.wordpress.org' ) === false ) {
            return [
                'success' => false,
                'message' => __( 'Only WordPress.org plugins are allowed.', 'wp-commander' ),
            ];
        }

        // Silent install (suppress output)
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => $result->get_error_message() ];
        }

        if ( $result === false ) {
            $errors = $skin->get_errors();
            $msg    = is_wp_error( $errors ) ? $errors->get_error_message() : __( 'Unknown install error.', 'wp-commander' );
            return [ 'success' => false, 'message' => $msg ];
        }

        return [
            'success' => true,
            'message' => sprintf( __( 'Installed: %s', 'wp-commander' ), esc_html( $api->name ) ),
            'name'    => esc_html( $api->name ),
        ];
    }

    // ── Activate a plugin ────────────────────────────────────────────────
    public function activate_plugin( string $main_file ): array {
        $main_file = sanitize_text_field( $main_file );

        if ( ! function_exists( 'activate_plugin' ) ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin( $main_file, '', false, true );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => $result->get_error_message() ];
        }

        return [ 'success' => true ];
    }

    // ── Install + activate batch ─────────────────────────────────────────
    public function install_and_activate_batch( array $plugins ): array {
        $results = [];

        foreach ( $plugins as $plugin ) {
            $slug      = sanitize_key( $plugin['slug']      ?? '' );
            $main_file = sanitize_text_field( $plugin['main_file'] ?? '' );
            $name      = sanitize_text_field( $plugin['name']      ?? $slug );

            if ( empty( $slug ) || empty( $main_file ) ) {
                $results[] = [
                    'slug'   => $slug,
                    'name'   => $name,
                    'status' => 'skipped',
                    'reason' => 'Missing slug or main_file',
                ];
                continue;
            }

            // Already active — skip
            if ( $this->is_active( $main_file ) ) {
                $results[] = [
                    'slug'   => $slug,
                    'name'   => $name,
                    'status' => self::STATUS_ALREADY_ACTIVE,
                ];
                continue;
            }

            // Install if needed
            if ( ! $this->is_installed( $main_file ) ) {
                $install = $this->install_plugin( $slug );
                if ( ! $install['success'] ) {
                    $results[] = [
                        'slug'   => $slug,
                        'name'   => $name,
                        'status' => self::STATUS_INSTALL_FAILED,
                        'error'  => $install['message'],
                    ];
                    continue;
                }
            }

            // Activate
            $activate = $this->activate_plugin( $main_file );
            $results[] = [
                'slug'   => $slug,
                'name'   => $name,
                'status' => $activate['success'] ? self::STATUS_ACTIVATED : self::STATUS_ACTIVATION_FAILED,
                'error'  => $activate['success'] ? null : ( $activate['message'] ?? '' ),
            ];

            // Log installed plugin for audit trail
            $this->log_installed( $slug, $main_file );
        }

        return $results;
    }

    // ── Audit log ────────────────────────────────────────────────────────
    private function log_installed( string $slug, string $main_file ): void {
        $log = get_option( 'wpc_installed_plugins', [] );
        $log[ $slug ] = [
            'main_file'  => $main_file,
            'installed'  => time(),
            'installed_by' => get_current_user_id(),
        ];
        update_option( 'wpc_installed_plugins', $log );
    }

    // ── Check install status for a list ─────────────────────────────────
    public function check_status( array $plugins ): array {
        $status = [];
        foreach ( $plugins as $plugin ) {
            $slug      = sanitize_key( $plugin['slug'] ?? '' );
            $main_file = sanitize_text_field( $plugin['main_file'] ?? '' );
            $status[] = [
                'slug'      => $slug,
                'installed' => $this->is_installed( $main_file ),
                'active'    => $this->is_active( $main_file ),
            ];
        }
        return $status;
    }
}
