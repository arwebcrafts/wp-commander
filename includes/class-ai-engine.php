<?php
/**
 * WPC_AI_Engine — Multi-provider AI: OpenAI, Anthropic, OpenRouter, Perplexity, custom.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_AI_Engine {

    // ── Supported providers ─────────────────────────────────────────────────
    const PROVIDERS = [
        'openai'     => 'OpenAI',
        'anthropic'  => 'Anthropic (Claude)',
        'openrouter' => 'OpenRouter',
        'perplexity' => 'Perplexity',
        'custom'     => 'Custom (OpenAI-compatible)',
    ];

    // ── Provider → default models ───────────────────────────────────────────
    const MODELS = [
        'openai'     => [ 'gpt-4o', 'gpt-4o-mini', 'gpt-4-turbo', 'gpt-3.5-turbo' ],
        'anthropic'  => [ 'claude-opus-4-6', 'claude-sonnet-4-6', 'claude-haiku-4-5-20251001', 'claude-3-5-sonnet-20241022' ],
        'openrouter' => [ 'openai/gpt-4o', 'anthropic/claude-sonnet-4-6', 'meta-llama/llama-3.1-405b-instruct', 'google/gemini-pro-1.5' ],
        'perplexity' => [ 'llama-3.1-sonar-huge-128k-online', 'llama-3.1-sonar-large-128k-online', 'llama-3.1-sonar-small-128k-online' ],
        'custom'     => [],
    ];

    private string $provider;
    private string $model;
    private string $api_key;
    private array  $settings;

    public function __construct() {
        $this->settings = get_option( 'wpc_settings', [] );
        $this->provider = $this->settings['ai_provider'] ?? 'openai';
        $this->model    = $this->settings['ai_model']    ?? 'gpt-4o';
        $this->api_key  = $this->get_api_key( $this->provider );
    }

    // ── Key management ──────────────────────────────────────────────────────
    private function get_api_key( string $provider ): string {
        $key_option = 'wpc_api_key_' . $provider;
        $encrypted  = get_option( $key_option, '' );
        return $encrypted ? $this->decrypt( $encrypted ) : '';
    }

    public static function save_api_key( string $provider, string $raw_key ): void {
        $engine = new self();
        update_option( 'wpc_api_key_' . sanitize_key( $provider ), $engine->encrypt( $raw_key ) );
    }

    private function encrypt( string $value ): string {
        if ( empty( $value ) ) return '';
        $secret = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'wpc-fallback-key';
        $iv     = random_bytes( 16 );
        $enc    = openssl_encrypt( $value, 'aes-256-cbc', substr( hash( 'sha256', $secret ), 0, 32 ), 0, $iv );
        return base64_encode( $iv . '::' . $enc );
    }

    private function decrypt( string $value ): string {
        if ( empty( $value ) ) return '';
        $secret  = defined( 'AUTH_KEY' ) && AUTH_KEY ? AUTH_KEY : 'wpc-fallback-key';
        $decoded = base64_decode( $value );
        $parts   = explode( '::', $decoded, 2 );
        if ( count( $parts ) !== 2 ) return '';
        [ $iv, $enc ] = $parts;
        return (string) openssl_decrypt( $enc, 'aes-256-cbc', substr( hash( 'sha256', $secret ), 0, 32 ), 0, $iv );
    }

    // ── System prompts ──────────────────────────────────────────────────────
    private function system_prompt_edit(): string {
        return <<<'PROMPT'
You are a WordPress site editing assistant. You receive a page structure JSON and a user command.
Return ONLY a valid JSON object with exactly these fields:
{
  "action": "style_update" | "content_update" | "block_update",
  "target_selector": "CSS selector string",
  "builder": "gutenberg" | "elementor" | "divi" | "beaver" | "bricks" | "generic",
  "changes": { "css-property": "value" },
  "elementor_widget_id": "optional",
  "elementor_setting_key": "optional",
  "content_update": "optional new HTML/text content",
  "undo_key": "unique key for reverting this change"
}
Never return explanatory text. Only JSON. Never include code fences.
PROMPT;
    }

    private function system_prompt_generate(): string {
        return <<<'PROMPT'
You are a WordPress site architect and modern web designer.
Generate a complete Site Blueprint JSON for the described website.
The site MUST be modern, elegant, and professional — never plain or generic.

Return ONLY valid JSON (no code fences, no commentary) with this structure:
{
  "site_name": "...",
  "tagline": "...",
  "color_primary": "#hex",
  "color_secondary": "#hex",
  "color_accent": "#hex",
  "color_bg": "#hex",
  "color_text": "#hex",
  "font_heading": "Google Font name",
  "font_body": "Google Font name",
  "industry": "dentist|restaurant|law|ecommerce|portfolio|...",
  "design_style": "minimal|bold|elegant|playful|corporate",
  "required_plugins": [
    {
      "name": "Plugin Name",
      "slug": "plugin-slug",
      "main_file": "plugin-slug/main.php",
      "reason": "Why needed"
    }
  ],
  "pages": [
    {
      "title": "...",
      "slug": "...",
      "set_as_front_page": true,
      "sections": ["hero", "services_grid", ...]
    }
  ]
}

Section types available: hero, services_grid, about_teaser, testimonials, cta_banner,
contact_footer, page_hero, our_story, team_grid, certifications, services_list,
pricing_table, faq, contact_form, map_embed, business_info, gallery, portfolio_grid,
blog_feed, stats_counter, video_section, newsletter_signup.

Rules:
- Use modern Google Fonts (e.g. Inter, Poppins, Playfair Display, Raleway, Montserrat)
- Choose colors that match the industry and design style
- Max 5 plugins, all free from WordPress.org
- Include set_as_front_page: true on exactly ONE page
- Minimum 4 pages for any site

When recommending plugins, use exact WordPress.org slugs:
- Contact forms: "contact-form-7" / "contact-form-7/wp-contact-form-7.php"
- Page builder: "elementor" / "elementor/elementor.php"
- SEO: "wordpress-seo" / "wordpress-seo/wp-seo.php"
- eCommerce: "woocommerce" / "woocommerce/woocommerce.php"
- Booking: "bookly-responsive-appointment-booking-tool" / "bookly-responsive-appointment-booking-tool/main.php"
- Maps: "wp-google-maps" / "wp-google-maps/wpGoogleMaps.php"
- Gallery: "envira-gallery-lite" / "envira-gallery-lite/envira-gallery-lite.php"
- Cache: "w3-total-cache" / "w3-total-cache/w3-total-cache.php"
- Security: "wordfence" / "wordfence/wordfence.php"
- Backup: "updraftplus" / "updraftplus/updraftplus.php"
PROMPT;
    }

    // ── Main public methods ─────────────────────────────────────────────────
    public function execute_command( string $command, array $page_map ): array|WP_Error {
        $messages = [
            [
                'role'    => 'system',
                'content' => $this->system_prompt_edit(),
            ],
            [
                'role'    => 'user',
                'content' => "Page structure:\n" . wp_json_encode( $page_map, JSON_UNESCAPED_UNICODE ) .
                             "\n\nCommand: " . $command,
            ],
        ];

        $raw = $this->call( $messages, 2000 );
        if ( is_wp_error( $raw ) ) return $raw;

        $decoded = json_decode( $this->strip_fences( $raw ), true );
        if ( ! is_array( $decoded ) ) {
            return new WP_Error( 'ai_parse', __( 'AI returned invalid JSON.', 'wp-commander' ) );
        }
        return $decoded;
    }

    public function generate_site_blueprint( string $prompt, array $reference_data = [] ): array|WP_Error {
        $context = $prompt;
        if ( ! empty( $reference_data ) ) {
            $context .= "\n\nReference site data:\n" . wp_json_encode( $reference_data, JSON_UNESCAPED_UNICODE );
        }

        $messages = [
            [
                'role'    => 'system',
                'content' => $this->system_prompt_generate(),
            ],
            [
                'role'    => 'user',
                'content' => $context,
            ],
        ];

        $raw = $this->call( $messages, 4000 );
        if ( is_wp_error( $raw ) ) return $raw;

        $decoded = json_decode( $this->strip_fences( $raw ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['pages'] ) ) {
            return new WP_Error( 'ai_parse', __( 'AI returned invalid site blueprint.', 'wp-commander' ) );
        }
        return $decoded;
    }

    // ── Provider dispatch ───────────────────────────────────────────────────
    private function call( array $messages, int $max_tokens ): string|WP_Error {
        if ( empty( $this->api_key ) ) {
            return new WP_Error( 'no_api_key', sprintf(
                __( 'No API key configured for provider: %s', 'wp-commander' ),
                $this->provider
            ) );
        }

        return match ( $this->provider ) {
            'anthropic'  => $this->call_anthropic( $messages, $max_tokens ),
            'openrouter' => $this->call_openrouter( $messages, $max_tokens ),
            'perplexity' => $this->call_perplexity( $messages, $max_tokens ),
            'custom'     => $this->call_custom( $messages, $max_tokens ),
            default      => $this->call_openai( $messages, $max_tokens ),
        };
    }

    // ── OpenAI ──────────────────────────────────────────────────────────────
    private function call_openai( array $messages, int $max_tokens ): string|WP_Error {
        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $max_tokens,
        ];

        $response = wp_remote_post(
            'https://api.openai.com/v1/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        return $this->parse_openai_compat_response( $response );
    }

    // ── Anthropic ───────────────────────────────────────────────────────────
    private function call_anthropic( array $messages, int $max_tokens ): string|WP_Error {
        // Anthropic uses a separate system param
        $system  = '';
        $user_msgs = [];
        foreach ( $messages as $msg ) {
            if ( $msg['role'] === 'system' ) {
                $system = $msg['content'];
            } else {
                $user_msgs[] = $msg;
            }
        }

        $body = [
            'model'      => $this->model ?: 'claude-sonnet-4-6',
            'max_tokens' => $max_tokens,
            'messages'   => $user_msgs,
        ];
        if ( $system ) {
            $body['system'] = $system;
        }

        $response = wp_remote_post(
            'https://api.anthropic.com/v1/messages',
            [
                'timeout' => 60,
                'headers' => [
                    'x-api-key'         => $this->api_key,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type'      => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? 'Anthropic API error';
            return new WP_Error( 'anthropic_error', esc_html( $msg ) );
        }

        return $data['content'][0]['text'] ?? '';
    }

    // ── OpenRouter (OpenAI-compatible) ──────────────────────────────────────
    private function call_openrouter( array $messages, int $max_tokens ): string|WP_Error {
        $body = [
            'model'       => $this->model ?: 'openai/gpt-4o',
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $max_tokens,
        ];

        $response = wp_remote_post(
            'https://openrouter.ai/api/v1/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'HTTP-Referer'  => get_site_url(),
                    'X-Title'       => get_bloginfo( 'name' ),
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        return $this->parse_openai_compat_response( $response );
    }

    // ── Perplexity (OpenAI-compatible) ──────────────────────────────────────
    private function call_perplexity( array $messages, int $max_tokens ): string|WP_Error {
        $body = [
            'model'       => $this->model ?: 'llama-3.1-sonar-large-128k-online',
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $max_tokens,
        ];

        $response = wp_remote_post(
            'https://api.perplexity.ai/chat/completions',
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        return $this->parse_openai_compat_response( $response );
    }

    // ── Custom endpoint (OpenAI-compatible) ─────────────────────────────────
    private function call_custom( array $messages, int $max_tokens ): string|WP_Error {
        $endpoint = $this->settings['custom_endpoint'] ?? '';
        if ( empty( $endpoint ) ) {
            return new WP_Error( 'no_endpoint', __( 'Custom API endpoint not configured.', 'wp-commander' ) );
        }

        $body = [
            'model'       => $this->model,
            'messages'    => $messages,
            'temperature' => 0.3,
            'max_tokens'  => $max_tokens,
        ];

        $response = wp_remote_post(
            esc_url_raw( $endpoint ),
            [
                'timeout' => 60,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->api_key,
                    'Content-Type'  => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
            ]
        );

        return $this->parse_openai_compat_response( $response );
    }

    // ── Parse OpenAI-compatible response ────────────────────────────────────
    private function parse_openai_compat_response( array|WP_Error $response ): string|WP_Error {
        if ( is_wp_error( $response ) ) return $response;

        $code = wp_remote_retrieve_response_code( $response );
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );

        if ( $code !== 200 ) {
            $msg = $data['error']['message'] ?? ( 'API error ' . $code );
            return new WP_Error( 'api_error', esc_html( $msg ) );
        }

        return $data['choices'][0]['message']['content'] ?? '';
    }

    // ── Strip markdown code fences from AI output ───────────────────────────
    private function strip_fences( string $raw ): string {
        $raw = trim( $raw );
        // Remove ```json ... ``` or ``` ... ```
        $raw = preg_replace( '/^```(?:json)?\s*/i', '', $raw );
        $raw = preg_replace( '/\s*```\s*$/', '', $raw );
        return trim( $raw );
    }

    // ── Public helper: current provider label ───────────────────────────────
    public function get_provider_label(): string {
        return self::PROVIDERS[ $this->provider ] ?? $this->provider;
    }

    public function get_model(): string {
        return $this->model;
    }
}
