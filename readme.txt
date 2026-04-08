=== WP Commander ===
Contributors: wpcommander
Tags: ai, artificial intelligence, page builder, site generator, command bar, openai, claude, gpt
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

AI-powered command bar for WordPress. Edit your site or generate a full modern website from a single prompt. Supports OpenAI, Claude, OpenRouter, Perplexity, and more.

== Description ==

**WP Commander** gives any WordPress user a floating AI command bar to:

* **Edit your existing site** using natural language — "Change the hero button color to orange"
* **Generate a full multi-page website** from a single prompt — "Create a modern dentist website with booking"
* **Clone a reference site** — paste any URL and AI recreates the structure, layout, and style
* **Auto-install required plugins** — AI recommends and installs what your site needs

**Works with ANY page builder:** Gutenberg, Elementor, Divi, Beaver Builder, Bricks, or plain WordPress.

= Multi-AI Support =

Configure your preferred AI provider:

* **OpenAI** — GPT-4o, GPT-4o-mini, GPT-4-Turbo
* **Anthropic (Claude)** — Claude Opus 4.6, Claude Sonnet 4.6, Claude Haiku 4.5
* **OpenRouter** — Access 100+ models through a single API
* **Perplexity** — Llama models with real-time web search
* **Custom** — Any OpenAI-compatible endpoint

= Features =

**Floating Command Bar**
* Opens with Ctrl+Shift+K (Windows) / Cmd+Shift+K (Mac)
* Edit Site tab + Generate Site tab
* Command history (last 10 commands, in-memory)
* Real-time CSS preview
* Undo last change button

**Site Editing**
* Change colors, fonts, spacing
* Update text content
* Modify button styles, backgrounds, layouts
* Works on Elementor widgets, Gutenberg blocks, Divi modules
* CSS changes preview instantly without page reload

**Site Generation**
* Modern, elegant multi-page websites
* Beautiful Gutenberg blocks with proper typography
* Auto-sets colors, fonts, navigation, front page
* Reference URL analysis — clone any site's structure
* Real-time build progress

**Auto Plugin Installer**
* AI recommends needed plugins (only free WordPress.org plugins)
* One-click install & activate all
* Per-plugin progress indicators
* Audit trail in wp_options

**Security (Non-Negotiable)**
* All AI calls server-side only — API keys never exposed to frontend
* Nonce verification on every endpoint
* Capability checks (edit_posts / install_plugins)
* Rate limiting (20 AI calls/hour/user, configurable)
* All input sanitized and output escaped

= Installation =

1. Upload the `wp-commander` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress
3. Go to **Settings → WP Commander**
4. Select your AI provider and enter your API key
5. The floating command bar appears on your site immediately

= Frequently Asked Questions =

= Which AI provider should I use? =

OpenAI GPT-4o gives the best results for site generation. Claude Sonnet 4.6 is excellent for editing commands. OpenRouter lets you use many models through one API key.

= Does it work with my page builder? =

Yes. WP Commander auto-detects Elementor, Divi, Beaver Builder, Bricks, and Gutenberg. It falls back to universal CSS injection for any other builder.

= Is my API key secure? =

Yes. API keys are encrypted with AES-256-CBC using WordPress's AUTH_KEY and stored in the database. They are never sent to the browser.

= Can I undo changes? =

Yes. Every edit has an "Undo Last Change" button. The plugin stores up to 20 undo entries per post.

= Will generated sites replace my existing content? =

Site generation creates NEW pages — it does not delete or modify your existing content unless you tell it to.

== Changelog ==

= 1.0.0 =
* Initial release
* Multi-AI provider support (OpenAI, Anthropic, OpenRouter, Perplexity, Custom)
* Floating command bar with Edit Site + Generate Site tabs
* Modern Gutenberg block templates for all section types
* Auto plugin installer with real-time progress
* Undo/redo system with snapshots
* Reference URL analyzer
* Full Elementor, Gutenberg, Divi, Beaver Builder, Bricks adapter support
* Conventional Commits git workflow

== Upgrade Notice ==

= 1.0.0 =
Initial release.
