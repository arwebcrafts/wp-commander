<?php
/**
 * Plugin Name: WP Commander
 * Plugin URI:  https://wpcommander.io
 * Description: AI-powered command bar for WordPress. Edit your site or generate a full modern website from a single prompt — supports OpenAI, Claude, OpenRouter, Perplexity, and more. Works with any page builder.
 * Version:     1.0.0
 * Author:      WP Commander
 * License:     GPL v2 or later
 * Text Domain: wp-commander
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */

defined( 'ABSPATH' ) || exit;

// ── Constants ──────────────────────────────────────────────────────────────
define( 'WPC_VERSION',  '1.0.0' );
define( 'WPC_FILE',     __FILE__ );
define( 'WPC_DIR',      plugin_dir_path( __FILE__ ) );
define( 'WPC_URL',      plugin_dir_url( __FILE__ ) );
define( 'WPC_BASENAME', plugin_basename( __FILE__ ) );

// ── Autoload classes ────────────────────────────────────────────────────────
$wpc_classes = [
    WPC_DIR . 'includes/class-ai-engine.php',
    WPC_DIR . 'includes/class-site-scanner.php',
    WPC_DIR . 'includes/class-url-analyzer.php',
    WPC_DIR . 'includes/class-site-generator.php',
    WPC_DIR . 'includes/class-executor.php',
    WPC_DIR . 'includes/class-plugin-installer.php',
    WPC_DIR . 'includes/adapters/class-adapter-css.php',
    WPC_DIR . 'includes/adapters/class-adapter-gutenberg.php',
    WPC_DIR . 'includes/adapters/class-adapter-elementor.php',
    WPC_DIR . 'includes/adapters/class-adapter-divi.php',
    WPC_DIR . 'includes/adapters/class-adapter-generic.php',
    WPC_DIR . 'admin/class-admin.php',
    WPC_DIR . 'includes/class-core.php',
];

foreach ( $wpc_classes as $file ) {
    if ( file_exists( $file ) ) {
        require_once $file;
    }
}

// ── Activation / Deactivation ───────────────────────────────────────────────
register_activation_hook( __FILE__, 'wpc_activate' );
register_deactivation_hook( __FILE__, 'wpc_deactivate' );

function wpc_activate() {
    $defaults = [
        'ai_provider'       => 'openai',
        'ai_model'          => 'gpt-4o',
        'enable_frontend'   => '1',
        'allowed_roles'     => [ 'administrator', 'editor' ],
        'bar_position'      => 'bottom-right',
        'rate_limit'        => 20,
    ];
    if ( ! get_option( 'wpc_settings' ) ) {
        add_option( 'wpc_settings', $defaults );
    }
    flush_rewrite_rules();
}

function wpc_deactivate() {
    flush_rewrite_rules();
}

// ── Boot ────────────────────────────────────────────────────────────────────
add_action( 'plugins_loaded', function () {
    if ( class_exists( 'WPC_Core' ) ) {
        WPC_Core::instance();
    }
} );
