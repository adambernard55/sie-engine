<?php
/**
 * SIE SEO Meta — expose SEO plugin meta fields in the REST API
 *
 * Registers key SEO meta fields with show_in_rest so that the sync tools
 * can pull/push focus keywords, meta descriptions, and SEO titles.
 *
 * Supports: Rank Math (default), Yoast, SEOPress.
 * Controlled by the sie_seo_plugin option (Settings → SIE → General).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_SEO_Meta {

    /**
     * Meta field mappings per SEO plugin.
     * Each entry: internal_key => post_meta_key
     */
    private const FIELD_MAP = [
        'rankmath' => [
            'focus_keyword'    => 'rank_math_focus_keyword',
            'meta_description' => 'rank_math_description',
            'seo_title'        => 'rank_math_title',
            'seo_score'        => 'rank_math_seo_score',
            'robots'           => 'rank_math_robots',
        ],
        'yoast' => [
            'focus_keyword'    => '_yoast_wpseo_focuskw',
            'meta_description' => '_yoast_wpseo_metadesc',
            'seo_title'        => '_yoast_wpseo_title',
        ],
        'seopress' => [
            'focus_keyword'    => '_seopress_analysis_target_kw',
            'meta_description' => '_seopress_titles_desc',
            'seo_title'        => '_seopress_titles_title',
        ],
    ];

    public function init() {
        add_action( 'init', [ $this, 'register_meta_fields' ], 20 );
    }

    /**
     * Get the active SEO plugin identifier.
     */
    private function get_seo_plugin(): string {
        $plugin = get_option( 'sie_seo_plugin', 'auto' );

        if ( $plugin === 'auto' ) {
            // Auto-detect
            if ( class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' ) ) {
                return 'rankmath';
            }
            if ( defined( 'WPSEO_VERSION' ) ) {
                return 'yoast';
            }
            if ( defined( 'SEOPRESS_VERSION' ) ) {
                return 'seopress';
            }
            return 'rankmath'; // default fallback
        }

        return $plugin;
    }

    /**
     * Register SEO meta fields for all public post types with show_in_rest.
     */
    public function register_meta_fields() {
        $plugin = $this->get_seo_plugin();
        $fields = self::FIELD_MAP[ $plugin ] ?? self::FIELD_MAP['rankmath'];

        // Register for all public post types
        $post_types = get_post_types( [ 'public' => true ], 'names' );

        foreach ( $post_types as $post_type ) {
            foreach ( $fields as $internal_key => $meta_key ) {
                register_post_meta( $post_type, $meta_key, [
                    'type'              => 'string',
                    'single'            => true,
                    'show_in_rest'      => true,
                    'sanitize_callback' => 'sanitize_text_field',
                    'auth_callback'     => fn() => current_user_can( 'edit_posts' ),
                ] );
            }
        }
    }

    /**
     * Get the field mapping for the active SEO plugin.
     * Used by sync tools to know which meta keys to read/write.
     */
    public static function get_field_map(): array {
        $instance = new self();
        $plugin = $instance->get_seo_plugin();
        return self::FIELD_MAP[ $plugin ] ?? self::FIELD_MAP['rankmath'];
    }

    /**
     * Get all supported SEO plugins for the settings dropdown.
     */
    public static function get_supported_plugins(): array {
        return [
            'auto'     => 'Auto-detect',
            'rankmath' => 'Rank Math',
            'yoast'    => 'Yoast SEO',
            'seopress' => 'SEOPress',
        ];
    }
}
