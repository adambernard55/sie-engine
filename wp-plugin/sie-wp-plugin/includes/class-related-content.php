<?php
/**
 * SIE Related Content — Shortcodes & Auto-Display
 *
 * Displays FAQs, Pro Tips (Insights), and Guides related to the current
 * post/page/product. Relationship is determined by:
 *   1. Explicit meta — `_sie_related_posts` on the triad CPT (array of post IDs)
 *   2. Taxonomy fallback — shared `sie_topic` terms
 *
 * Shortcodes:
 *   [sie_faqs]     — FAQs related to current post
 *   [sie_insights] — Pro Tips / Insights related to current post
 *   [sie_guides]   — Guides related to current post
 *   [sie_related]  — All three combined
 *
 * Auto-append:
 *   Enable via Settings → SIE → "Auto-append related content" checkbox.
 *   Appends [sie_related] output after the_content on selected post types.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Related_Content {

    /** CPT slugs in display order */
    private const CPT_SLUGS = [ 'sie_faq', 'sie_insight', 'sie_guide' ];

    /** Default icons per CPT */
    private const CPT_ICONS = [
        'sie_faq'     => 'dashicons-editor-help',
        'sie_insight' => 'dashicons-lightbulb',
        'sie_guide'   => 'dashicons-compass',
    ];

    /**
     * Build CPT map dynamically using editable labels from settings.
     */
    private function get_cpt_map(): array {
        $triad = SIE_CPT::triad_labels();
        return [
            'sie_faq' => [
                'label'   => $triad['sie_faq']['plural'],
                'icon'    => self::CPT_ICONS['sie_faq'],
                'heading' => 'Frequently Asked Questions',
            ],
            'sie_insight' => [
                'label'   => $triad['sie_insight']['plural'],
                'icon'    => self::CPT_ICONS['sie_insight'],
                'heading' => 'Related ' . $triad['sie_insight']['plural'],
            ],
            'sie_guide' => [
                'label'   => $triad['sie_guide']['plural'],
                'icon'    => self::CPT_ICONS['sie_guide'],
                'heading' => 'Related ' . $triad['sie_guide']['plural'],
            ],
        ];
    }

    /** Meta key used for explicit relationships */
    private const META_KEY = '_sie_related_posts';

    /** Post types that support auto-append */
    private const AUTO_APPEND_TYPES = [ 'knowledge_base', 'post', 'page', 'product' ];

    public function init() {
        add_shortcode( 'sie_faqs',     [ $this, 'shortcode_faqs' ] );
        add_shortcode( 'sie_insights', [ $this, 'shortcode_insights' ] );
        add_shortcode( 'sie_guides',   [ $this, 'shortcode_guides' ] );
        add_shortcode( 'sie_related',  [ $this, 'shortcode_related' ] );
        add_shortcode( 'sie_archive',  [ $this, 'shortcode_archive' ] );

        // Auto-append if enabled
        add_filter( 'the_content', [ $this, 'maybe_auto_append' ], 90 );

        // Admin: meta box for setting explicit relationships
        add_action( 'add_meta_boxes', [ $this, 'add_relationship_meta_box' ] );
        add_action( 'save_post',      [ $this, 'save_relationship_meta' ] );

        // Enqueue styles
        add_action( 'wp_enqueue_scripts', [ $this, 'enqueue_styles' ] );
    }

    // =========================================================================
    // Shortcodes
    // =========================================================================

    public function shortcode_faqs( $atts ) {
        return $this->render_related( 'sie_faq', $atts );
    }

    public function shortcode_insights( $atts ) {
        return $this->render_related( 'sie_insight', $atts );
    }

    public function shortcode_guides( $atts ) {
        return $this->render_related( 'sie_guide', $atts );
    }

    public function shortcode_related( $atts ) {
        $atts = shortcode_atts( [
            'post_id' => 0,
            'limit'   => 5,
            'style'   => 'accordion', // accordion | list | cards
        ], $atts, 'sie_related' );

        $output = '';
        foreach ( self::CPT_SLUGS as $cpt ) {
            $output .= $this->render_related( $cpt, $atts );
        }
        return $output;
    }

    // =========================================================================
    // Core Query & Render
    // =========================================================================

    /**
     * Find and render related items of a given CPT for the current (or specified) post.
     */
    private function render_related( string $cpt, $atts = [] ): string {
        $atts = shortcode_atts( [
            'post_id' => 0,
            'limit'   => 5,
            'style'   => 'accordion',
        ], (array) $atts );

        $post_id = absint( $atts['post_id'] ) ?: get_the_ID();
        if ( ! $post_id ) return '';

        $limit = absint( $atts['limit'] ) ?: 5;
        $style = sanitize_key( $atts['style'] );

        $related_ids = $this->get_related_ids( $cpt, $post_id, $limit );
        if ( empty( $related_ids ) ) return '';

        $posts = get_posts( [
            'post_type'      => $cpt,
            'post__in'       => $related_ids,
            'posts_per_page' => $limit,
            'orderby'        => 'post__in',
            'post_status'    => 'publish',
        ] );

        if ( empty( $posts ) ) return '';

        $cpt_map = $this->get_cpt_map();
        $meta    = $cpt_map[ $cpt ] ?? $cpt_map['sie_faq'];
        $method = "render_{$style}";

        if ( ! method_exists( $this, $method ) ) {
            $method = 'render_accordion';
        }

        return $this->$method( $posts, $meta, $cpt );
    }

    /**
     * Get related post IDs — explicit meta first, taxonomy fallback.
     */
    private function get_related_ids( string $cpt, int $post_id, int $limit ): array {
        // 1. Explicit: query triad CPTs where _sie_related_posts contains this post ID
        $explicit = get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'meta_query'     => [
                [
                    'key'     => self::META_KEY,
                    'value'   => sprintf( '"%d"', $post_id ),
                    'compare' => 'LIKE',
                ],
            ],
            'fields' => 'ids',
        ] );

        if ( ! empty( $explicit ) ) {
            return $explicit;
        }

        // 2. Taxonomy fallback: match via sie_topic terms
        $terms = wp_get_object_terms( $post_id, 'sie_topic', [ 'fields' => 'ids' ] );

        // Also check knowledge_topic and map to sie_topic by slug
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            $kt = wp_get_object_terms( $post_id, 'knowledge_topic', [ 'fields' => 'slugs' ] );
            if ( ! empty( $kt ) && ! is_wp_error( $kt ) ) {
                $terms = [];
                foreach ( $kt as $slug ) {
                    $st = get_term_by( 'slug', $slug, 'sie_topic' );
                    if ( $st ) $terms[] = $st->term_id;
                }
            }
        }

        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return [];
        }

        return get_posts( [
            'post_type'      => $cpt,
            'posts_per_page' => $limit,
            'post_status'    => 'publish',
            'tax_query'      => [
                [
                    'taxonomy' => 'sie_topic',
                    'terms'    => $terms,
                    'field'    => 'term_id',
                ],
            ],
            'fields' => 'ids',
        ] );
    }

    // =========================================================================
    // Render Styles
    // =========================================================================

    /**
     * Accordion style — collapsible Q&A format, ideal for FAQs.
     * Uses <details>/<summary> for no-JS progressive enhancement.
     */
    private function render_accordion( array $posts, array $meta, string $cpt ): string {
        $html  = '<div class="sie-related sie-related--accordion sie-related--' . esc_attr( $cpt ) . '">';
        $html .= '<h3 class="sie-related__heading">';
        $html .= '<span class="dashicons ' . esc_attr( $meta['icon'] ) . '"></span> ';
        $html .= esc_html( $meta['heading'] );
        $html .= '</h3>';

        foreach ( $posts as $p ) {
            $html .= '<details class="sie-related__item">';
            $html .= '<summary class="sie-related__question">' . esc_html( $p->post_title ) . '</summary>';
            $html .= '<div class="sie-related__answer">';
            $html .= wp_kses_post( apply_filters( 'the_content', $p->post_content ) );
            $permalink = get_permalink( $p->ID );
            $html .= '<a href="' . esc_url( $permalink ) . '" class="sie-related__link">Read more</a>';
            $html .= '</div>';
            $html .= '</details>';
        }

        $html .= '</div>';
        return $html;
    }

    /**
     * List style — simple linked list with excerpts.
     */
    private function render_list( array $posts, array $meta, string $cpt ): string {
        $html  = '<div class="sie-related sie-related--list sie-related--' . esc_attr( $cpt ) . '">';
        $html .= '<h3 class="sie-related__heading">';
        $html .= '<span class="dashicons ' . esc_attr( $meta['icon'] ) . '"></span> ';
        $html .= esc_html( $meta['heading'] );
        $html .= '</h3>';
        $html .= '<ul class="sie-related__list">';

        foreach ( $posts as $p ) {
            $excerpt = $p->post_excerpt ?: wp_trim_words( $p->post_content, 25 );
            $html .= '<li class="sie-related__item">';
            $html .= '<a href="' . esc_url( get_permalink( $p->ID ) ) . '" class="sie-related__link">';
            $html .= esc_html( $p->post_title );
            $html .= '</a>';
            $html .= '<p class="sie-related__excerpt">' . esc_html( $excerpt ) . '</p>';
            $html .= '</li>';
        }

        $html .= '</ul></div>';
        return $html;
    }

    /**
     * Cards style — grid of cards with title, excerpt, and link.
     */
    private function render_cards( array $posts, array $meta, string $cpt ): string {
        $html  = '<div class="sie-related sie-related--cards sie-related--' . esc_attr( $cpt ) . '">';
        $html .= '<h3 class="sie-related__heading">';
        $html .= '<span class="dashicons ' . esc_attr( $meta['icon'] ) . '"></span> ';
        $html .= esc_html( $meta['heading'] );
        $html .= '</h3>';
        $html .= '<div class="sie-related__grid">';

        foreach ( $posts as $p ) {
            $excerpt = $p->post_excerpt ?: wp_trim_words( $p->post_content, 30 );
            $html .= '<div class="sie-related__card">';
            $html .= '<h4 class="sie-related__card-title">';
            $html .= '<a href="' . esc_url( get_permalink( $p->ID ) ) . '">' . esc_html( $p->post_title ) . '</a>';
            $html .= '</h4>';
            $html .= '<p class="sie-related__card-excerpt">' . esc_html( $excerpt ) . '</p>';
            $html .= '<a href="' . esc_url( get_permalink( $p->ID ) ) . '" class="sie-related__card-link">Read more &rarr;</a>';
            $html .= '</div>';
        }

        $html .= '</div></div>';
        return $html;
    }

    // =========================================================================
    // Auto-Append
    // =========================================================================

    public function maybe_auto_append( string $content ): string {
        if ( ! is_singular( self::AUTO_APPEND_TYPES ) ) return $content;
        if ( ! get_option( 'sie_auto_related', '0' ) )  return $content;

        // Don't double-render if shortcode already present
        if ( has_shortcode( $content, 'sie_related' ) || has_shortcode( $content, 'sie_faqs' )
            || has_shortcode( $content, 'sie_insights' ) || has_shortcode( $content, 'sie_guides' ) ) {
            return $content;
        }

        $related = do_shortcode( '[sie_related style="accordion"]' );
        if ( empty( trim( strip_tags( $related ) ) ) ) return $content;

        return $content . "\n\n" . '<div class="sie-related-auto">' . $related . '</div>';
    }

    // =========================================================================
    // Admin: Relationship Meta Box
    // =========================================================================

    public function add_relationship_meta_box() {
        foreach ( self::CPT_SLUGS as $cpt ) {
            add_meta_box(
                'sie_related_posts',
                'Related Posts / Pages',
                [ $this, 'render_meta_box' ],
                $cpt,
                'side',
                'default'
            );
        }
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'sie_related_nonce', 'sie_related_nonce_field' );
        $related = get_post_meta( $post->ID, self::META_KEY, true );
        $related = is_array( $related ) ? $related : [];

        // Build display list of currently related posts
        $related_display = [];
        foreach ( $related as $rid ) {
            $rp = get_post( $rid );
            if ( $rp ) {
                $related_display[] = $rp;
            }
        }
        ?>
        <p class="description">
            Attach this <?php $cm = $this->get_cpt_map(); echo esc_html( $cm[ $post->post_type ]['label'] ?? 'item' ); ?>
            to specific posts, pages, or KB articles. It will appear on those pages via
            <code>[sie_related]</code> or auto-append.
        </p>

        <div id="sie-related-list">
            <?php foreach ( $related_display as $rp ) : ?>
                <div class="sie-related-tag" data-id="<?php echo esc_attr( $rp->ID ); ?>">
                    <span><?php echo esc_html( $rp->post_title ); ?></span>
                    <button type="button" class="sie-related-remove" aria-label="Remove">&times;</button>
                    <input type="hidden" name="sie_related_posts[]" value="<?php echo esc_attr( $rp->ID ); ?>" />
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:8px;">
            <input type="text" id="sie-related-search" placeholder="Search posts..." class="widefat" autocomplete="off" />
            <div id="sie-related-results" style="max-height:200px;overflow-y:auto;border:1px solid #ddd;display:none;"></div>
        </div>

        <script>
        (function(){
            const search = document.getElementById('sie-related-search');
            const results = document.getElementById('sie-related-results');
            const list = document.getElementById('sie-related-list');
            let timer;

            search.addEventListener('input', function(){
                clearTimeout(timer);
                const q = this.value.trim();
                if (q.length < 2) { results.style.display='none'; return; }
                timer = setTimeout(() => {
                    fetch(ajaxurl + '?action=sie_search_posts&q=' + encodeURIComponent(q) + '&nonce=<?php echo wp_create_nonce("sie_search"); ?>')
                        .then(r => r.json())
                        .then(data => {
                            if (!data.length) { results.innerHTML='<div style="padding:6px;">No results</div>'; }
                            else {
                                results.innerHTML = data.map(p =>
                                    '<div class="sie-result-item" data-id="'+p.ID+'" style="padding:6px;cursor:pointer;border-bottom:1px solid #eee;">'
                                    + '<strong>'+p.title+'</strong> <small>('+p.type+')</small></div>'
                                ).join('');
                            }
                            results.style.display='block';
                        });
                }, 300);
            });

            results.addEventListener('click', function(e){
                const item = e.target.closest('.sie-result-item');
                if (!item) return;
                const id = item.dataset.id;
                // Prevent duplicates
                if (list.querySelector('[data-id="'+id+'"]')) return;
                const tag = document.createElement('div');
                tag.className = 'sie-related-tag';
                tag.dataset.id = id;
                tag.innerHTML = '<span>'+item.querySelector('strong').textContent+'</span>'
                    + '<button type="button" class="sie-related-remove" aria-label="Remove">&times;</button>'
                    + '<input type="hidden" name="sie_related_posts[]" value="'+id+'" />';
                list.appendChild(tag);
                results.style.display='none';
                search.value='';
            });

            list.addEventListener('click', function(e){
                if (e.target.classList.contains('sie-related-remove')) {
                    e.target.closest('.sie-related-tag').remove();
                }
            });
        })();
        </script>

        <style>
            .sie-related-tag { display:inline-flex; align-items:center; gap:4px; background:#f0f0f1; border-radius:3px; padding:3px 8px; margin:3px 2px; font-size:12px; }
            .sie-related-remove { background:none; border:none; cursor:pointer; font-size:14px; color:#a00; padding:0 2px; }
            .sie-result-item:hover { background:#f0f6fc; }
        </style>
        <?php
    }

    public function save_relationship_meta( $post_id ) {
        if ( ! isset( $_POST['sie_related_nonce_field'] ) ) return;
        if ( ! wp_verify_nonce( $_POST['sie_related_nonce_field'], 'sie_related_nonce' ) ) return;
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        $related = isset( $_POST['sie_related_posts'] ) ? array_map( 'absint', (array) $_POST['sie_related_posts'] ) : [];
        $related = array_filter( $related );
        $related = array_values( array_unique( $related ) );

        update_post_meta( $post_id, self::META_KEY, $related );
    }

    // =========================================================================
    // AJAX Search for Meta Box
    // =========================================================================

    public static function register_ajax() {
        add_action( 'wp_ajax_sie_search_posts', [ __CLASS__, 'ajax_search_posts' ] );
    }

    public static function ajax_search_posts() {
        check_ajax_referer( 'sie_search', 'nonce' );

        $q = sanitize_text_field( $_GET['q'] ?? '' );
        if ( strlen( $q ) < 2 ) wp_send_json( [] );

        $searchable_types = [ 'knowledge_base', 'post', 'page', 'product' ];
        $existing_types   = array_filter( $searchable_types, 'post_type_exists' );

        $results = get_posts( [
            'post_type'      => $existing_types,
            'post_status'    => 'publish',
            's'              => $q,
            'posts_per_page' => 10,
        ] );

        $out = [];
        foreach ( $results as $p ) {
            $type_obj = get_post_type_object( $p->post_type );
            $out[] = [
                'ID'    => $p->ID,
                'title' => $p->post_title,
                'type'  => $type_obj ? $type_obj->labels->singular_name : $p->post_type,
            ];
        }

        wp_send_json( $out );
    }

    // =========================================================================
    // Frontend Styles
    // =========================================================================

    public function enqueue_styles() {
        // Enqueue on singular pages (for [sie_related]) and any page (for [sie_archive])
        wp_enqueue_style( 'dashicons' );
        wp_add_inline_style( 'dashicons', $this->get_inline_css() );
    }

    private function get_inline_css(): string {
        return <<<CSS
/* SIE Related Content */
.sie-related { margin: 2rem 0; }
.sie-related__heading {
    display: flex; align-items: center; gap: 8px;
    font-size: 1.25rem; margin-bottom: 1rem;
    padding-bottom: 0.5rem; border-bottom: 2px solid var(--sie-accent, #2563eb);
}
.sie-related__heading .dashicons { font-size: 1.25rem; width: 1.25rem; height: 1.25rem; color: var(--sie-accent, #2563eb); }

/* Accordion */
.sie-related--accordion .sie-related__item {
    border: 1px solid #e5e7eb; border-radius: 6px;
    margin-bottom: 0.5rem; overflow: hidden;
}
.sie-related--accordion .sie-related__question {
    padding: 0.875rem 1rem; cursor: pointer; font-weight: 600;
    list-style: none; display: flex; align-items: center; justify-content: space-between;
}
.sie-related--accordion .sie-related__question::after { content: '+'; font-size: 1.25rem; color: #6b7280; }
.sie-related--accordion details[open] .sie-related__question::after { content: '−'; }
.sie-related--accordion .sie-related__question::-webkit-details-marker { display: none; }
.sie-related--accordion .sie-related__answer { padding: 0 1rem 1rem; color: #374151; }
.sie-related--accordion .sie-related__link {
    display: inline-block; margin-top: 0.5rem; font-size: 0.875rem;
    color: var(--sie-accent, #2563eb); text-decoration: none;
}
.sie-related--accordion .sie-related__link:hover { text-decoration: underline; }

/* List */
.sie-related--list .sie-related__list { list-style: none; padding: 0; margin: 0; }
.sie-related--list .sie-related__item { padding: 0.75rem 0; border-bottom: 1px solid #e5e7eb; }
.sie-related--list .sie-related__item:last-child { border-bottom: none; }
.sie-related--list .sie-related__link { font-weight: 600; color: var(--sie-accent, #2563eb); text-decoration: none; }
.sie-related--list .sie-related__link:hover { text-decoration: underline; }
.sie-related--list .sie-related__excerpt { margin: 0.25rem 0 0; color: #6b7280; font-size: 0.875rem; }

/* Cards */
.sie-related__grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 1rem;
}
.sie-related__card {
    border: 1px solid #e5e7eb; border-radius: 8px;
    padding: 1.25rem; transition: box-shadow 0.2s;
}
.sie-related__card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); }
.sie-related__card-title { font-size: 1rem; margin: 0 0 0.5rem; }
.sie-related__card-title a { color: inherit; text-decoration: none; }
.sie-related__card-title a:hover { color: var(--sie-accent, #2563eb); }
.sie-related__card-excerpt { color: #6b7280; font-size: 0.875rem; margin: 0 0 0.75rem; }
.sie-related__card-link { font-size: 0.875rem; color: var(--sie-accent, #2563eb); text-decoration: none; }
.sie-related__card-link:hover { text-decoration: underline; }

/* Auto-append separator */
.sie-related-auto { margin-top: 3rem; padding-top: 2rem; border-top: 1px solid #e5e7eb; }

/* =========================== Archive page =========================== */
.sie-archive { margin: 2rem 0; }

/* Type tabs (FAQ / Insights / Guides) */
.sie-archive__tabs {
    display: flex; gap: 4px; margin-bottom: 1.5rem;
    border-bottom: 2px solid #e5e7eb; padding-bottom: 0;
}
.sie-archive__tab {
    padding: 0.625rem 1.25rem; cursor: pointer; font-weight: 500;
    border: none; background: none; font-size: 0.9375rem;
    color: #6b7280; border-bottom: 2px solid transparent;
    margin-bottom: -2px; transition: color 0.15s, border-color 0.15s;
}
.sie-archive__tab:hover { color: #111827; }
.sie-archive__tab--active {
    color: var(--sie-accent, #2563eb); border-bottom-color: var(--sie-accent, #2563eb); font-weight: 600;
}

/* Topic filter dropdown */
.sie-archive__filter {
    display: flex; align-items: center; gap: 10px; margin-bottom: 1.5rem; flex-wrap: wrap;
}
.sie-archive__filter label { font-weight: 600; font-size: 0.875rem; color: #374151; }
.sie-archive__filter select {
    padding: 0.5rem 2rem 0.5rem 0.75rem; border: 1px solid #d1d5db; border-radius: 6px;
    font-size: 0.875rem; background: #fff; min-width: 220px;
    appearance: none; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='8'%3E%3Cpath d='M1 1l5 5 5-5' stroke='%236b7280' stroke-width='1.5' fill='none'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 0.75rem center;
}
.sie-archive__count {
    font-size: 0.8125rem; color: #9ca3af; margin-left: auto;
}

/* Topic group headings */
.sie-archive__topic-heading {
    font-size: 1.1rem; font-weight: 600; color: #111827;
    margin: 1.75rem 0 0.75rem; padding-bottom: 0.375rem;
    border-bottom: 1px solid #e5e7eb;
}
.sie-archive__topic-heading:first-child { margin-top: 0; }

/* Empty state */
.sie-archive__empty {
    text-align: center; padding: 3rem 1rem; color: #9ca3af; font-size: 0.9375rem;
}

/* Content panel visibility */
.sie-archive__panel { display: none; }
.sie-archive__panel--active { display: block; }
CSS;
    }

    // =========================================================================
    // Archive Page — [sie_archive]
    // =========================================================================

    /**
     * Full archive page with type tabs and topic dropdown filter.
     *
     * Usage:
     *   [sie_archive]                  — all types, topic filter
     *   [sie_archive type="faq"]       — FAQs only
     *   [sie_archive type="insights"]  — Insights only
     *   [sie_archive type="guides"]    — Guides only
     *   [sie_archive style="cards"]    — change render style
     */
    public function shortcode_archive( $atts ) {
        $atts = shortcode_atts( [
            'type'  => 'all',    // all | faq | insights | guides
            'style' => 'accordion',
        ], $atts, 'sie_archive' );

        $cpt_map = $this->get_cpt_map();
        $type    = sanitize_key( $atts['type'] );
        $style   = sanitize_key( $atts['style'] );

        // Determine which CPTs to show
        $type_to_cpt = [
            'faq'      => 'sie_faq',
            'insights' => 'sie_insight',
            'guides'   => 'sie_guide',
        ];

        if ( $type === 'all' ) {
            $cpts = self::CPT_SLUGS;
        } elseif ( isset( $type_to_cpt[ $type ] ) ) {
            $cpts = [ $type_to_cpt[ $type ] ];
        } else {
            $cpts = self::CPT_SLUGS;
        }

        // Get all sie_topic terms that have posts
        $all_topics = get_terms( [
            'taxonomy'   => 'sie_topic',
            'hide_empty' => true,
            'orderby'    => 'name',
        ] );
        if ( is_wp_error( $all_topics ) ) $all_topics = [];

        // Fetch all posts for each CPT, grouped by topic
        $data = []; // cpt_slug => [ topic_slug => [ posts ] ]
        $totals = []; // cpt_slug => count

        foreach ( $cpts as $cpt ) {
            $posts = get_posts( [
                'post_type'      => $cpt,
                'post_status'    => 'publish',
                'posts_per_page' => -1,
                'orderby'        => 'title',
                'order'          => 'ASC',
            ] );

            $totals[ $cpt ] = count( $posts );
            $grouped = [ '_general' => [] ];

            foreach ( $posts as $p ) {
                $terms = wp_get_object_terms( $p->ID, 'sie_topic', [ 'fields' => 'slugs' ] );
                if ( empty( $terms ) || is_wp_error( $terms ) ) {
                    $grouped['_general'][] = $p;
                } else {
                    foreach ( $terms as $slug ) {
                        if ( ! isset( $grouped[ $slug ] ) ) $grouped[ $slug ] = [];
                        $grouped[ $slug ][] = $p;
                    }
                }
            }

            $data[ $cpt ] = $grouped;
        }

        // Build output
        $uid = 'sie-archive-' . wp_unique_id();
        $show_tabs = ( $type === 'all' && count( $cpts ) > 1 );

        ob_start();
        ?>
        <div class="sie-archive" id="<?php echo esc_attr( $uid ); ?>">

            <?php if ( $show_tabs ) : ?>
            <div class="sie-archive__tabs" role="tablist">
                <?php foreach ( $cpts as $i => $cpt ) :
                    $meta = $cpt_map[ $cpt ] ?? [];
                    $label = $meta['label'] ?? $cpt;
                    $count = $totals[ $cpt ] ?? 0;
                ?>
                    <button class="sie-archive__tab <?php echo $i === 0 ? 'sie-archive__tab--active' : ''; ?>"
                            role="tab" data-panel="<?php echo esc_attr( $cpt ); ?>"
                            aria-selected="<?php echo $i === 0 ? 'true' : 'false'; ?>">
                        <?php echo esc_html( $label ); ?> <span style="font-weight:400;color:#9ca3af;">(<?php echo $count; ?>)</span>
                    </button>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php foreach ( $cpts as $i => $cpt ) :
                $meta    = $cpt_map[ $cpt ] ?? [];
                $grouped = $data[ $cpt ] ?? [];
                $total   = $totals[ $cpt ] ?? 0;
                $active  = ( ! $show_tabs || $i === 0 ) ? ' sie-archive__panel--active' : '';

                // Collect topics that actually have posts for this CPT
                $cpt_topics = [];
                foreach ( $all_topics as $t ) {
                    if ( isset( $grouped[ $t->slug ] ) && ! empty( $grouped[ $t->slug ] ) ) {
                        $cpt_topics[] = $t;
                    }
                }
            ?>
            <div class="sie-archive__panel<?php echo $active; ?>" data-cpt="<?php echo esc_attr( $cpt ); ?>">

                <?php if ( ! empty( $cpt_topics ) || ! empty( $grouped['_general'] ) ) : ?>
                <div class="sie-archive__filter">
                    <label for="<?php echo esc_attr( $uid . '-filter-' . $cpt ); ?>">Filter by topic:</label>
                    <select id="<?php echo esc_attr( $uid . '-filter-' . $cpt ); ?>" class="sie-archive__select" data-cpt="<?php echo esc_attr( $cpt ); ?>">
                        <option value="all">All Topics</option>
                        <?php if ( ! empty( $grouped['_general'] ) ) : ?>
                            <option value="_general">General (<?php echo count( $grouped['_general'] ); ?>)</option>
                        <?php endif; ?>
                        <?php foreach ( $cpt_topics as $t ) : ?>
                            <option value="<?php echo esc_attr( $t->slug ); ?>">
                                <?php echo esc_html( $t->name ); ?> (<?php echo count( $grouped[ $t->slug ] ); ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <span class="sie-archive__count" data-cpt="<?php echo esc_attr( $cpt ); ?>">
                        <?php echo $total; ?> <?php echo esc_html( strtolower( $meta['label'] ?? 'items' ) ); ?>
                    </span>
                </div>
                <?php endif; ?>

                <?php if ( $total === 0 ) : ?>
                    <div class="sie-archive__empty">No <?php echo esc_html( strtolower( $meta['label'] ?? 'items' ) ); ?> yet.</div>
                <?php else : ?>

                    <?php // General (untagged) items ?>
                    <?php if ( ! empty( $grouped['_general'] ) ) : ?>
                        <div class="sie-archive__topic-group" data-topic="_general">
                            <h3 class="sie-archive__topic-heading">General</h3>
                            <?php echo $this->render_archive_items( $grouped['_general'], $style, $meta, $cpt ); ?>
                        </div>
                    <?php endif; ?>

                    <?php // Topic-grouped items ?>
                    <?php foreach ( $cpt_topics as $t ) : ?>
                        <div class="sie-archive__topic-group" data-topic="<?php echo esc_attr( $t->slug ); ?>">
                            <h3 class="sie-archive__topic-heading"><?php echo esc_html( $t->name ); ?></h3>
                            <?php echo $this->render_archive_items( $grouped[ $t->slug ], $style, $meta, $cpt ); ?>
                        </div>
                    <?php endforeach; ?>

                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>

        <script>
        (function(){
            var root = document.getElementById('<?php echo esc_js( $uid ); ?>');
            if (!root) return;

            // Tab switching
            root.querySelectorAll('.sie-archive__tab').forEach(function(tab) {
                tab.addEventListener('click', function() {
                    root.querySelectorAll('.sie-archive__tab').forEach(function(t) {
                        t.classList.remove('sie-archive__tab--active');
                        t.setAttribute('aria-selected', 'false');
                    });
                    root.querySelectorAll('.sie-archive__panel').forEach(function(p) {
                        p.classList.remove('sie-archive__panel--active');
                    });
                    tab.classList.add('sie-archive__tab--active');
                    tab.setAttribute('aria-selected', 'true');
                    var panel = root.querySelector('[data-cpt="' + tab.dataset.panel + '"]');
                    if (panel) panel.classList.add('sie-archive__panel--active');
                });
            });

            // Topic filtering
            root.querySelectorAll('.sie-archive__select').forEach(function(sel) {
                sel.addEventListener('change', function() {
                    var cpt = sel.dataset.cpt;
                    var topic = sel.value;
                    var panel = root.querySelector('.sie-archive__panel[data-cpt="' + cpt + '"]');
                    if (!panel) return;

                    var groups = panel.querySelectorAll('.sie-archive__topic-group');
                    var visible = 0;

                    groups.forEach(function(g) {
                        if (topic === 'all' || g.dataset.topic === topic) {
                            g.style.display = '';
                            visible += g.querySelectorAll('.sie-related__item, .sie-related__card, li.sie-related__item').length
                                || g.querySelectorAll('details, .sie-related__card, .sie-related__item').length;
                        } else {
                            g.style.display = 'none';
                        }
                    });

                    // Update count
                    var counter = root.querySelector('.sie-archive__count[data-cpt="' + cpt + '"]');
                    if (counter) {
                        var items = panel.querySelectorAll('.sie-archive__topic-group:not([style*="display: none"]) details, .sie-archive__topic-group:not([style*="display: none"]) .sie-related__card, .sie-archive__topic-group:not([style*="display: none"]) li.sie-related__item');
                        counter.textContent = items.length + ' ' + counter.textContent.replace(/^\d+\s*/, '');
                    }
                });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Render a set of archive items using the chosen style.
     */
    private function render_archive_items( array $posts, string $style, array $meta, string $cpt ): string {
        $method = "render_{$style}";
        if ( ! method_exists( $this, $method ) ) {
            $method = 'render_accordion';
        }
        return $this->$method( $posts, $meta, $cpt );
    }
}
