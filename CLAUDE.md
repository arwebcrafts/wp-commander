# WP Commander — CLAUDE.md
## Project Overview
Build a WordPress plugin called **WP Commander** (`wp-commander`).

The plugin gives any WordPress user a floating AI command bar to:
1. **Edit their existing site** using natural language (change colors, update text, add sections)
2. **Generate a full multi-page website from a single prompt** (e.g. "Create a dentist website")
3. **Clone a reference site** by pasting any URL — AI analyzes it and recreates the structure, layout, and style inside WordPress

Works with ANY page builder: Gutenberg, Elementor, Divi, Beaver Builder, Bricks, or plain WordPress.

---

## File Structure
```
wp-commander/
├── wp-commander.php              ← Main plugin file
├── readme.txt
├── assets/
│   ├── css/
│   │   └── command-bar.css       ← Floating command bar UI styles
│   └── js/
│       └── command-bar.js        ← Command bar UI + API calls
├── includes/
│   ├── class-core.php            ← Plugin bootstrap
│   ├── class-ai-engine.php       ← OpenAI API integration + prompt builder
│   ├── class-site-scanner.php    ← Scans current page structure
│   ├── class-url-analyzer.php    ← Fetches + parses reference URL
│   ├── class-site-generator.php  ← Builds full multi-page sites
│   ├── class-executor.php        ← Routes actions to correct adapter
│   └── adapters/
│       ├── class-adapter-css.php         ← Universal CSS injection (fallback)
│       ├── class-adapter-gutenberg.php   ← Gutenberg block editor adapter
│       ├── class-adapter-elementor.php   ← Elementor _elementor_data adapter
│       ├── class-adapter-divi.php        ← Divi shortcode adapter
│       └── class-adapter-generic.php     ← WP REST API content adapter
└── admin/
    ├── settings-page.php         ← Admin settings (API key, model choice)
    └── class-admin.php
```

---

## Feature 1: Floating Command Bar

### UI Behavior
- Floating button (bottom-right corner) on BOTH frontend and wp-admin
- Keyboard shortcut: `Ctrl+Shift+K` (Windows) / `Cmd+Shift+K` (Mac) opens/closes it
- Clean modal overlay with a text input field
- "Generate Site" tab and "Edit Site" tab
- Command history (last 10 commands, stored in memory — NO localStorage)
- Shows a spinner while AI is processing
- Shows success/error message after execution
- "Undo Last Change" button after each edit

### Visibility
- Show on frontend only to logged-in users with `edit_posts` capability
- Show in wp-admin for all admin/editor users
- Add a toggle in Settings to enable/disable frontend bar

---

## Feature 2: Edit Existing Site via Command

### Flow
1. User types command: *"Change the hero button color to orange"*
2. `class-site-scanner.php` scans the current page:
   - Detects active builder (check post meta keys + DOM class signatures)
   - Builds a JSON "page map": sections, widgets, text blocks, IDs
3. Command + page map sent to OpenAI API via `class-ai-engine.php`
4. AI returns structured JSON action:
```json
{
  "action": "style_update",
  "target_selector": ".elementor-button, .wp-block-button__link",
  "builder": "elementor",
  "changes": {
    "background-color": "#FF8C00",
    "border-radius": "6px"
  },
  "elementor_widget_id": "abc123",
  "elementor_setting_key": "button_background_color"
}
```
5. `class-executor.php` routes to correct adapter
6. CSS adapter injects custom CSS immediately (live preview)
7. Builder adapter saves change permanently to database

### Builder Detection Logic (class-site-scanner.php)
```php
public function detect_builder( $post_id ) {
    if ( get_post_meta( $post_id, '_elementor_data', true ) ) return 'elementor';
    if ( get_post_meta( $post_id, '_fl_builder_data', true ) ) return 'beaver';
    if ( get_post_meta( $post_id, '_bricks_page_content_2', true ) ) return 'bricks';
    if ( has_blocks( get_post_field( 'post_content', $post_id ) ) ) return 'gutenberg';
    // Check post_content for Divi shortcodes
    $content = get_post_field( 'post_content', $post_id );
    if ( strpos( $content, '[et_pb_section' ) !== false ) return 'divi';
    return 'generic'; // fallback
}
```

---

## Feature 3: Full Site Generator

### Command Examples
- *"Create a full dentist website"*
- *"Build a restaurant site with menu and reservations page"*
- *"Create a law firm website. Reference: https://example.com"*

### Flow
1. Detect if command is a "generate site" intent (AI classifies it)
2. If reference URL provided → `class-url-analyzer.php` fetches and parses it:
   - Use WordPress `wp_remote_get()` to fetch HTML
   - Extract: page titles from nav links, color values from inline styles/CSS, font families, section structure from HTML landmarks
   - Return a `$reference_data` array
3. Send command + reference_data to AI
4. AI returns a full **Site Blueprint** JSON:
```json
{
  "site_name": "Bright Smile Dental",
  "tagline": "Your Family's Trusted Dentist",
  "color_primary": "#1A6B9A",
  "color_accent": "#F5A623",
  "font_heading": "Playfair Display",
  "font_body": "Open Sans",
  "pages": [
    {
      "title": "Home",
      "slug": "home",
      "set_as_front_page": true,
      "sections": ["hero", "services_grid", "about_teaser", "testimonials", "cta_banner", "contact_footer"]
    },
    {
      "title": "About Us",
      "slug": "about",
      "sections": ["page_hero", "our_story", "team_grid", "certifications"]
    },
    {
      "title": "Services",
      "slug": "services",
      "sections": ["page_hero", "services_list", "pricing_table", "faq"]
    },
    {
      "title": "Contact",
      "slug": "contact",
      "sections": ["page_hero", "contact_form", "map_embed", "business_info"]
    }
  ]
}
```
5. `class-site-generator.php` creates all pages via `wp_insert_post()`
6. For each page, generates section HTML/blocks appropriate to the detected builder
7. Sets front page, creates navigation menu, applies global colors via `set_theme_mod()`
8. Shows progress bar in UI while building (use REST API endpoint for polling)

---

## Feature 4: Reference URL Analyzer (class-url-analyzer.php)

```php
public function analyze( $url ) {
    $response = wp_remote_get( $url, ['timeout' => 15] );
    if ( is_wp_error( $response ) ) return [];
    
    $html = wp_remote_retrieve_body( $response );
    
    return [
        'nav_links'   => $this->extract_nav_links( $html ),
        'colors'      => $this->extract_colors( $html ),
        'fonts'       => $this->extract_fonts( $html ),
        'sections'    => $this->extract_section_landmarks( $html ),
        'page_count'  => count( $this->extract_nav_links( $html ) ),
    ];
}
```
Use regex + DOMDocument to parse HTML. Do NOT use external scraping APIs.

---

## REST API Endpoints

Register all under namespace `wp-commander/v1`:

| Endpoint | Method | Description |
|----------|--------|-------------|
| `/execute-command` | POST | Run an edit command on current page |
| `/generate-site` | POST | Start full site generation |
| `/generation-status` | GET | Poll generation progress |
| `/undo-last` | POST | Undo last executed change |
| `/analyze-url` | POST | Analyze a reference URL |

All endpoints require:
```php
'permission_callback' => function() {
    return current_user_can( 'edit_posts' );
}
```
All inputs must be sanitized with `sanitize_text_field()`, `esc_url_raw()`, `wp_verify_nonce()`.

---

## AI Engine (class-ai-engine.php)

### System Prompt for Edit Commands
```
You are a WordPress site editing assistant. You receive a page structure JSON and a user command.
Return ONLY a valid JSON object with these fields: action, target_selector, builder, changes, [optional: elementor_widget_id, elementor_setting_key, content_update].
Never return explanatory text. Only JSON.
```

### System Prompt for Site Generation
```
You are a WordPress site architect. Generate a complete Site Blueprint JSON for the described website type.
Include: site_name, tagline, color_primary, color_accent, font_heading, font_body, and a pages array.
Each page must have: title, slug, set_as_front_page (boolean, only one true), and a sections array.
Return ONLY valid JSON.
```

### API Call Settings
- Model: `gpt-4o` (default), configurable in settings to `gpt-4o-mini` or `claude-3-5-sonnet`
- Temperature: `0.3` (deterministic output)
- Max tokens: `2000` for edits, `4000` for site generation
- Store API key in `wp_options` table, encrypted with `AUTH_KEY`

---

## Settings Page (admin/settings-page.php)

Fields:
1. **OpenAI API Key** (password field, saved encrypted)
2. **AI Model** (dropdown: GPT-4o, GPT-4o-mini, Claude 3.5 Sonnet)
3. **Enable on Frontend** (checkbox)
4. **Allowed Roles** (checkboxes: Administrator, Editor, Author)
5. **Command Bar Position** (Bottom Right / Bottom Left)
6. **Danger Zone** — "Delete all WP Commander generated CSS" button

---

## Security Requirements (NON-NEGOTIABLE)

Every REST endpoint MUST have:
- `wp_verify_nonce( $_REQUEST['_wpnonce'], 'wp_commander_nonce' )` 
- `current_user_can( 'edit_posts' )` check
- All user input sanitized before use
- API key NEVER exposed in frontend JS (all AI calls happen server-side via PHP)
- Output escaped with `esc_html()`, `esc_attr()`, `wp_kses_post()`
- Rate limiting: max 20 AI calls per hour per user (store count in transients)

---

## CSS Injection System (class-adapter-css.php)

For visual changes (colors, fonts, spacing, border-radius):
1. Store changes in `wp_options` as `wpc_custom_css_{post_id}`
2. Hook into `wp_head` to output `<style>` tag with all changes for current page
3. For global changes (site-wide), use `set_theme_mod( 'custom_css' )`
4. Each CSS rule tagged with a comment for undo: `/* WPC-CHANGE-{timestamp} */`

---

## Elementor Adapter (class-adapter-elementor.php)

```php
public function update_widget_setting( $post_id, $widget_id, $setting_key, $value ) {
    $data = json_decode( get_post_meta( $post_id, '_elementor_data', true ), true );
    // Recursively find widget by ID, update setting
    $data = $this->find_and_update( $data, $widget_id, $setting_key, $value );
    update_post_meta( $post_id, '_elementor_data', wp_slash( json_encode( $data ) ) );
    // Clear Elementor cache
    if ( class_exists( '\Elementor\Plugin' ) ) {
        \Elementor\Plugin::$instance->files_manager->clear_cache();
    }
}
```

---

## Gutenberg Adapter (class-adapter-gutenberg.php)

For block content changes, update post content via `wp_update_post()`.
Parse blocks with `parse_blocks()`, modify target block attributes, serialize back with `serialize_blocks()`.

---

## Plugin Header (wp-commander.php)

```php
<?php
/**
 * Plugin Name: WP Commander
 * Plugin URI: https://wpcommander.io
 * Description: AI-powered command bar for WordPress. Edit your site or generate a full website from a single prompt — works with any page builder.
 * Version: 1.0.0
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-commander
 * Requires at least: 6.0
 * Requires PHP: 8.0
 */
```

---

## What NOT To Do
- Do NOT use localStorage or sessionStorage (sandbox incompatibility)
- Do NOT make AI calls from frontend JavaScript (security — server-side only)
- Do NOT hardcode the API key anywhere
- Do NOT skip nonce verification on any endpoint
- Do NOT output unescaped user data anywhere in HTML

---

## Build Order
1. `wp-commander.php` + `class-core.php` (bootstrap, register hooks)
2. `admin/settings-page.php` + `class-admin.php` (API key storage)
3. `command-bar.css` + `command-bar.js` (UI)
4. REST API endpoints
5. `class-ai-engine.php` (OpenAI integration)
6. `class-site-scanner.php` + `class-adapter-css.php` (edit commands, Phase 1)
7. `class-url-analyzer.php` + `class-site-generator.php` (site generation)
8. `class-adapter-elementor.php` + `class-adapter-gutenberg.php` (deep adapters)

---

## Feature 5: Auto Plugin Installer (class-plugin-installer.php)

### Overview
When AI generates a Site Blueprint, it also returns a `required_plugins` array.
WP Commander shows a confirmation panel to the user, then auto-installs + activates all plugins silently.

### AI Returns This in Site Blueprint
```json
{
  "site_name": "Bright Smile Dental",
  "required_plugins": [
    {
      "name": "Elementor",
      "slug": "elementor",
      "main_file": "elementor/elementor.php",
      "reason": "Page builder for all layouts"
    },
    {
      "name": "Contact Form 7",
      "slug": "contact-form-7",
      "main_file": "contact-form-7/wp-contact-form-7.php",
      "reason": "Patient contact and inquiry forms"
    },
    {
      "name": "Bookly",
      "slug": "bookly-responsive-appointment-booking-tool",
      "main_file": "bookly-responsive-appointment-booking-tool/main.php",
      "reason": "Appointment booking system"
    },
    {
      "name": "WP Google Maps",
      "slug": "wp-google-maps",
      "main_file": "wp-google-maps/wpGoogleMaps.php",
      "reason": "Clinic location map"
    }
  ],
  "pages": [...]
}
```

### class-plugin-installer.php — Core Logic

```php
class WPC_Plugin_Installer {

    /**
     * Check if a plugin is already installed
     */
    public function is_installed( $main_file ) {
        return file_exists( WP_PLUGIN_DIR . '/' . $main_file );
    }

    /**
     * Check if a plugin is active
     */
    public function is_active( $main_file ) {
        return is_plugin_active( $main_file );
    }

    /**
     * Install a single plugin from WordPress.org repository
     * Returns: ['success' => bool, 'message' => string]
     */
    public function install_plugin( $slug ) {
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Get plugin info from WordPress.org
        $api = plugins_api( 'plugin_information', [
            'slug'   => sanitize_text_field( $slug ),
            'fields' => [ 'short_description' => false, 'sections' => false ],
        ]);

        if ( is_wp_error( $api ) ) {
            return [ 'success' => false, 'message' => $api->get_error_message() ];
        }

        // Silent install (no output)
        $skin     = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader( $skin );
        $result   = $upgrader->install( $api->download_link );

        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => $result->get_error_message() ];
        }

        return [ 'success' => true, 'message' => "Installed: {$api->name}" ];
    }

    /**
     * Activate an installed plugin
     */
    public function activate_plugin( $main_file ) {
        $result = activate_plugin( sanitize_text_field( $main_file ) );
        if ( is_wp_error( $result ) ) {
            return [ 'success' => false, 'message' => $result->get_error_message() ];
        }
        return [ 'success' => true ];
    }

    /**
     * Install + activate multiple plugins
     * Called from REST API endpoint
     * Returns progress array for UI polling
     */
    public function install_and_activate_batch( $plugins ) {
        $results = [];
        foreach ( $plugins as $plugin ) {
            $slug      = sanitize_text_field( $plugin['slug'] );
            $main_file = sanitize_text_field( $plugin['main_file'] );

            // Skip if already active
            if ( $this->is_active( $main_file ) ) {
                $results[] = [ 'slug' => $slug, 'status' => 'already_active' ];
                continue;
            }

            // Install if not installed
            if ( ! $this->is_installed( $main_file ) ) {
                $install = $this->install_plugin( $slug );
                if ( ! $install['success'] ) {
                    $results[] = [ 'slug' => $slug, 'status' => 'install_failed', 'error' => $install['message'] ];
                    continue;
                }
            }

            // Activate
            $activate  = $this->activate_plugin( $main_file );
            $results[] = [
                'slug'   => $slug,
                'status' => $activate['success'] ? 'activated' : 'activation_failed',
            ];
        }
        return $results;
    }
}
```

### REST API Endpoint for Plugin Installation

```
POST /wp-json/wp-commander/v1/install-plugins
Body: { "plugins": [...], "_wpnonce": "..." }
Permission: current_user_can('install_plugins') — only admins
Response: { "results": [...], "all_done": true/false }
```

### UI Flow in command-bar.js

After AI returns the site blueprint with required_plugins:
1. Show "Required Plugins" panel — list each plugin with name + reason
2. Show three buttons:
   - **"Install & Activate All"** — calls `/install-plugins` endpoint for all
   - **"Skip Already Installed"** — only installs missing ones
   - **"I'll do it manually"** — skips auto-install, proceeds to site build
3. Show real-time progress:
   - Each plugin gets a status indicator: ⏳ Installing → ✅ Activated
   - Show spinner per plugin, not a global spinner
4. After all plugins activated → automatically trigger site generation

### Plugin Recommendation Map (in class-ai-engine.php System Prompt)
Include this in the site generation system prompt so AI picks correct slugs:

```
When recommending plugins, always use their exact WordPress.org slug and main file path.
Common mappings:
- Contact forms: "contact-form-7" / "contact-form-7/wp-contact-form-7.php"
- Page builder: "elementor" / "elementor/elementor.php"  
- SEO: "wordpress-seo" / "wordpress-seo/wp-seo.php"
- eCommerce: "woocommerce" / "woocommerce/woocommerce.php"
- Booking: "bookly-responsive-appointment-booking-tool" / "bookly-responsive-appointment-booking-tool/main.php"
- Google Maps: "wp-google-maps" / "wp-google-maps/wpGoogleMaps.php"
- Gallery: "envira-gallery-lite" / "envira-gallery-lite/envira-gallery-lite.php"
- Cache: "w3-total-cache" / "w3-total-cache/w3-total-cache.php"
- Security: "wordfence" / "wordfence/wordfence.php"
- Backup: "updraftplus" / "updraftplus/updraftplus.php"
Only recommend FREE plugins available on WordPress.org repository.
Maximum 5 plugins per site to avoid overwhelming the user.
```

### Security Note
- `install_plugins` capability required (admins only) for install endpoint
- `activate_plugins` capability required for activate endpoint
- Never allow installing plugins from external ZIP URLs — WordPress.org repository only
- Log all installed plugins to `wp_options` under `wpc_installed_plugins` for audit trail
