<?php
/**
 * Topic Discovery API
 *
 * Exposes GET /wp-json/sie/v1/topics → { "/AI/0_fundamentals/": 1186, ... }
 * Also adds a "KB Path Pattern" meta field to each knowledge_topics term so
 * kb_sync.py can fetch the full mapping dynamically instead of hardcoding IDs.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Topic_API {

    public function init() {
        add_action( 'rest_api_init',                        [ $this, 'register_routes' ] );
        add_action( 'knowledge_topics_edit_form_fields',    [ $this, 'render_edit_field'  ], 10, 2 );
        add_action( 'knowledge_topics_add_form_fields',     [ $this, 'render_add_field'   ], 10, 1 );
        add_action( 'edited_knowledge_topics',              [ $this, 'save_field' ] );
        add_action( 'create_knowledge_topics',              [ $this, 'save_field' ] );
    }

    // -------------------------------------------------------------------------
    // REST endpoint
    // -------------------------------------------------------------------------

    public function register_routes() {
        register_rest_route( 'sie/v1', '/topics', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'get_topics' ],
            'permission_callback' => [ $this, 'auth_check' ],
        ] );
    }

    /** Requires the same WP app-password auth used by kb_sync. */
    public function auth_check() {
        return current_user_can( 'edit_posts' );
    }

    public function get_topics( WP_REST_Request $request ) {
        $terms = get_terms( [
            'taxonomy'   => 'knowledge_topics',
            'hide_empty' => false,
        ] );

        if ( is_wp_error( $terms ) ) {
            return new WP_Error( 'sie_terms_error', 'Could not retrieve topics.', [ 'status' => 500 ] );
        }

        $mapping = [];
        foreach ( $terms as $term ) {
            $pattern = get_term_meta( $term->term_id, '_sie_path_pattern', true );
            if ( $pattern ) {
                $mapping[ $pattern ] = $term->term_id;
            }
        }

        // Most-specific paths first (longest match wins) — mirrors kb_sync.py sort order.
        uksort( $mapping, fn( $a, $b ) => strlen( $b ) - strlen( $a ) );

        return rest_ensure_response( $mapping );
    }

    // -------------------------------------------------------------------------
    // Taxonomy term meta field
    // -------------------------------------------------------------------------

    public function render_edit_field( $term, $taxonomy ) {
        $value = get_term_meta( $term->term_id, '_sie_path_pattern', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="sie_path_pattern">KB Path Pattern</label></th>
            <td>
                <input type="text" name="sie_path_pattern" id="sie_path_pattern"
                       value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
                <p class="description">
                    The KB folder this topic maps to, e.g. <code>/AI/0_fundamentals/</code>.
                    Must start and end with <code>/</code>.
                </p>
            </td>
        </tr>
        <?php
    }

    public function render_add_field( $taxonomy ) {
        ?>
        <div class="form-field">
            <label for="sie_path_pattern">KB Path Pattern</label>
            <input type="text" name="sie_path_pattern" id="sie_path_pattern" value="" class="regular-text" />
            <p class="description">
                The KB folder this topic maps to, e.g. <code>/AI/0_fundamentals/</code>.
                Must start and end with <code>/</code>.
            </p>
        </div>
        <?php
    }

    public function save_field( $term_id ) {
        if ( isset( $_POST['sie_path_pattern'] ) ) {
            update_term_meta(
                $term_id,
                '_sie_path_pattern',
                sanitize_text_field( wp_unslash( $_POST['sie_path_pattern'] ) )
            );
        }
    }
}
