<?php
/**
 * Hierarchical Permalinks for Knowledge Base
 *
 * Overrides the knowledge_base CPT rewrite slug to include the full
 * knowledge_topic taxonomy hierarchy in the URL.
 *
 * Result: /kb/ai/methods/mcp/post-slug/
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Permalink {

    public function init() {
        // Try to catch the CPT registration event (may already have fired).
        add_action( 'registered_post_type_knowledge_base', [ $this, 'override_rewrite' ], 10, 2 );

        // Fallback: if the CPT was already registered before this plugin loaded,
        // override the permastruct directly during init.
        add_action( 'init', [ $this, 'late_override_rewrite' ], 98 );

        add_filter( 'post_type_link',                      [ $this, 'resolve_link' ], 10, 2 );
        add_action( 'init',                                [ $this, 'add_rewrite_rules' ], 99 );
    }

    /**
     * Override the CPT rewrite slug after CPT UI registers it.
     */
    public function override_rewrite( $post_type, $args ) {
        global $wp_rewrite;

        $args->rewrite = [
            'slug'       => 'kb/%knowledge_topic%',
            'with_front' => false,
        ];

        // Re-add the permastruct with the new slug.
        $wp_rewrite->extra_permastructs['knowledge_base'] = [
            'struct'   => '/kb/%knowledge_topic%/%knowledge_base%',
            'ep_mask'  => EP_NONE,
            'paged'    => false,
            'feed'     => false,
            'walk_dirs' => false,
        ];
    }

    /**
     * Fallback: override the permastruct if the CPT was registered before
     * this plugin loaded (common with CPT UI which runs on init priority 9).
     */
    public function late_override_rewrite() {
        global $wp_rewrite;

        if ( ! post_type_exists( 'knowledge_base' ) ) {
            return;
        }

        // Only override if the permastruct hasn't been set correctly yet.
        if ( isset( $wp_rewrite->extra_permastructs['knowledge_base'] )
             && false !== strpos( $wp_rewrite->extra_permastructs['knowledge_base']['struct'], '%knowledge_topic%' ) ) {
            return; // Already set by override_rewrite().
        }

        $wp_rewrite->extra_permastructs['knowledge_base'] = [
            'struct'    => '/kb/%knowledge_topic%/%knowledge_base%',
            'ep_mask'   => EP_NONE,
            'paged'     => false,
            'feed'      => false,
            'walk_dirs' => false,
        ];
    }

    /**
     * Add a catch-all rewrite rule for kb/ with any depth of taxonomy path.
     *
     * WordPress can't infer variable-depth hierarchies from a permastruct,
     * so we add a broad regex that captures everything after /kb/ and
     * resolves it to a knowledge_base post by slug (the last segment).
     */
    public function add_rewrite_rules() {
        // Match /kb/anything/post-slug/ — the post slug is the last segment.
        add_rewrite_rule(
            '^kb/(.+)/([^/]+)/?$',
            'index.php?knowledge_base=$matches[2]',
            'top'
        );

        // Keep the flat /kb/post-slug/ rule as fallback.
        add_rewrite_rule(
            '^kb/([^/]+)/?$',
            'index.php?knowledge_base=$matches[1]',
            'top'
        );
    }

    /**
     * Replace %knowledge_topic% in the permalink with the full term hierarchy.
     *
     * For a post assigned to MCP (child of Methods, child of AI):
     *   %knowledge_topic% → ai/methods/mcp
     */
    public function resolve_link( $post_link, $post ) {
        if ( 'knowledge_base' !== $post->post_type ) {
            return $post_link;
        }

        if ( false === strpos( $post_link, '%knowledge_topic%' ) ) {
            return $post_link;
        }

        $terms = wp_get_object_terms( $post->ID, 'knowledge_topic' );

        if ( is_wp_error( $terms ) || empty( $terms ) ) {
            // No topic assigned — fall back to 'uncategorized'.
            return str_replace( '%knowledge_topic%', 'uncategorized', $post_link );
        }

        // Find the deepest (most specific) term.
        $deepest = $this->get_deepest_term( $terms );

        // Build the full ancestor path: ai/methods/mcp
        $path = $this->build_term_path( $deepest );

        return str_replace( '%knowledge_topic%', $path, $post_link );
    }

    /**
     * Find the deepest term (the one with the most ancestors).
     */
    private function get_deepest_term( array $terms ): object {
        $max_depth = -1;
        $deepest   = $terms[0];

        foreach ( $terms as $term ) {
            $ancestors = get_ancestors( $term->term_id, 'knowledge_topic', 'taxonomy' );
            if ( count( $ancestors ) > $max_depth ) {
                $max_depth = count( $ancestors );
                $deepest   = $term;
            }
        }

        return $deepest;
    }

    /**
     * Build the full slug path from a term up to its root ancestor.
     *
     * Term "mcp" (parent: methods, grandparent: ai) → "ai/methods/mcp"
     */
    private function build_term_path( object $term ): string {
        $slugs = [ $term->slug ];
        $ancestors = get_ancestors( $term->term_id, 'knowledge_topic', 'taxonomy' );

        foreach ( $ancestors as $ancestor_id ) {
            $ancestor = get_term( $ancestor_id, 'knowledge_topic' );
            if ( ! is_wp_error( $ancestor ) ) {
                array_unshift( $slugs, $ancestor->slug );
            }
        }

        return implode( '/', $slugs );
    }
}
