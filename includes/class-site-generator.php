<?php
/**
 * WPC_Site_Generator — creates a full modern, elegant multi-page WordPress site
 * from an AI-generated Site Blueprint. Uses Gutenberg blocks + custom CSS.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_Site_Generator {

    private array  $blueprint = [];
    private int    $uid       = 0;
    private string $primary   = '#6366f1';
    private string $secondary = '#8b5cf6';
    private string $accent    = '#10b981';
    private string $bg        = '#ffffff';
    private string $text_col  = '#1e293b';
    private string $font_h    = 'Inter';
    private string $font_b    = 'Inter';

    // ── Async build (stores state, built page-by-page via repeated polling) ─
    public function build_async( array $blueprint, int $uid ): void {
        // Store blueprint; actual build runs on next admin_init via transient check
        set_transient( 'wpc_pending_build_' . $uid, $blueprint, HOUR_IN_SECONDS );
        set_transient( 'wpc_gen_status_' . $uid, [
            'status'   => 'queued',
            'progress' => 2,
            'message'  => 'Build queued…',
            'steps'    => [],
        ], HOUR_IN_SECONDS );

        // Trigger immediate build via shutdown action (runs at end of current request)
        add_action( 'shutdown', function () use ( $blueprint, $uid ) {
            $this->build( $blueprint, $uid );
        } );
    }

    // ── Synchronous full build ───────────────────────────────────────────
    public function build( array $blueprint, int $uid ): void {
        $this->blueprint = $blueprint;
        $this->uid       = $uid;
        $this->load_colors();

        $pages       = $blueprint['pages'] ?? [];
        $total_pages = count( $pages );
        $built_ids   = [];

        $this->update_status( 5, 'Preparing your site…', [] );

        // 1. Inject global CSS (colors, fonts)
        $this->inject_global_css();
        $this->update_status( 10, 'Applying global styles…', [
            [ 'label' => 'Global styles applied', 'done' => true ],
        ] );

        // 2. Enqueue Google Fonts
        $this->register_google_fonts();

        // 3. Build each page
        $steps = [ [ 'label' => 'Global styles applied', 'done' => true ] ];
        foreach ( $pages as $index => $page_def ) {
            $pct   = 10 + (int) ( ( $index / $total_pages ) * 75 );
            $title = sanitize_text_field( $page_def['title'] ?? 'Page ' . ( $index + 1 ) );

            $steps[] = [ 'label' => 'Building: ' . $title, 'active' => true ];
            $this->update_status( $pct, 'Building page: ' . $title, $steps );

            $post_id = $this->build_page( $page_def );
            if ( $post_id ) {
                $built_ids[] = $post_id;
                if ( ! empty( $page_def['set_as_front_page'] ) ) {
                    update_option( 'page_on_front', $post_id );
                    update_option( 'show_on_front', 'page' );
                }
            }

            // Mark step done
            $steps[ count( $steps ) - 1 ] = [ 'label' => '✓ Built: ' . $title, 'done' => true ];
        }

        // 4. Create navigation menu
        $this->update_status( 87, 'Creating navigation menu…', $steps );
        $menu_id = $this->create_nav_menu( $blueprint['site_name'] ?? 'Main Menu', $built_ids, $pages );
        $steps[] = [ 'label' => '✓ Navigation menu created', 'done' => true ];

        // 5. Site identity
        $this->update_status( 92, 'Setting site identity…', $steps );
        $this->set_site_identity( $blueprint );
        $steps[] = [ 'label' => '✓ Site identity set', 'done' => true ];

        // 6. Log installed pages
        $this->update_status( 97, 'Finalizing…', $steps );
        update_option( 'wpc_generated_pages', $built_ids );
        delete_transient( 'wpc_blueprint_' . $uid );
        delete_transient( 'wpc_pending_build_' . $uid );

        $front_id  = $built_ids[0] ?? 0;
        $steps[]   = [ 'label' => '✓ Site ready!', 'done' => true ];

        $this->update_status( 100, 'Your site is ready! 🎉', $steps, 'complete', [
            'front_page_url' => $front_id ? get_permalink( $front_id ) : home_url(),
            'pages_created'  => count( $built_ids ),
        ] );
    }

    // ── Build a single page ──────────────────────────────────────────────
    private function build_page( array $page_def ): int {
        $title    = sanitize_text_field( $page_def['title']   ?? 'Page' );
        $slug     = sanitize_title(      $page_def['slug']    ?? $title );
        $sections = (array) ( $page_def['sections']  ?? [] );

        // Build Gutenberg block content from section list
        $content = '';
        foreach ( $sections as $section_name ) {
            $content .= $this->render_section( $section_name, $title );
        }

        // Check if page with this slug already exists
        $existing = get_page_by_path( $slug );
        if ( $existing ) {
            wp_update_post( [
                'ID'           => $existing->ID,
                'post_title'   => $title,
                'post_content' => $content,
                'post_status'  => 'publish',
            ] );
            return $existing->ID;
        }

        $post_id = wp_insert_post( [
            'post_title'   => $title,
            'post_name'    => $slug,
            'post_content' => $content,
            'post_status'  => 'publish',
            'post_type'    => 'page',
            'post_author'  => $this->uid ?: get_current_user_id(),
        ] );

        return is_wp_error( $post_id ) ? 0 : (int) $post_id;
    }

    // ── Section renderer ─────────────────────────────────────────────────
    private function render_section( string $section, string $page_title ): string {
        $site_name = esc_html( $this->blueprint['site_name'] ?? 'Our Business' );
        $tagline   = esc_html( $this->blueprint['tagline']   ?? 'Welcome to our site' );
        $industry  = sanitize_key( $this->blueprint['industry'] ?? 'business' );

        switch ( $section ) {
            case 'hero':
                return $this->section_hero( $site_name, $tagline );
            case 'page_hero':
                return $this->section_page_hero( $page_title );
            case 'services_grid':
                return $this->section_services_grid( $industry );
            case 'about_teaser':
                return $this->section_about_teaser( $site_name );
            case 'testimonials':
                return $this->section_testimonials();
            case 'cta_banner':
                return $this->section_cta_banner( $site_name );
            case 'contact_footer':
                return $this->section_contact_footer( $site_name );
            case 'our_story':
                return $this->section_our_story( $site_name );
            case 'team_grid':
                return $this->section_team_grid();
            case 'certifications':
                return $this->section_certifications();
            case 'services_list':
                return $this->section_services_list( $industry );
            case 'pricing_table':
                return $this->section_pricing_table();
            case 'faq':
                return $this->section_faq( $industry );
            case 'contact_form':
                return $this->section_contact_form();
            case 'map_embed':
                return $this->section_map_embed();
            case 'business_info':
                return $this->section_business_info( $site_name );
            case 'stats_counter':
                return $this->section_stats_counter();
            case 'newsletter_signup':
                return $this->section_newsletter();
            case 'gallery':
                return $this->section_gallery();
            case 'blog_feed':
                return $this->section_blog_feed();
            default:
                return '';
        }
    }

    // ── Section: Hero ────────────────────────────────────────────────────
    private function section_hero( string $name, string $tagline ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"0","bottom":"0"}},"color":{"gradient":"linear-gradient(135deg, %1$s 0%%, %2$s 100%%)"}}} -->
<div class="wp-block-group alignfull wpc-hero" style="background:linear-gradient(135deg,%1$s 0%%,%2$s 100%%)">
<!-- wp:group {"style":{"spacing":{"padding":{"top":"140px","bottom":"140px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group wpc-hero-inner">
<!-- wp:heading {"level":1,"textAlign":"center","style":{"typography":{"fontSize":"clamp(2.5rem,5vw,4.5rem)","fontWeight":"700","lineHeight":"1.15"},"color":{"text":"#ffffff"}}} -->
<h1 class="wp-block-heading has-text-align-center" style="color:#ffffff;font-size:clamp(2.5rem,5vw,4.5rem);font-weight:700;line-height:1.15">%3$s</h1>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"1.2rem","lineHeight":"1.7"},"color":{"text":"rgba(255,255,255,0.88)"},"spacing":{"margin":{"top":"24px"}}}} -->
<p class="has-text-align-center" style="color:rgba(255,255,255,0.88);font-size:1.2rem;line-height:1.7;margin-top:24px">%4$s</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"margin":{"top":"40px"}}}} -->
<div class="wp-block-buttons" style="margin-top:40px">
<!-- wp:button {"style":{"border":{"radius":"50px"},"spacing":{"padding":{"top":"18px","bottom":"18px","left":"40px","right":"40px"}},"color":{"text":"%1$s","background":"#ffffff"},"typography":{"fontWeight":"700","fontSize":"1rem"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="#contact" style="border-radius:50px;padding:18px 40px;background:#ffffff;color:%1$s;font-weight:700">Get Started Today</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline","style":{"border":{"radius":"50px","color":"rgba(255,255,255,0.7)","width":"2px"},"spacing":{"padding":{"top":"18px","bottom":"18px","left":"40px","right":"40px"}},"color":{"text":"#ffffff"},"typography":{"fontWeight":"600","fontSize":"1rem"}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" style="border-radius:50px;padding:18px 40px;border:2px solid rgba(255,255,255,0.7);color:#ffffff;font-weight:600">Learn More</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->',
            $this->primary, $this->secondary,
            $name, $tagline
        );
    }

    // ── Section: Page Hero (inner pages) ────────────────────────────────
    private function section_page_hero( string $title ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"80px","bottom":"80px"}},"color":{"gradient":"linear-gradient(135deg,%1$s 0%%,%2$s 100%%)"}}} -->
<div class="wp-block-group alignfull wpc-page-hero" style="background:linear-gradient(135deg,%1$s 0%%,%2$s 100%%);padding:80px 5%%">
<!-- wp:heading {"level":1,"textAlign":"center","style":{"color":{"text":"#ffffff"},"typography":{"fontSize":"clamp(2rem,4vw,3.5rem)","fontWeight":"700"}}} -->
<h1 class="wp-block-heading has-text-align-center" style="color:#ffffff;font-size:clamp(2rem,4vw,3.5rem);font-weight:700">%3$s</h1>
<!-- /wp:heading -->
</div>
<!-- /wp:group -->',
            $this->primary, $this->secondary, $title
        );
    }

    // ── Section: Services Grid ───────────────────────────────────────────
    private function section_services_grid( string $industry ): string {
        $services = $this->get_industry_services( $industry );
        $cards    = '';
        foreach ( $services as $svc ) {
            $cards .= sprintf( '
<!-- wp:group {"style":{"border":{"radius":"16px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"32px","bottom":"32px","left":"28px","right":"28px"}},"color":{"background":"#ffffff"}},"className":"wpc-service-card"} -->
<div class="wp-block-group wpc-service-card" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:32px 28px;box-shadow:0 2px 16px rgba(0,0,0,0.06);transition:transform 0.2s,box-shadow 0.2s">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"2.5rem"}}} -->
<p style="font-size:2.5rem;margin:0 0 16px">%1$s</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"1.2rem","fontWeight":"700"},"color":{"text":"%2$s"}}} -->
<h3 class="wp-block-heading" style="color:%2$s;font-size:1.2rem;font-weight:700;margin:0 0 10px">%3$s</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#64748b"},"typography":{"fontSize":"0.95rem","lineHeight":"1.6"}}} -->
<p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0">%4$s</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
                $svc['icon'], $this->primary, esc_html( $svc['title'] ), esc_html( $svc['desc'] )
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"16px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:16px">Our Services</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"#64748b"},"typography":{"fontSize":"1.05rem"},"spacing":{"margin":{"bottom":"56px"}}}} -->
<p class="has-text-align-center" style="color:#64748b;font-size:1.05rem;margin-bottom:56px">Expert solutions tailored to your needs</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"24px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:24px">
<!-- wp:column -->
<div class="wp-block-column">%2$s</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">%3$s</div>
<!-- /wp:column -->
<!-- wp:column -->
<div class="wp-block-column">%4$s</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $this->primary,
            $cards ? explode( '<!-- /wp:group -->', $cards )[0] . '<!-- /wp:group -->' : '',
            $cards ? ( explode( '<!-- /wp:group -->', $cards )[1] ?? '' ) . ( count( $services ) > 1 ? '<!-- /wp:group -->' : '' ) : '',
            $cards ? ( explode( '<!-- /wp:group -->', $cards )[2] ?? '' ) . ( count( $services ) > 2 ? '<!-- /wp:group -->' : '' ) : ''
        );
    }

    // ── Section: About Teaser ────────────────────────────────────────────
    private function section_about_teaser( string $name ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:columns {"isStackedOnMobile":true,"verticalAlignment":"center","style":{"spacing":{"blockGap":"60px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:60px;align-items:center">
<!-- wp:column {"width":"50%%"} -->
<div class="wp-block-column" style="width:50%%">
<!-- wp:image {"align":"center","sizeSlug":"large","style":{"border":{"radius":"20px"}}} -->
<figure class="wp-block-image aligncenter size-large" style="border-radius:20px"><img src="https://images.unsplash.com/photo-1497366216548-37526070297c?w=800&q=80" alt="About %1$s" style="border-radius:20px;width:100%%;object-fit:cover;aspect-ratio:4/3"/></figure>
<!-- /wp:image -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"50%%"} -->
<div class="wp-block-column" style="width:50%%">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.85rem","fontWeight":"700","letterSpacing":"0.1em","textTransform":"uppercase"},"color":{"text":"%2$s"}}} -->
<p style="color:%2$s;font-size:0.85rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase;margin-bottom:12px">About Us</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"style":{"typography":{"fontSize":"clamp(1.7rem,3vw,2.6rem)","fontWeight":"700","lineHeight":"1.2"},"color":{"text":"#0f172a"}}} -->
<h2 class="wp-block-heading" style="color:#0f172a;font-size:clamp(1.7rem,3vw,2.6rem);font-weight:700;line-height:1.2">Who We Are &amp; What We Do</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"1.05rem","lineHeight":"1.75"},"spacing":{"margin":{"top":"20px"}}}} -->
<p style="color:#475569;font-size:1.05rem;line-height:1.75;margin-top:20px">We are %1$s — a trusted team of professionals dedicated to delivering exceptional results. Our commitment to quality and client satisfaction sets us apart in everything we do.</p>
<!-- /wp:paragraph -->
<!-- wp:list {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"1rem"},"spacing":{"margin":{"top":"20px"}}}} -->
<ul class="wp-block-list" style="color:#475569;font-size:1rem;margin-top:20px">
<li>✓ Professional &amp; experienced team</li>
<li>✓ Proven track record of success</li>
<li>✓ Tailored solutions for every client</li>
</ul>
<!-- /wp:list -->
<!-- wp:buttons {"style":{"spacing":{"margin":{"top":"32px"}}}} -->
<div class="wp-block-buttons" style="margin-top:32px">
<!-- wp:button {"style":{"border":{"radius":"50px"},"color":{"text":"#ffffff","background":"%2$s"},"spacing":{"padding":{"top":"14px","bottom":"14px","left":"30px","right":"30px"}},"typography":{"fontWeight":"600"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link" style="border-radius:50px;padding:14px 30px;background:%2$s;color:#ffffff;font-weight:600">Learn More About Us</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $name, $this->primary
        );
    }

    // ── Section: Testimonials ────────────────────────────────────────────
    private function section_testimonials(): string {
        $reviews = [
            [ 'name' => 'Sarah Johnson',    'role' => 'CEO, TechVentures',    'text' => 'Outstanding service! The team delivered beyond our expectations. Highly recommended for anyone looking for professional, quality work.', 'rating' => '⭐⭐⭐⭐⭐' ],
            [ 'name' => 'Michael Chen',     'role' => 'Marketing Director',   'text' => 'The results speak for themselves. Our business has seen tremendous growth since partnering with this incredible team.', 'rating' => '⭐⭐⭐⭐⭐' ],
            [ 'name' => 'Emily Rodriguez',  'role' => 'Small Business Owner', 'text' => 'From start to finish, the experience was seamless. The attention to detail and customer care is second to none.', 'rating' => '⭐⭐⭐⭐⭐' ],
        ];

        $cards = '';
        foreach ( $reviews as $r ) {
            $cards .= sprintf( '
<!-- wp:group {"style":{"border":{"radius":"16px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"32px","bottom":"32px","left":"28px","right":"28px"}},"color":{"background":"#ffffff"}},"className":"wpc-testimonial"} -->
<div class="wp-block-group wpc-testimonial" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:16px;padding:32px 28px">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"1.3rem"},"spacing":{"margin":{"bottom":"12px"}}}} -->
<p style="font-size:1.3rem;margin-bottom:12px">%1$s</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"0.95rem","lineHeight":"1.7"},"spacing":{"margin":{"bottom":"20px"}}}} -->
<p style="color:#475569;font-size:0.95rem;line-height:1.7;margin-bottom:20px">"%2$s"</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"typography":{"fontWeight":"700","fontSize":"0.95rem"},"color":{"text":"#0f172a"}}} -->
<p style="font-weight:700;font-size:0.95rem;color:#0f172a;margin:0">%3$s <span style="font-weight:400;color:#64748b">— %4$s</span></p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
                $r['rating'], $r['text'], $r['name'], $r['role']
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"16px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:16px">What Our Clients Say</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"#64748b"},"spacing":{"margin":{"bottom":"56px"}}}} -->
<p class="has-text-align-center" style="color:#64748b;margin-bottom:56px">Real stories from our satisfied clients</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"24px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:24px">
%2$s
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $this->primary,
            implode( "\n<!-- wp:column --><div class=\"wp-block-column\">\n", array_map(
                fn( $c ) => "<!-- wp:column --><div class=\"wp-block-column\">\n" . $c . "\n</div><!-- /wp:column -->",
                $reviews
            ) ) ? $cards : ''
        );
    }

    // ── Section: CTA Banner ──────────────────────────────────────────────
    private function section_cta_banner( string $name ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"80px","bottom":"80px","left":"5%%","right":"5%%"}},"color":{"gradient":"linear-gradient(135deg,%1$s 0%%,%2$s 100%%)"}}} -->
<div class="wp-block-group alignfull" style="background:linear-gradient(135deg,%1$s 0%%,%2$s 100%%);padding:80px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3.5vw,3rem)","fontWeight":"700"},"color":{"text":"#ffffff"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:#ffffff;font-size:clamp(1.8rem,3.5vw,3rem);font-weight:700">Ready to Get Started with %3$s?</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"rgba(255,255,255,0.85)"},"typography":{"fontSize":"1.1rem"},"spacing":{"margin":{"top":"16px","bottom":"36px"}}}} -->
<p class="has-text-align-center" style="color:rgba(255,255,255,0.85);font-size:1.1rem;margin:16px 0 36px">Join hundreds of satisfied clients who trust us for their needs. Contact us today for a free consultation.</p>
<!-- /wp:paragraph -->
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"},"style":{"spacing":{"blockGap":"16px"}}} -->
<div class="wp-block-buttons" style="display:flex;justify-content:center;gap:16px;flex-wrap:wrap">
<!-- wp:button {"style":{"border":{"radius":"50px"},"color":{"text":"%1$s","background":"#ffffff"},"spacing":{"padding":{"top":"16px","bottom":"16px","left":"36px","right":"36px"}},"typography":{"fontWeight":"700","fontSize":"1rem"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link" style="border-radius:50px;padding:16px 36px;background:#ffffff;color:%1$s;font-weight:700">Contact Us Now</a></div>
<!-- /wp:button -->
<!-- wp:button {"className":"is-style-outline","style":{"border":{"radius":"50px","color":"rgba(255,255,255,0.8)","width":"2px"},"color":{"text":"#ffffff"},"spacing":{"padding":{"top":"16px","bottom":"16px","left":"36px","right":"36px"}},"typography":{"fontWeight":"600","fontSize":"1rem"}}} -->
<div class="wp-block-button is-style-outline"><a class="wp-block-button__link" style="border-radius:50px;padding:16px 36px;border:2px solid rgba(255,255,255,0.8);color:#ffffff;font-weight:600">View Our Work</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->',
            $this->primary, $this->secondary, $name
        );
    }

    // ── Section: Contact Footer ──────────────────────────────────────────
    private function section_contact_footer( string $name ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"40px","left":"5%%","right":"5%%"}},"color":{"background":"#0f172a"}}} -->
<div class="wp-block-group alignfull" style="background:#0f172a;padding:72px 5%% 40px">
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"48px","margin":{"bottom":"48px"}}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:48px;margin-bottom:48px">
<!-- wp:column {"width":"40%%"} -->
<div class="wp-block-column" style="width:40%%">
<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"1.5rem","fontWeight":"700"},"color":{"text":"#ffffff"}}} -->
<h3 class="wp-block-heading" style="color:#ffffff;font-size:1.5rem;font-weight:700">%1$s</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"rgba(255,255,255,0.6)"},"typography":{"fontSize":"0.95rem","lineHeight":"1.7"},"spacing":{"margin":{"top":"12px"}}}} -->
<p style="color:rgba(255,255,255,0.6);font-size:0.95rem;line-height:1.7;margin-top:12px">Dedicated to providing exceptional service and building lasting relationships with our clients.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"20%%"} -->
<div class="wp-block-column" style="width:20%%">
<!-- wp:heading {"level":4,"style":{"typography":{"fontSize":"0.85rem","fontWeight":"700","letterSpacing":"0.08em","textTransform":"uppercase"},"color":{"text":"rgba(255,255,255,0.4)"},"spacing":{"margin":{"bottom":"16px"}}}} -->
<h4 style="color:rgba(255,255,255,0.4);font-size:0.85rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:16px">Company</h4>
<!-- /wp:heading -->
<!-- wp:list {"style":{"color":{"text":"rgba(255,255,255,0.65)"}}} -->
<ul class="wp-block-list" style="color:rgba(255,255,255,0.65);list-style:none;padding:0"><li><a href="/about" style="color:inherit;text-decoration:none">About Us</a></li><li><a href="/services" style="color:inherit;text-decoration:none">Services</a></li><li><a href="/contact" style="color:inherit;text-decoration:none">Contact</a></li></ul>
<!-- /wp:list -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"30%%"} -->
<div class="wp-block-column" style="width:30%%">
<!-- wp:heading {"level":4,"style":{"typography":{"fontSize":"0.85rem","fontWeight":"700","letterSpacing":"0.08em","textTransform":"uppercase"},"color":{"text":"rgba(255,255,255,0.4)"},"spacing":{"margin":{"bottom":"16px"}}}} -->
<h4 style="color:rgba(255,255,255,0.4);font-size:0.85rem;font-weight:700;letter-spacing:0.08em;text-transform:uppercase;margin-bottom:16px">Contact</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"rgba(255,255,255,0.65)"},"typography":{"fontSize":"0.95rem","lineHeight":"1.8"}}} -->
<p style="color:rgba(255,255,255,0.65);font-size:0.95rem;line-height:1.8">📍 123 Business Ave, City, State 12345<br/>📞 (555) 123-4567<br/>✉️ hello@%2$s</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
<!-- wp:separator {"style":{"color":{"background":"rgba(255,255,255,0.1)"}}} -->
<hr class="wp-block-separator" style="border-color:rgba(255,255,255,0.1);margin:0 0 24px"/>
<!-- /wp:separator -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"rgba(255,255,255,0.35)"},"typography":{"fontSize":"0.85rem"}}} -->
<p class="has-text-align-center" style="color:rgba(255,255,255,0.35);font-size:0.85rem">© %3$d %1$s. All rights reserved. Built with WP Commander.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
            $name,
            strtolower( preg_replace( '/\s+/', '', $name ) ) . '.com',
            date( 'Y' )
        );
    }

    // ── Section: Our Story ───────────────────────────────────────────────
    private function section_our_story( string $name ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:group {"style":{"spacing":{"padding":{"top":"0","bottom":"0"}}}} -->
<div class="wp-block-group" style="max-width:800px;margin:0 auto;text-align:center">
<!-- wp:paragraph {"style":{"typography":{"fontSize":"0.85rem","fontWeight":"700","letterSpacing":"0.1em","textTransform":"uppercase"},"color":{"text":"%1$s"}}} -->
<p style="color:%1$s;font-size:0.85rem;font-weight:700;letter-spacing:0.1em;text-transform:uppercase">Our Story</p>
<!-- /wp:paragraph -->
<!-- wp:heading {"level":2,"style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"#0f172a"}}} -->
<h2 class="wp-block-heading" style="color:#0f172a;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700">Our Journey &amp; Mission</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"1.05rem","lineHeight":"1.8"},"spacing":{"margin":{"top":"24px"}}}} -->
<p style="color:#475569;font-size:1.05rem;line-height:1.8;margin-top:24px">Founded with a vision to make a difference, %2$s has grown from a small team of passionate professionals into a trusted industry leader. Our story is one of dedication, innovation, and an unwavering commitment to our clients.</p>
<!-- /wp:paragraph -->
<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"1.05rem","lineHeight":"1.8"},"spacing":{"margin":{"top":"20px"}}}} -->
<p style="color:#475569;font-size:1.05rem;line-height:1.8;margin-top:20px">Every decision we make is guided by our core values: integrity, excellence, and genuine care for the people we serve. We believe that when our clients succeed, we succeed.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->',
            $this->primary, $name
        );
    }

    // ── Section: Team Grid ───────────────────────────────────────────────
    private function section_team_grid(): string {
        $members = [
            [ 'name' => 'Alex Johnson',   'role' => 'Founder & CEO',       'img' => 'photo-1507003211169-0a1dd7228f2d' ],
            [ 'name' => 'Maria Garcia',   'role' => 'Director of Operations','img' => 'photo-1494790108377-be9c29b29330' ],
            [ 'name' => 'David Kim',      'role' => 'Lead Specialist',      'img' => 'photo-1500648767791-00dcc994a43e' ],
        ];

        $cards = '';
        foreach ( $members as $m ) {
            $cards .= sprintf( '
<!-- wp:group {"style":{"border":{"radius":"16px"},"spacing":{"padding":{"top":"0","bottom":"28px"}},"color":{"background":"#ffffff"}},"className":"wpc-team-card"} -->
<div class="wp-block-group wpc-team-card" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,0.08);text-align:center;padding-bottom:28px">
<!-- wp:image {"sizeSlug":"medium"} -->
<figure class="wp-block-image size-medium" style="margin:0"><img src="https://images.unsplash.com/%1$s?w=400&q=80&fit=crop&crop=face" alt="%2$s" style="width:100%%;height:240px;object-fit:cover"/></figure>
<!-- /wp:image -->
<!-- wp:heading {"level":4,"textAlign":"center","style":{"typography":{"fontSize":"1.1rem","fontWeight":"700"},"color":{"text":"#0f172a"},"spacing":{"margin":{"top":"20px","bottom":"4px"}}}} -->
<h4 class="wp-block-heading has-text-align-center" style="color:#0f172a;font-size:1.1rem;font-weight:700;margin:20px 0 4px">%2$s</h4>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"%3$s"},"typography":{"fontSize":"0.9rem","fontWeight":"600"}}} -->
<p class="has-text-align-center" style="color:%3$s;font-size:0.9rem;font-weight:600;margin:0">%4$s</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->',
                $m['img'], $m['name'], $this->primary, $m['role']
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"56px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:56px">Meet Our Team</h2>
<!-- /wp:heading -->
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"28px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:28px">
<!-- wp:column --><div class="wp-block-column">%2$s</div><!-- /wp:column -->
<!-- wp:column --><div class="wp-block-column">%3$s</div><!-- /wp:column -->
<!-- wp:column --><div class="wp-block-column">%4$s</div><!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $this->primary,
            ...array_map( fn( $m ) => sprintf(
                '<!-- wp:group {"style":{"border":{"radius":"16px"},"spacing":{"padding":{"top":"0","bottom":"28px"}},"color":{"background":"#ffffff"}},"className":"wpc-team-card"} --><div class="wp-block-group wpc-team-card" style="background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 2px 20px rgba(0,0,0,0.08);text-align:center;padding-bottom:28px"><!-- wp:image {"sizeSlug":"medium"} --><figure class="wp-block-image size-medium" style="margin:0"><img src="https://images.unsplash.com/%s?w=400&q=80&fit=crop&crop=face" alt="%s" style="width:100%%;height:240px;object-fit:cover"/></figure><!-- /wp:image --><!-- wp:heading {"level":4,"textAlign":"center","style":{"typography":{"fontSize":"1.1rem","fontWeight":"700"},"color":{"text":"#0f172a"},"spacing":{"margin":{"top":"20px","bottom":"4px"}}}} --><h4 class="wp-block-heading has-text-align-center" style="color:#0f172a;font-size:1.1rem;font-weight:700;margin:20px 0 4px">%s</h4><!-- /wp:heading --><!-- wp:paragraph {"align":"center","style":{"color":{"text":"%s"},"typography":{"fontSize":"0.9rem","fontWeight":"600"}}} --><p class="has-text-align-center" style="color:%s;font-size:0.9rem;font-weight:600;margin:0">%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
                $m['img'], $m['name'], $m['name'], $this->primary, $this->primary, $m['role']
            ), $members )
        );
    }

    // ── Section: Certifications / Badges ────────────────────────────────
    private function section_certifications(): string {
        $badges = [ '🏆 Award Winner 2024', '⭐ 5-Star Rated', '✅ Certified Professional', '🔒 Licensed & Insured', '👥 500+ Clients' ];
        $items  = implode( '', array_map( fn( $b ) => sprintf(
            '<!-- wp:group {"style":{"border":{"radius":"12px","width":"1.5px","color":"#e2e8f0"},"spacing":{"padding":{"top":"20px","bottom":"20px","left":"20px","right":"20px"}},"color":{"background":"#ffffff"}}} --><div class="wp-block-group" style="background:#ffffff;border:1.5px solid #e2e8f0;border-radius:12px;padding:20px;text-align:center"><!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"0.95rem","fontWeight":"600"},"color":{"text":"#334155"}}} --><p class="has-text-align-center" style="color:#334155;font-size:0.95rem;font-weight:600">%s</p><!-- /wp:paragraph --></div><!-- /wp:group -->',
            esc_html( $b )
        ), $badges ) );

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"60px","bottom":"60px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:60px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"1.2rem","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"32px"}}}} -->
<h3 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:1.2rem;font-weight:700;margin-bottom:32px">Trusted &amp; Certified</h3>
<!-- /wp:heading -->
<div style="display:flex;flex-wrap:wrap;gap:16px;justify-content:center">%2$s</div>
</div>
<!-- /wp:group -->',
            $this->primary, $items
        );
    }

    // ── Section: Services List (detailed) ────────────────────────────────
    private function section_services_list( string $industry ): string {
        $services = $this->get_industry_services( $industry );
        $items = '';
        foreach ( $services as $svc ) {
            $items .= sprintf( '
<!-- wp:group {"style":{"border":{"radius":"12px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"28px","bottom":"28px","left":"24px","right":"24px"}},"color":{"background":"#ffffff"}},"className":"wpc-service-list-item"} -->
<div class="wp-block-group wpc-service-list-item" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 24px;display:flex;gap:20px;align-items:flex-start;box-shadow:0 1px 8px rgba(0,0,0,0.05)">
<div style="font-size:2rem;flex-shrink:0">%1$s</div>
<div>
<!-- wp:heading {"level":3,"style":{"typography":{"fontSize":"1.1rem","fontWeight":"700"},"color":{"text":"#0f172a"}}} -->
<h3 style="color:#0f172a;font-size:1.1rem;font-weight:700;margin:0 0 8px">%2$s</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#64748b"},"typography":{"fontSize":"0.95rem","lineHeight":"1.6"}}} -->
<p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0">%3$s</p>
<!-- /wp:paragraph -->
</div>
</div>
<!-- /wp:group -->',
                esc_html( $svc['icon'] ), esc_html( $svc['title'] ), esc_html( $svc['desc'] )
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"48px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:48px">All Our Services</h2>
<!-- /wp:heading -->
<div style="display:flex;flex-direction:column;gap:16px;max-width:800px;margin:0 auto">%2$s</div>
</div>
<!-- /wp:group -->',
            $this->primary, $items
        );
    }

    // ── Section: Pricing Table ───────────────────────────────────────────
    private function section_pricing_table(): string {
        $tiers = [
            [ 'name' => 'Starter',     'price' => '$49',  'period' => '/month', 'highlight' => false, 'features' => [ '5 Projects', '10GB Storage', 'Email Support', 'Basic Analytics' ] ],
            [ 'name' => 'Professional','price' => '$99',  'period' => '/month', 'highlight' => true,  'features' => [ 'Unlimited Projects', '100GB Storage', 'Priority Support', 'Advanced Analytics', 'Team Collaboration' ] ],
            [ 'name' => 'Enterprise',  'price' => '$199', 'period' => '/month', 'highlight' => false, 'features' => [ 'Everything in Pro', 'Dedicated Server', '24/7 Phone Support', 'Custom Integrations', 'SLA Guarantee' ] ],
        ];

        $cols = '';
        foreach ( $tiers as $tier ) {
            $feats = implode( '', array_map( fn( $f ) => '<li style="padding:8px 0;border-bottom:1px solid rgba(0,0,0,0.05);color:#475569">✓ ' . esc_html( $f ) . '</li>', $tier['features'] ) );
            $bg = $tier['highlight'] ? "background:linear-gradient(135deg,{$this->primary} 0%,{$this->secondary} 100%)" : 'background:#ffffff';
            $tc = $tier['highlight'] ? '#ffffff' : '#0f172a';
            $mc = $tier['highlight'] ? 'rgba(255,255,255,0.85)' : '#64748b';
            $bc = $tier['highlight'] ? '#ffffff' : $this->primary;

            $cols .= sprintf( '
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:group {"style":{"border":{"radius":"20px"},"spacing":{"padding":{"top":"36px","bottom":"36px","left":"28px","right":"28px"}}}} -->
<div class="wp-block-group" style="%1$s;border-radius:20px;padding:36px 28px;%2$s">
<!-- wp:heading {"level":3,"textAlign":"center","style":{"typography":{"fontSize":"1rem","fontWeight":"700","letterSpacing":"0.06em","textTransform":"uppercase"},"color":{"text":"%3$s"}}} -->
<h3 class="wp-block-heading has-text-align-center" style="color:%3$s;font-size:1rem;font-weight:700;letter-spacing:0.06em;text-transform:uppercase">%4$s</h3>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"3rem","fontWeight":"800","lineHeight":"1"},"color":{"text":"%3$s"},"spacing":{"margin":{"top":"16px"}}}} -->
<p class="has-text-align-center" style="color:%3$s;font-size:3rem;font-weight:800;line-height:1;margin-top:16px">%5$s<span style="font-size:1rem;font-weight:500;opacity:0.7">%6$s</span></p>
<!-- /wp:paragraph -->
<ul style="list-style:none;padding:0;margin:24px 0 28px">%7$s</ul>
<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} -->
<div class="wp-block-buttons" style="display:flex;justify-content:center">
<!-- wp:button {"style":{"border":{"radius":"50px"},"color":{"text":"%1$s","background":"%8$s"},"spacing":{"padding":{"top":"14px","bottom":"14px","left":"28px","right":"28px"}},"typography":{"fontWeight":"700"}}} -->
<div class="wp-block-button"><a class="wp-block-button__link" style="border-radius:50px;padding:14px 28px;color:%9$s;background:%8$s;font-weight:700;display:block">Get Started</a></div>
<!-- /wp:button -->
</div>
<!-- /wp:buttons -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->',
                $bg,
                $tier['highlight'] ? 'box-shadow:0 20px 40px rgba(99,102,241,0.35);transform:scale(1.03)' : 'border:1.5px solid #e2e8f0;box-shadow:0 2px 16px rgba(0,0,0,0.06)',
                $tc, $tier['name'], $tier['price'], $tier['period'],
                $feats,
                $tier['highlight'] ? '#ffffff' : $this->primary,
                $tier['highlight'] ? $this->primary : '#ffffff'
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"16px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:16px">Simple, Transparent Pricing</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"#64748b"},"spacing":{"margin":{"bottom":"60px"}}}} -->
<p class="has-text-align-center" style="color:#64748b;margin-bottom:60px">Choose the plan that works best for you. No hidden fees.</p>
<!-- /wp:paragraph -->
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"24px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:24px;align-items:center">
%2$s
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $this->primary, $cols
        );
    }

    // ── Section: FAQ ─────────────────────────────────────────────────────
    private function section_faq( string $industry ): string {
        $faqs = $this->get_industry_faqs( $industry );
        $items = '';
        foreach ( $faqs as $faq ) {
            $items .= sprintf( '
<!-- wp:details {"style":{"border":{"radius":"10px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"20px","bottom":"20px","left":"20px","right":"20px"}},"color":{"background":"#ffffff"}}} -->
<details class="wp-block-details" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;margin-bottom:12px">
<summary style="font-weight:600;color:#0f172a;cursor:pointer;font-size:1rem">%1$s</summary>
<!-- wp:paragraph {"style":{"color":{"text":"#64748b"},"typography":{"lineHeight":"1.7"},"spacing":{"margin":{"top":"12px"}}}} -->
<p style="color:#64748b;line-height:1.7;margin-top:12px">%2$s</p>
<!-- /wp:paragraph -->
</details>
<!-- /wp:details -->',
                esc_html( $faq['q'] ), esc_html( $faq['a'] )
            );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<div style="max-width:720px;margin:0 auto">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"48px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:48px">Frequently Asked Questions</h2>
<!-- /wp:heading -->
%2$s
</div>
</div>
<!-- /wp:group -->',
            $this->primary, $items
        );
    }

    // ── Section: Contact Form ────────────────────────────────────────────
    private function section_contact_form(): string {
        // Use CF7 if available, otherwise HTML form
        $cf7_available = post_type_exists( 'wpcf7_contact_form' );

        $form_html = $cf7_available
            ? '[contact-form-7 id="1" title="Contact form"]'
            : '<form class="wpc-contact-form" style="display:flex;flex-direction:column;gap:16px">
<div style="display:grid;grid-template-columns:1fr 1fr;gap:16px">
<div><label style="display:block;font-weight:600;color:#334155;margin-bottom:6px;font-size:0.9rem">Name</label><input type="text" name="name" required style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:1rem;outline:none;transition:border-color 0.15s" placeholder="Your name"/></div>
<div><label style="display:block;font-weight:600;color:#334155;margin-bottom:6px;font-size:0.9rem">Email</label><input type="email" name="email" required style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:1rem;outline:none" placeholder="your@email.com"/></div>
</div>
<div><label style="display:block;font-weight:600;color:#334155;margin-bottom:6px;font-size:0.9rem">Message</label><textarea name="message" rows="5" required style="width:100%;padding:12px 14px;border:1.5px solid #e2e8f0;border-radius:8px;font-size:1rem;resize:vertical;outline:none" placeholder="How can we help you?"></textarea></div>
<button type="submit" style="padding:14px 32px;background:linear-gradient(135deg,' . $this->primary . ',' . $this->secondary . ');color:#ffffff;border:none;border-radius:50px;font-size:1rem;font-weight:700;cursor:pointer;align-self:flex-start">Send Message →</button>
</form>';

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"60px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:60px">
<!-- wp:column {"width":"45%%"} -->
<div class="wp-block-column" style="width:45%%">
<!-- wp:heading {"level":2,"style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"#0f172a"}}} -->
<h2 class="wp-block-heading" style="color:#0f172a;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700">Get In Touch</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"style":{"color":{"text":"#475569"},"typography":{"fontSize":"1.05rem","lineHeight":"1.7"},"spacing":{"margin":{"top":"16px","bottom":"32px"}}}} -->
<p style="color:#475569;font-size:1.05rem;line-height:1.7;margin:16px 0 32px">We\'d love to hear from you. Send us a message and we\'ll respond as soon as possible.</p>
<!-- /wp:paragraph -->
<!-- wp:group {"style":{"spacing":{"blockGap":"16px"}}} -->
<div style="display:flex;flex-direction:column;gap:16px">
<div style="display:flex;align-items:center;gap:12px;color:#475569"><span style="font-size:1.4rem">📍</span><span>123 Business Ave, City, State 12345</span></div>
<div style="display:flex;align-items:center;gap:12px;color:#475569"><span style="font-size:1.4rem">📞</span><span>(555) 123-4567</span></div>
<div style="display:flex;align-items:center;gap:12px;color:#475569"><span style="font-size:1.4rem">✉️</span><span>hello@yourbusiness.com</span></div>
<div style="display:flex;align-items:center;gap:12px;color:#475569"><span style="font-size:1.4rem">🕐</span><span>Mon–Fri: 9am–5pm</span></div>
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->
<!-- wp:column {"width":"55%%"} -->
<div class="wp-block-column" style="width:55%%">
<!-- wp:html -->
%1$s
<!-- /wp:html -->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->',
            $form_html
        );
    }

    // ── Section: Map ─────────────────────────────────────────────────────
    private function section_map_embed(): string {
        return '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"0","bottom":"0"}}}} -->
<div class="wp-block-group alignfull">
<!-- wp:html -->
<div style="width:100%;height:400px;background:linear-gradient(135deg,#e2e8f0,#cbd5e1);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#64748b;font-weight:500">
📍 Replace this placeholder with your Google Maps embed
</div>
<!-- /wp:html -->
</div>
<!-- /wp:group -->';
    }

    // ── Section: Business Info ───────────────────────────────────────────
    private function section_business_info( string $name ): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:72px 5%%">
<!-- wp:columns {"isStackedOnMobile":true,"style":{"spacing":{"blockGap":"32px"}}} -->
<div class="wp-block-columns is-not-stacked-on-mobile" style="gap:32px">
<!-- wp:column --><div class="wp-block-column">
<!-- wp:group {"style":{"border":{"radius":"12px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"28px","bottom":"28px","left":"24px","right":"24px"}},"color":{"background":"#ffffff"}}} -->
<div class="wp-block-group" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 24px">
<p style="font-size:2rem;margin:0 0 12px">📍</p>
<h4 style="color:#0f172a;font-size:1rem;font-weight:700;margin:0 0 8px">Our Location</h4>
<p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0">123 Business Avenue<br/>City, State 12345<br/>United States</p>
</div><!-- /wp:group -->
</div><!-- /wp:column -->
<!-- wp:column --><div class="wp-block-column">
<!-- wp:group {"style":{"border":{"radius":"12px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"28px","bottom":"28px","left":"24px","right":"24px"}},"color":{"background":"#ffffff"}}} -->
<div class="wp-block-group" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 24px">
<p style="font-size:2rem;margin:0 0 12px">🕐</p>
<h4 style="color:#0f172a;font-size:1rem;font-weight:700;margin:0 0 8px">Business Hours</h4>
<p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0">Monday – Friday: 9am – 5pm<br/>Saturday: 10am – 2pm<br/>Sunday: Closed</p>
</div><!-- /wp:group -->
</div><!-- /wp:column -->
<!-- wp:column --><div class="wp-block-column">
<!-- wp:group {"style":{"border":{"radius":"12px","width":"1px","color":"#e2e8f0"},"spacing":{"padding":{"top":"28px","bottom":"28px","left":"24px","right":"24px"}},"color":{"background":"#ffffff"}}} -->
<div class="wp-block-group" style="background:#ffffff;border:1px solid #e2e8f0;border-radius:12px;padding:28px 24px">
<p style="font-size:2rem;margin:0 0 12px">📞</p>
<h4 style="color:#0f172a;font-size:1rem;font-weight:700;margin:0 0 8px">Contact Info</h4>
<p style="color:#64748b;font-size:0.95rem;line-height:1.6;margin:0">Phone: (555) 123-4567<br/>Email: hello@%1$s<br/>Fax: (555) 987-6543</p>
</div><!-- /wp:group -->
</div><!-- /wp:column -->
</div><!-- /wp:columns -->
</div><!-- /wp:group -->',
            strtolower( preg_replace( '/\s+/', '', $name ) ) . '.com'
        );
    }

    // ── Section: Stats Counter ───────────────────────────────────────────
    private function section_stats_counter(): string {
        $stats = [ [ '500+', 'Happy Clients' ], [ '10+', 'Years Experience' ], [ '98%', 'Satisfaction Rate' ], [ '24/7', 'Support Available' ] ];
        $items = '';
        foreach ( $stats as $s ) {
            $items .= sprintf( '<!-- wp:column --><div class="wp-block-column" style="text-align:center"><p style="font-size:clamp(2rem,4vw,3.5rem);font-weight:800;color:%1$s;margin:0">%2$s</p><p style="color:#64748b;font-size:0.95rem;margin:4px 0 0;font-weight:500">%3$s</p></div><!-- /wp:column -->', $this->primary, $s[0], $s[1] );
        }

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"5%%","right":"5%%"}},"color":{"gradient":"linear-gradient(135deg,%1$s 0%%,%2$s 100%%)"}}} -->
<div class="wp-block-group alignfull" style="background:linear-gradient(135deg,%1$s 0%%,%2$s 100%%);padding:72px 5%%">
<!-- wp:columns {"isStackedOnMobile":true} --><div class="wp-block-columns is-not-stacked-on-mobile">%3$s</div><!-- /wp:columns -->
</div><!-- /wp:group -->',
            $this->primary, $this->secondary, $items
        );
    }

    // ── Section: Newsletter ──────────────────────────────────────────────
    private function section_newsletter(): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"72px","bottom":"72px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:72px 5%%">
<div style="max-width:560px;margin:0 auto;text-align:center">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.6rem,2.8vw,2.4rem)","fontWeight":"700"},"color":{"text":"#0f172a"}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:#0f172a;font-size:clamp(1.6rem,2.8vw,2.4rem);font-weight:700">Stay Updated</h2>
<!-- /wp:heading -->
<!-- wp:paragraph {"align":"center","style":{"color":{"text":"#64748b"},"spacing":{"margin":{"top":"12px","bottom":"28px"}}}} -->
<p class="has-text-align-center" style="color:#64748b;margin:12px 0 28px">Subscribe to our newsletter for the latest news and updates.</p>
<!-- /wp:paragraph -->
<!-- wp:html -->
<form style="display:flex;gap:12px;max-width:440px;margin:0 auto">
<input type="email" placeholder="Enter your email" style="flex:1;padding:13px 16px;border:1.5px solid #e2e8f0;border-radius:50px;font-size:1rem;outline:none"/>
<button type="submit" style="padding:13px 24px;background:linear-gradient(135deg,%1$s,%2$s);color:#ffffff;border:none;border-radius:50px;font-weight:700;cursor:pointer;white-space:nowrap">Subscribe</button>
</form>
<!-- /wp:html -->
</div>
</div><!-- /wp:group -->',
            $this->primary, $this->secondary
        );
    }

    // ── Section: Gallery ────────────────────────────────────────────────
    private function section_gallery(): string {
        $photos = [
            'photo-1497366216548-37526070297c', 'photo-1497366811353-6870744d04b2',
            'photo-1582407947304-fd86f028f716', 'photo-1600585154340-be6161a56a0c',
            'photo-1497366754035-f200968a1f43', 'photo-1542744173-8e7e53415bb0',
        ];
        $imgs = implode( '', array_map( fn( $p ) => sprintf(
            '<img src="https://images.unsplash.com/%s?w=400&q=80" alt="Gallery" style="width:100%%;aspect-ratio:1;object-fit:cover;border-radius:12px"/>',
            $p
        ), $photos ) );

        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}}}} -->
<div class="wp-block-group alignfull" style="padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"48px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:48px">Our Gallery</h2>
<!-- /wp:heading -->
<!-- wp:html -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px">%2$s</div>
<!-- /wp:html -->
</div><!-- /wp:group -->',
            $this->primary, $imgs
        );
    }

    // ── Section: Blog Feed ───────────────────────────────────────────────
    private function section_blog_feed(): string {
        return sprintf( '
<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"90px","bottom":"90px","left":"5%%","right":"5%%"}},"color":{"background":"#f8fafc"}}} -->
<div class="wp-block-group alignfull" style="background:#f8fafc;padding:90px 5%%">
<!-- wp:heading {"textAlign":"center","style":{"typography":{"fontSize":"clamp(1.8rem,3vw,2.8rem)","fontWeight":"700"},"color":{"text":"%1$s"},"spacing":{"margin":{"bottom":"48px"}}}} -->
<h2 class="wp-block-heading has-text-align-center" style="color:%1$s;font-size:clamp(1.8rem,3vw,2.8rem);font-weight:700;margin-bottom:48px">Latest News</h2>
<!-- /wp:heading -->
<!-- wp:latest-posts {"postsToShow":3,"displayPostDate":true,"displayFeaturedImage":true,"featuredImageSizeSlug":"medium","style":{"spacing":{"blockGap":"24px"}}} /-->
</div><!-- /wp:group -->',
            $this->primary
        );
    }

    // ── Navigation menu ──────────────────────────────────────────────────
    private function create_nav_menu( string $name, array $post_ids, array $pages ): int {
        $menu_name = sanitize_text_field( $name ) . ' Navigation';
        $existing  = get_term_by( 'name', $menu_name, 'nav_menu' );
        if ( $existing ) {
            wp_delete_nav_menu( $existing->term_id );
        }

        $menu_id = wp_create_nav_menu( $menu_name );
        if ( is_wp_error( $menu_id ) ) return 0;

        foreach ( $pages as $i => $page_def ) {
            $post_id = $post_ids[ $i ] ?? 0;
            if ( ! $post_id ) continue;
            wp_update_nav_menu_item( $menu_id, 0, [
                'menu-item-title'   => sanitize_text_field( $page_def['title'] ?? 'Page' ),
                'menu-item-url'     => get_permalink( $post_id ),
                'menu-item-status'  => 'publish',
                'menu-item-type'    => 'post_type',
                'menu-item-object'  => 'page',
                'menu-item-object-id' => $post_id,
            ] );
        }

        // Assign to primary location if it exists
        $locations = get_theme_mod( 'nav_menu_locations' );
        if ( $locations ) {
            foreach ( array_keys( $locations ) as $location ) {
                $locations[ $location ] = $menu_id;
                break; // Only assign to first nav location
            }
            set_theme_mod( 'nav_menu_locations', $locations );
        }

        return is_wp_error( $menu_id ) ? 0 : (int) $menu_id;
    }

    // ── Site identity ────────────────────────────────────────────────────
    private function set_site_identity( array $blueprint ): void {
        if ( ! empty( $blueprint['site_name'] ) ) {
            update_option( 'blogname', sanitize_text_field( $blueprint['site_name'] ) );
        }
        if ( ! empty( $blueprint['tagline'] ) ) {
            update_option( 'blogdescription', sanitize_text_field( $blueprint['tagline'] ) );
        }
    }

    // ── Inject global CSS ────────────────────────────────────────────────
    private function inject_global_css(): void {
        $font_h_safe = str_replace( ' ', '+', $this->font_h );
        $font_b_safe = str_replace( ' ', '+', $this->font_b );

        $css = "
:root {
    --wpc-site-primary:    {$this->primary};
    --wpc-site-secondary:  {$this->secondary};
    --wpc-site-accent:     {$this->accent};
    --wpc-site-bg:         {$this->bg};
    --wpc-site-text:       {$this->text_col};
    --wpc-site-font-h:     '{$this->font_h}', sans-serif;
    --wpc-site-font-b:     '{$this->font_b}', sans-serif;
}
body { font-family: var(--wpc-site-font-b); color: var(--wpc-site-text); }
h1,h2,h3,h4,h5,h6 { font-family: var(--wpc-site-font-h); }
.wpc-hero, .wpc-page-hero { position:relative; }
.wpc-service-card:hover { transform:translateY(-4px); box-shadow:0 8px 30px rgba(0,0,0,0.12) !important; }
.wpc-team-card:hover { transform:translateY(-4px); box-shadow:0 8px 30px rgba(0,0,0,0.12) !important; }
.wpc-service-card, .wpc-team-card { transition:transform 0.25s ease, box-shadow 0.25s ease; }
wp-block-image img { border-radius:inherit; }
details summary::-webkit-details-marker { display:none; }
details summary { list-style:none; }
details[open] summary { color: var(--wpc-site-primary); }
";

        update_option( 'wpc_global_css', $css );

        // Also set via theme mods for theme compatibility
        $existing_mod = get_theme_mod( 'custom_css', '' );
        set_theme_mod( 'custom_css', $existing_mod . "\n/* WP Commander Generated */\n" . $css );
    }

    // ── Register Google Fonts ────────────────────────────────────────────
    private function register_google_fonts(): void {
        $fonts = array_unique( [ $this->font_h, $this->font_b ] );
        $query = implode( '&family=', array_map( fn( $f ) => urlencode( $f ) . ':wght@300;400;500;600;700;800', $fonts ) );
        $url   = 'https://fonts.googleapis.com/css2?family=' . $query . '&display=swap';

        // Store so wp_head can output the link tag
        update_option( 'wpc_google_fonts_url', esc_url_raw( $url ) );

        add_action( 'wp_head', function () use ( $url ) {
            echo '<link rel="preconnect" href="https://fonts.googleapis.com">' . "\n";
            echo '<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>' . "\n";
            echo '<link href="' . esc_url( $url ) . '" rel="stylesheet">' . "\n";
        }, 1 );
    }

    // ── Color helpers ────────────────────────────────────────────────────
    private function load_colors(): void {
        $b = $this->blueprint;
        $this->primary   = $this->safe_color( $b['color_primary']   ?? '', '#6366f1' );
        $this->secondary = $this->safe_color( $b['color_secondary'] ?? $b['color_accent'] ?? '', '#8b5cf6' );
        $this->accent    = $this->safe_color( $b['color_accent']    ?? '', '#10b981' );
        $this->bg        = $this->safe_color( $b['color_bg']        ?? '', '#ffffff' );
        $this->text_col  = $this->safe_color( $b['color_text']      ?? '', '#1e293b' );
        $this->font_h    = sanitize_text_field( $b['font_heading'] ?? 'Inter' );
        $this->font_b    = sanitize_text_field( $b['font_body']    ?? 'Inter' );
    }

    private function safe_color( string $color, string $default ): string {
        if ( empty( $color ) ) return $default;
        // Accept hex colors only for CSS safety
        if ( preg_match( '/^#[0-9a-fA-F]{3,8}$/', $color ) ) return $color;
        return $default;
    }

    // ── Industry-specific content ────────────────────────────────────────
    private function get_industry_services( string $industry ): array {
        $map = [
            'dentist' => [
                [ 'icon' => '🦷', 'title' => 'General Dentistry',    'desc' => 'Comprehensive dental care including cleanings, fillings, and preventive treatments for the whole family.' ],
                [ 'icon' => '✨', 'title' => 'Teeth Whitening',       'desc' => 'Professional whitening treatments that brighten your smile by several shades safely and effectively.' ],
                [ 'icon' => '🔧', 'title' => 'Dental Implants',       'desc' => 'Permanent tooth replacement solutions that look, feel, and function just like natural teeth.' ],
            ],
            'restaurant' => [
                [ 'icon' => '🍽️', 'title' => 'Dine In',       'desc' => 'Enjoy a warm, inviting atmosphere with carefully crafted dishes made from the freshest local ingredients.' ],
                [ 'icon' => '🥡', 'title' => 'Takeout & Delivery', 'desc' => 'All your favorites available for pickup or delivery, fresh and ready when you need them.' ],
                [ 'icon' => '🎉', 'title' => 'Private Events',  'desc' => 'Host your special occasions with us. We offer full catering and private dining room reservations.' ],
            ],
            'law' => [
                [ 'icon' => '⚖️', 'title' => 'Corporate Law',   'desc' => 'Expert guidance for business formations, contracts, mergers, and all aspects of corporate legal matters.' ],
                [ 'icon' => '🏠', 'title' => 'Real Estate Law', 'desc' => 'Comprehensive legal support for property transactions, disputes, and real estate development.' ],
                [ 'icon' => '👨‍👩‍👧', 'title' => 'Family Law',  'desc' => 'Compassionate representation for divorce, custody, adoption, and all family-related legal proceedings.' ],
            ],
            'ecommerce' => [
                [ 'icon' => '🚀', 'title' => 'Fast Shipping',   'desc' => 'Orders processed within 24 hours with expedited shipping options available for urgent needs.' ],
                [ 'icon' => '🛡️', 'title' => 'Secure Shopping', 'desc' => 'Bank-level encryption and secure payment processing to keep your information safe.' ],
                [ 'icon' => '↩️', 'title' => 'Easy Returns',    'desc' => '30-day hassle-free return policy on all items. Customer satisfaction is our top priority.' ],
            ],
        ];

        return $map[ $industry ] ?? [
            [ 'icon' => '⭐', 'title' => 'Premium Service',     'desc' => 'Top-quality professional service delivered with attention to detail and a commitment to excellence.' ],
            [ 'icon' => '🎯', 'title' => 'Custom Solutions',    'desc' => 'Tailored approaches designed specifically for your unique needs and business requirements.' ],
            [ 'icon' => '🤝', 'title' => 'Expert Consulting',   'desc' => 'Strategic guidance from experienced professionals who understand your industry and goals.' ],
        ];
    }

    private function get_industry_faqs( string $industry ): array {
        $common = [
            [ 'q' => 'What are your business hours?',  'a' => 'We are open Monday through Friday, 9:00 AM to 5:00 PM, and Saturday from 10:00 AM to 2:00 PM. We are closed on Sundays and major holidays.' ],
            [ 'q' => 'Do you offer free consultations?', 'a' => 'Yes! We offer complimentary initial consultations. Contact us to schedule your free 30-minute session with one of our specialists.' ],
            [ 'q' => 'What payment methods do you accept?', 'a' => 'We accept all major credit cards, debit cards, bank transfers, and cash. Payment plans may be available for certain services.' ],
            [ 'q' => 'How can I get in touch?',        'a' => 'You can reach us by phone at (555) 123-4567, via email at hello@yourbusiness.com, or by filling out the contact form on our website.' ],
        ];
        return $common;
    }

    // ── Status update ─────────────────────────────────────────────────────
    private function update_status( int $pct, string $msg, array $steps, string $status = 'building', array $extra = [] ): void {
        $data = array_merge( [
            'status'   => $status,
            'progress' => $pct,
            'message'  => $msg,
            'steps'    => $steps,
        ], $extra );
        set_transient( 'wpc_gen_status_' . $this->uid, $data, HOUR_IN_SECONDS );
    }
}
