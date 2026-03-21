<?php
/**
 * SIE Custom Post Types & Taxonomies
 *
 * Knowledge Base — the primary CPT for synced KB articles, with a
 * hierarchical knowledge_topic taxonomy for /kb/topic/subtopic/post/ URLs.
 *
 * Knowledge triad (FAQ, Insight, Guide) — three CPTs that power the chat:
 *   - FAQ     → "What" (declarative knowledge, definitions, comparisons)
 *   - Insight → "How"  (procedural, actionable guidance)
 *   - Guide   → "Which/When" (decision support, recommendations)
 *
 * sie_topic taxonomy connects all triad CPTs plus any additional post types
 * opted in via the "SIE-connected post types" setting.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_CPT {

    public function init() {
        add_action( 'init', [ $this, 'register_post_types' ] );
        add_action( 'init', [ $this, 'register_taxonomies' ] );
        add_action( 'init', [ $this, 'connect_external_cpts' ], 99 );
    }

    // -------------------------------------------------------------------------
    // Post Types
    // -------------------------------------------------------------------------

    public function register_post_types() {

        // Knowledge Base — primary CPT for synced KB articles
        register_post_type( 'knowledge_base', [
            'labels' => self::labels( 'Knowledge Base', 'Knowledge Base' ),
            'public'              => true,
            'publicly_queryable'  => true,
            'has_archive'         => 'kb',
            'rewrite'             => [ 'slug' => 'kb/%knowledge_topic%', 'with_front' => false ],
            'menu_icon'           => 'dashicons-book-alt',
            'menu_position'       => 24,
            'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
            'show_in_rest'        => true,
            'rest_base'           => 'knowledge-base',
            'show_in_nav_menus'   => true,
            'taxonomies'          => [ 'knowledge_topic' ],
        ] );

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

        // Insight — "How do I...?"
        register_post_type( 'sie_insight', [
            'labels' => self::labels( 'Insight', 'Insights' ),
            'public'              => true,
            'has_archive'         => true,
            'rewrite'             => [ 'slug' => 'insights', 'with_front' => false ],
            'menu_icon'           => 'dashicons-lightbulb',
            'menu_position'       => 26,
            'supports'            => [ 'title', 'editor', 'excerpt', 'thumbnail', 'custom-fields', 'revisions' ],
            'show_in_rest'        => true,
            'rest_base'           => 'insights',
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
    // Taxonomies
    // -------------------------------------------------------------------------

    public function register_taxonomies() {

        // knowledge_topic — hierarchical taxonomy for Knowledge Base articles
        register_taxonomy( 'knowledge_topic', [ 'knowledge_base' ], [
            'labels' => [
                'name'              => 'Knowledge Topics',
                'singular_name'     => 'Knowledge Topic',
                'search_items'      => 'Search Knowledge Topics',
                'all_items'         => 'All Knowledge Topics',
                'parent_item'       => 'Parent Topic',
                'parent_item_colon' => 'Parent Topic:',
                'edit_item'         => 'Edit Knowledge Topic',
                'update_item'       => 'Update Knowledge Topic',
                'add_new_item'      => 'Add New Knowledge Topic',
                'new_item_name'     => 'New Knowledge Topic Name',
                'menu_name'         => 'Knowledge Topics',
            ],
            'hierarchical'      => true,
            'public'            => true,
            'publicly_queryable' => true,
            'show_ui'           => true,
            'show_in_rest'      => true,
            'rest_base'         => 'knowledge-topics',
            'show_in_nav_menus' => true,
            'rewrite'           => [ 'slug' => 'kb-topic', 'with_front' => false, 'hierarchical' => true ],
            'show_admin_column' => true,
        ] );

        // sie_topic — shared across triad CPTs + opted-in external CPTs
        register_taxonomy( 'sie_topic', [ 'sie_faq', 'sie_insight', 'sie_guide' ], [
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
    // Connect external CPTs to sie_topic
    // -------------------------------------------------------------------------

    /**
     * Attach sie_topic taxonomy to any additional post types selected in settings.
     * Runs at priority 99 so all CPTs (Avada, Woo, etc.) are registered first.
     */
    public function connect_external_cpts() {
        $connected = get_option( 'sie_connected_cpts', [] );
        if ( ! is_array( $connected ) || empty( $connected ) ) return;

        foreach ( $connected as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                register_taxonomy_for_object_type( 'sie_topic', $post_type );
            }
        }
    }

    /**
     * Get all public post types available for connection (excludes SIE's own CPTs).
     */
    public static function get_connectable_cpts(): array {
        $all = get_post_types( [ 'public' => true ], 'objects' );
        $sie_types = [ 'knowledge_base', 'sie_faq', 'sie_insight', 'sie_guide', 'attachment' ];

        $connectable = [];
        foreach ( $all as $slug => $obj ) {
            if ( in_array( $slug, $sie_types, true ) ) continue;
            $connectable[ $slug ] = $obj->labels->name;
        }

        return $connectable;
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
