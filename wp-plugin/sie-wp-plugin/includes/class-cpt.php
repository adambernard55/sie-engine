<?php
/**
 * SIE Custom Post Types — FAQ, Pro Tip, Guide
 *
 * Three universal CPTs that power the chat's knowledge triad:
 *   - FAQ       → "What" (declarative knowledge, definitions, comparisons)
 *   - Pro Tip   → "How"  (procedural, actionable guidance)
 *   - Guide     → "Which/When" (decision support, recommendations)
 *
 * Shared taxonomy: sie_topic — connects all three CPTs (and optionally
 * posts/products) so the chat can filter by subject area.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_CPT {

    public function init() {
        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
    }

    // -------------------------------------------------------------------------
    // Post Types
    // -------------------------------------------------------------------------

    public function register_post_types() {

        // FAQ — "What is...?"
        register_post_type( 'sie_faq', [
            'labels' => self::labels( 'FAQ', 'FAQs' ),
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => [ 'slug' => 'faq', 'with_front' => false ],
            'menu_icon'           => 'dashicons-editor-help',
            'menu_position'       => 25,
            'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
            'show_in_rest'        => true,
            'rest_base'           => 'faq',
            'taxonomies'          => [ 'sie_topic' ],
        ] );

        // Pro Tip — "How do I...?"
        register_post_type( 'sie_pro_tip', [
            'labels' => self::labels( 'Pro Tip', 'Pro Tips' ),
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => [ 'slug' => 'pro-tips', 'with_front' => false ],
            'menu_icon'           => 'dashicons-lightbulb',
            'menu_position'       => 26,
            'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
            'show_in_rest'        => true,
            'rest_base'           => 'pro-tips',
            'taxonomies'          => [ 'sie_topic' ],
        ] );

        // Guide — "Which one / when should I...?"
        register_post_type( 'sie_guide', [
            'labels' => self::labels( 'Guide', 'Guides' ),
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => [ 'slug' => 'guides', 'with_front' => false ],
            'menu_icon'           => 'dashicons-compass',
            'menu_position'       => 27,
            'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
            'show_in_rest'        => true,
            'rest_base'           => 'guides',
            'taxonomies'          => [ 'sie_topic' ],
        ] );
    }

    // -------------------------------------------------------------------------
    // Shared Taxonomy
    // -------------------------------------------------------------------------

    public function register_taxonomies() {

        // sie_topic — shared across FAQ, Pro Tip, Guide (and optionally post/product)
        register_taxonomy( 'sie_topic', [ 'sie_faq', 'sie_pro_tip', 'sie_guide' ], [
            'labels' => [
                'name'              => 'SIE Topics',
                'singular_name'     => 'SIE Topic',
                'search_items'      => 'Search Topics',
                'all_items'         => 'All Topics',
                'parent_item'       => 'Parent Topic',
                'parent_item_colon' => 'Parent Topic:',
                'edit_item'         => 'Edit Topic',
                'update_item'       => 'Update Topic',
                'add_new_item'      => 'Add New Topic',
                'new_item_name'     => 'New Topic Name',
                'menu_name'         => 'SIE Topics',
            ],
            'hierarchical'  => true,
            'public'        => true,
            'show_in_rest'  => true,
            'rest_base'     => 'sie-topics',
            'rewrite'       => [ 'slug' => 'topic', 'with_front' => false ],
            'show_admin_column' => true,
        ] );
    }

    // -------------------------------------------------------------------------
    // Helper
    // -------------------------------------------------------------------------

    private static function labels( string $singular, string $plural ): array {
        return [
            'name'               => $plural,
            'singular_name'      => $singular,
            'add_new'            => "Add New {$singular}",
            'add_new_item'       => "Add New {$singular}",
            'edit_item'          => "Edit {$singular}",
            'new_item'           => "New {$singular}",
            'view_item'          => "View {$singular}",
            'search_items'       => "Search {$plural}",
            'not_found'          => "No {$plural} found",
            'not_found_in_trash' => "No {$plural} found in Trash",
            'all_items'          => "All {$plural}",
            'menu_name'          => $plural,
        ];
    }
}
