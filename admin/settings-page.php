<?php
/**
 * WP Commander — Settings page renderer.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

function wpc_render_settings_page(): void {
    $settings = get_option( 'wpc_settings', [] );
    $admin    = WPC_Admin::instance();
    $msg      = sanitize_key( $_GET['wpc_msg'] ?? '' );
    $provider = $settings['ai_provider'] ?? 'openai';
    ?>
    <div class="wrap wpc-settings-wrap">
        <h1>
            <span style="font-size:1.4em;margin-right:6px">⚡</span>
            <?php esc_html_e( 'WP Commander — Settings', 'wp-commander' ); ?>
        </h1>

        <?php if ( $msg === 'css_deleted' ) : ?>
            <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All WP Commander CSS has been deleted.', 'wp-commander' ); ?></p></div>
        <?php endif; ?>

        <form method="post" action="options.php">
            <?php
            settings_fields( 'wpc_settings_group' );
            do_settings_sections( 'wp-commander' );
            ?>

            <!-- ── AI Provider ───────────────────────────────────────── -->
            <h2 class="title"><?php esc_html_e( 'AI Provider', 'wp-commander' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Active Provider', 'wp-commander' ); ?></th>
                    <td>
                        <select name="wpc_settings[ai_provider]" id="wpc-provider-select" onchange="wpcToggleProviderFields(this.value)">
                            <?php foreach ( WPC_AI_Engine::PROVIDERS as $key => $label ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>" <?php selected( $provider, $key ); ?>>
                                    <?php echo esc_html( $label ); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description"><?php esc_html_e( 'Choose the AI provider for all commands. You can configure multiple providers below.', 'wp-commander' ); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Model', 'wp-commander' ); ?></th>
                    <td>
                        <input type="text" name="wpc_settings[ai_model]" id="wpc-model-input"
                               value="<?php echo esc_attr( $settings['ai_model'] ?? 'gpt-4o' ); ?>"
                               class="regular-text"
                               placeholder="e.g. gpt-4o, claude-sonnet-4-6"
                        />
                        <p class="description" id="wpc-model-hints"></p>
                    </td>
                </tr>
            </table>

            <!-- ── API Keys ──────────────────────────────────────────── -->
            <h2 class="title"><?php esc_html_e( 'API Keys', 'wp-commander' ); ?></h2>
            <p class="description"><?php esc_html_e( 'Keys are stored encrypted. Leave blank to keep the existing key. Fields showing •••• already have a key saved.', 'wp-commander' ); ?></p>
            <table class="form-table" role="presentation">
                <?php
                $provider_configs = [
                    'openai'     => [ 'label' => 'OpenAI API Key',                  'placeholder' => 'sk-...',          'hint' => 'platform.openai.com/api-keys' ],
                    'anthropic'  => [ 'label' => 'Anthropic (Claude) API Key',      'placeholder' => 'sk-ant-...',      'hint' => 'console.anthropic.com/keys' ],
                    'openrouter' => [ 'label' => 'OpenRouter API Key',               'placeholder' => 'sk-or-...',       'hint' => 'openrouter.ai/keys' ],
                    'perplexity' => [ 'label' => 'Perplexity API Key',               'placeholder' => 'pplx-...',        'hint' => 'perplexity.ai/settings/api' ],
                    'custom'     => [ 'label' => 'Custom Provider API Key',          'placeholder' => 'your-key',        'hint' => '' ],
                ];
                foreach ( $provider_configs as $p_key => $p_cfg ) :
                    $has_key = $admin->has_api_key( $p_key );
                ?>
                <tr>
                    <th scope="row">
                        <label for="wpc-key-<?php echo esc_attr( $p_key ); ?>">
                            <?php echo esc_html( $p_cfg['label'] ); ?>
                            <?php if ( $has_key ) : ?><span style="color:#00a32a;font-weight:normal"> ✓</span><?php endif; ?>
                        </label>
                    </th>
                    <td>
                        <input type="password"
                               id="wpc-key-<?php echo esc_attr( $p_key ); ?>"
                               name="wpc_settings[api_key_<?php echo esc_attr( $p_key ); ?>]"
                               class="regular-text"
                               value="<?php echo $has_key ? '••••••••••••••••' : ''; ?>"
                               placeholder="<?php echo esc_attr( $has_key ? __( 'Key saved — enter new to replace', 'wp-commander' ) : $p_cfg['placeholder'] ); ?>"
                               autocomplete="new-password"
                        />
                        <?php if ( $p_cfg['hint'] ) : ?>
                            <p class="description"><?php echo esc_html( $p_cfg['hint'] ); ?></p>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>

                <!-- Custom endpoint -->
                <tr id="wpc-custom-endpoint-row" style="<?php echo $provider !== 'custom' ? 'display:none' : ''; ?>">
                    <th scope="row"><label for="wpc-custom-endpoint"><?php esc_html_e( 'Custom API Endpoint', 'wp-commander' ); ?></label></th>
                    <td>
                        <input type="url" id="wpc-custom-endpoint" name="wpc_settings[custom_endpoint]"
                               value="<?php echo esc_attr( $settings['custom_endpoint'] ?? '' ); ?>"
                               class="regular-text"
                               placeholder="https://your-api.com/v1/chat/completions"
                        />
                        <p class="description"><?php esc_html_e( 'Must be OpenAI-compatible (chat completions format).', 'wp-commander' ); ?></p>
                    </td>
                </tr>
            </table>

            <!-- ── Appearance & Behavior ─────────────────────────────── -->
            <h2 class="title"><?php esc_html_e( 'Appearance &amp; Behavior', 'wp-commander' ); ?></h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable on Frontend', 'wp-commander' ); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="wpc_settings[enable_frontend]" value="1"
                                   <?php checked( $settings['enable_frontend'] ?? '1', '1' ); ?> />
                            <?php esc_html_e( 'Show command bar on the frontend for eligible users', 'wp-commander' ); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Allowed Roles', 'wp-commander' ); ?></th>
                    <td>
                        <?php
                        $allowed = (array) ( $settings['allowed_roles'] ?? [ 'administrator', 'editor' ] );
                        $roles   = [ 'administrator' => __( 'Administrator', 'wp-commander' ),
                                     'editor'        => __( 'Editor', 'wp-commander' ),
                                     'author'        => __( 'Author', 'wp-commander' ) ];
                        foreach ( $roles as $role_key => $role_label ) :
                        ?>
                        <label style="display:block;margin-bottom:4px">
                            <input type="checkbox" name="wpc_settings[allowed_roles][]"
                                   value="<?php echo esc_attr( $role_key ); ?>"
                                   <?php checked( in_array( $role_key, $allowed, true ) ); ?> />
                            <?php echo esc_html( $role_label ); ?>
                        </label>
                        <?php endforeach; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Command Bar Position', 'wp-commander' ); ?></th>
                    <td>
                        <select name="wpc_settings[bar_position]">
                            <option value="bottom-right" <?php selected( $settings['bar_position'] ?? 'bottom-right', 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'wp-commander' ); ?></option>
                            <option value="bottom-left"  <?php selected( $settings['bar_position'] ?? 'bottom-right', 'bottom-left'  ); ?>><?php esc_html_e( 'Bottom Left', 'wp-commander' ); ?></option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e( 'Rate Limit', 'wp-commander' ); ?></th>
                    <td>
                        <input type="number" name="wpc_settings[rate_limit]"
                               value="<?php echo esc_attr( $settings['rate_limit'] ?? 20 ); ?>"
                               min="1" max="200" class="small-text" />
                        <span><?php esc_html_e( 'AI calls per hour, per user', 'wp-commander' ); ?></span>
                    </td>
                </tr>
            </table>

            <?php submit_button( __( 'Save Settings', 'wp-commander' ) ); ?>
        </form>

        <!-- ── Danger Zone ─────────────────────────────────────────── -->
        <hr style="margin:30px 0">
        <h2 class="title" style="color:#d63638"><?php esc_html_e( 'Danger Zone', 'wp-commander' ); ?></h2>
        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"
              onsubmit="return confirm('<?php echo esc_js( __( 'This will delete all WP Commander generated CSS. This cannot be undone. Continue?', 'wp-commander' ) ); ?>')">
            <?php wp_nonce_field( 'wpc_danger_zone' ); ?>
            <input type="hidden" name="action" value="wpc_save_settings" />
            <input type="hidden" name="wpc_delete_css" value="1" />
            <p><?php esc_html_e( 'Delete all CSS rules injected by WP Commander. This will revert all visual changes made via the command bar.', 'wp-commander' ); ?></p>
            <button type="submit" class="button button-link-delete">
                <?php esc_html_e( 'Delete all WP Commander CSS', 'wp-commander' ); ?>
            </button>
        </form>

    </div><!-- .wpc-settings-wrap -->

    <script>
    const WPC_MODELS = <?php echo wp_json_encode( WPC_AI_Engine::MODELS ); ?>;

    function wpcToggleProviderFields(provider) {
        // Show/hide custom endpoint row
        const customRow = document.getElementById('wpc-custom-endpoint-row');
        if (customRow) {
            customRow.style.display = (provider === 'custom') ? '' : 'none';
        }
        // Update model hint
        const hints = document.getElementById('wpc-model-hints');
        const models = WPC_MODELS[provider] || [];
        if (hints) {
            hints.textContent = models.length
                ? 'Suggested: ' + models.join(', ')
                : 'Enter your custom model name.';
        }
    }

    // Init on page load
    document.addEventListener('DOMContentLoaded', function() {
        const sel = document.getElementById('wpc-provider-select');
        if (sel) wpcToggleProviderFields(sel.value);
    });
    </script>
    <?php
}
