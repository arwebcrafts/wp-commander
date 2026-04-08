<?php
/**
 * WPC_URL_Analyzer — fetches and parses a reference URL to extract
 * nav links, colors, fonts, and section structure.
 * Uses DOMDocument + regex only — no external scraping APIs.
 *
 * @package WP Commander
 */

defined( 'ABSPATH' ) || exit;

class WPC_URL_Analyzer {

    public function analyze( string $url ): array {
        $url = esc_url_raw( $url );
        if ( empty( $url ) ) return [];

        $response = wp_remote_get( $url, [
            'timeout'    => 15,
            'user-agent' => 'WP-Commander/1.0 (WordPress site analysis)',
            'sslverify'  => false,
        ] );

        if ( is_wp_error( $response ) ) return [];
        if ( wp_remote_retrieve_response_code( $response ) !== 200 ) return [];

        $html = wp_remote_retrieve_body( $response );
        if ( empty( $html ) ) return [];

        $nav_links = $this->extract_nav_links( $html, $url );

        return [
            'nav_links'  => $nav_links,
            'colors'     => $this->extract_colors( $html ),
            'fonts'      => $this->extract_fonts( $html ),
            'sections'   => $this->extract_section_landmarks( $html ),
            'meta'       => $this->extract_meta( $html ),
            'page_count' => count( $nav_links ),
        ];
    }

    // ── Nav links ────────────────────────────────────────────────────────
    private function extract_nav_links( string $html, string $base_url = '' ): array {
        $links = [];

        // Look in <nav> elements first, then <header>
        if ( preg_match_all( '/<(?:nav|header)[^>]*>(.*?)<\/(?:nav|header)>/is', $html, $containers ) ) {
            $nav_html = implode( ' ', $containers[1] );
        } else {
            $nav_html = $html;
        }

        preg_match_all( '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $nav_html, $matches );

        $seen = [];
        foreach ( $matches[1] as $i => $href ) {
            $text = wp_strip_all_tags( $matches[2][ $i ] );
            $text = trim( preg_replace( '/\s+/', ' ', $text ) );

            // Skip empty, anchor, or external links
            if ( empty( $text ) || strlen( $text ) > 60 ) continue;
            if ( strpos( $href, '#' ) === 0 ) continue;
            if ( strpos( $href, 'javascript:' ) !== false ) continue;

            $key = strtolower( $text );
            if ( isset( $seen[ $key ] ) ) continue;
            $seen[ $key ] = true;

            $links[] = [
                'text' => sanitize_text_field( $text ),
                'href' => esc_url_raw( $href ),
            ];
        }

        return array_slice( $links, 0, 10 );
    }

    // ── Colors ───────────────────────────────────────────────────────────
    private function extract_colors( string $html ): array {
        $colors = [];

        // Extract from <style> blocks and inline styles
        preg_match_all( '/<style[^>]*>(.*?)<\/style>/is', $html, $style_blocks );
        $css = implode( ' ', $style_blocks[1] );

        // Also grab inline styles
        preg_match_all( '/style=["\'][^"\']*["\']/', $html, $inline );
        $css .= ' ' . implode( ' ', $inline[0] );

        // Hex colors
        preg_match_all( '/#([0-9a-fA-F]{3,8})\b/', $css, $hex_matches );
        foreach ( $hex_matches[0] as $hex ) {
            $colors[] = strtolower( $hex );
        }

        // RGB colors
        preg_match_all( '/rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/', $css, $rgb_matches );
        foreach ( $rgb_matches[0] as $rgb ) {
            $colors[] = $rgb;
        }

        // CSS variables color values
        preg_match_all( '/--[\w-]+color[\w-]*\s*:\s*(#[0-9a-fA-F]{3,8}|rgb[^;]+)/i', $css, $var_colors );
        foreach ( $var_colors[1] as $c ) {
            $colors[] = strtolower( trim( $c ) );
        }

        // Deduplicate and filter
        $colors = array_unique( $colors );
        $colors = array_values( array_filter( $colors, function( $c ) {
            // Filter out very light (near-white) and very dark (near-black) colors
            if ( strpos( $c, '#' ) === 0 ) {
                $hex = ltrim( $c, '#' );
                if ( strlen( $hex ) === 3 ) {
                    $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
                }
                if ( strlen( $hex ) !== 6 ) return false;
                $r = hexdec( substr( $hex, 0, 2 ) );
                $g = hexdec( substr( $hex, 2, 2 ) );
                $b = hexdec( substr( $hex, 4, 2 ) );
                $brightness = ( $r * 299 + $g * 587 + $b * 114 ) / 1000;
                return $brightness > 30 && $brightness < 240;
            }
            return true;
        } ) );

        return array_slice( $colors, 0, 8 );
    }

    // ── Fonts ────────────────────────────────────────────────────────────
    private function extract_fonts( string $html ): array {
        $fonts = [];

        // Google Fonts links
        preg_match_all( '/fonts\.googleapis\.com\/css[^"\']*[?&]family=([^&"\']+)/i', $html, $gf );
        foreach ( $gf[1] as $families ) {
            $fams = explode( '|', urldecode( $families ) );
            foreach ( $fams as $fam ) {
                $name = trim( explode( ':', $fam )[0] );
                $name = str_replace( '+', ' ', $name );
                if ( $name ) $fonts[] = sanitize_text_field( $name );
            }
        }

        // font-family in CSS
        preg_match_all( '/font-family\s*:\s*["\']?([^,"\';\}]+)/i', $html, $ff );
        foreach ( $ff[1] as $font ) {
            $font = trim( trim( $font, '"\',' ) );
            if ( strlen( $font ) > 2 && strlen( $font ) < 50 ) {
                $fonts[] = sanitize_text_field( $font );
            }
        }

        $fonts = array_unique( $fonts );
        // Filter out system fonts
        $system = [ 'sans-serif', 'serif', 'monospace', 'inherit', 'initial', 'cursive', 'fantasy',
                    'arial', 'helvetica', 'verdana', 'times', 'georgia', 'system-ui', '-apple-system' ];
        $fonts  = array_filter( $fonts, fn( $f ) => ! in_array( strtolower( $f ), $system, true ) );

        return array_slice( array_values( $fonts ), 0, 4 );
    }

    // ── Section landmarks ────────────────────────────────────────────────
    private function extract_section_landmarks( string $html ): array {
        $sections = [];

        // HTML5 landmarks
        $tags = [ 'header', 'nav', 'main', 'section', 'article', 'aside', 'footer' ];
        foreach ( $tags as $tag ) {
            $count = substr_count( strtolower( $html ), '<' . $tag );
            if ( $count > 0 ) {
                $sections[] = [ 'element' => $tag, 'count' => $count ];
            }
        }

        // Common section class patterns
        $patterns = [
            'hero'         => '/class=["\'][^"\']*hero[^"\']*["\']/',
            'testimonials' => '/class=["\'][^"\']*testimonial[^"\']*["\']/',
            'services'     => '/class=["\'][^"\']*service[^"\']*["\']/',
            'team'         => '/class=["\'][^"\']*team[^"\']*["\']/',
            'pricing'      => '/class=["\'][^"\']*pric[^"\']*["\']/',
            'contact'      => '/class=["\'][^"\']*contact[^"\']*["\']/',
            'gallery'      => '/class=["\'][^"\']*gallery[^"\']*["\']/',
            'faq'          => '/class=["\'][^"\']*faq[^"\']*["\']/',
        ];

        $found_patterns = [];
        foreach ( $patterns as $name => $pattern ) {
            if ( preg_match( $pattern, $html ) ) {
                $found_patterns[] = $name;
            }
        }

        if ( $found_patterns ) {
            $sections[] = [ 'detected_sections' => $found_patterns ];
        }

        return $sections;
    }

    // ── Meta info ────────────────────────────────────────────────────────
    private function extract_meta( string $html ): array {
        $meta = [];

        // Title
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            $meta['title'] = sanitize_text_field( wp_strip_all_tags( $m[1] ) );
        }

        // Description
        if ( preg_match( '/<meta[^>]+name=["\']description["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) {
            $meta['description'] = sanitize_text_field( $m[1] );
        }

        // OG image
        if ( preg_match( '/<meta[^>]+property=["\']og:image["\'][^>]+content=["\']([^"\']+)["\']/', $html, $m ) ) {
            $meta['og_image'] = esc_url_raw( $m[1] );
        }

        return $meta;
    }
}
