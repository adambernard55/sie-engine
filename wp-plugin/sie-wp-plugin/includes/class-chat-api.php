<?php
/**
 * AI Chat API + Widget
 *
 * REST endpoint: POST /wp-json/sie/v1/chat  { "query": "..." }
 * Shortcode:     [sie_chat]
 *
 * Flow: embed query → query Pinecone → confidence check → ask LLM → log → return.
 * Supports OpenAI and Anthropic providers (configurable in SIE Settings).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Chat_API {

    /** Max requests per IP per minute. */
    const RATE_LIMIT     = 10;
    const RATE_WINDOW    = 60; // seconds

    /** Max queries per day (all users combined) — cost ceiling. 0 = unlimited. */
    const DAILY_LIMIT_OPTION = 'sie_daily_query_limit';

    /** Track whether [sie_chat_page] was rendered on this request. */
    private $page_chat_active = false;

    public function init() {
        add_action( 'rest_api_init',       [ $this, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets'  ] );
        add_shortcode( 'sie_chat',         [ $this, 'render_widget'   ] );
        add_shortcode( 'sie_chat_page',    [ $this, 'render_page_chat' ] );
    }

    // -------------------------------------------------------------------------
    // REST endpoint
    // -------------------------------------------------------------------------

    public function register_routes() {
        register_rest_route( 'sie/v1', '/chat', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => [ $this, 'access_check' ],
            'show_in_index'       => false,
            'args'                => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
                'agent' => [
                    'required'          => false,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_key',
                    'default'           => '',
                ],
            ],
        ] );
    }

    public function access_check( WP_REST_Request $request ) {
        // 1. Nonce verification — blocks requests not originating from our widget
        $nonce = $request->get_header( 'X-WP-Nonce' );
        if ( ! $nonce || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
            return new WP_Error( 'sie_invalid_nonce', 'Invalid or missing security token.', [ 'status' => 403 ] );
        }

        // 2. Rate limiting — per IP
        $ip  = self::get_client_ip();
        $key = 'sie_rate_' . md5( $ip );
        $hits = (int) get_transient( $key );

        if ( $hits >= self::RATE_LIMIT ) {
            return new WP_Error( 'sie_rate_limited', 'Too many requests. Please wait a moment.', [ 'status' => 429 ] );
        }

        set_transient( $key, $hits + 1, self::RATE_WINDOW );

        // 3. Daily cost ceiling
        $daily_limit = (int) get_option( self::DAILY_LIMIT_OPTION, 0 );
        if ( $daily_limit > 0 ) {
            $today_key = 'sie_daily_count_' . gmdate( 'Y-m-d' );
            $today_count = (int) get_transient( $today_key );
            if ( $today_count >= $daily_limit ) {
                return new WP_Error( 'sie_daily_limit', 'Daily query limit reached. Please try again tomorrow.', [ 'status' => 429 ] );
            }
            set_transient( $today_key, $today_count + 1, DAY_IN_SECONDS );
        }

        // 4. Access level check
        $access = get_option( 'sie_chat_access', 'logged_in' );

        if ( $access === 'logged_in' ) return is_user_logged_in();

        if ( $access === 'role' ) {
            $role = get_option( 'sie_chat_role', 'subscriber' );
            return current_user_can( $role );
        }

        // Public — but apply guest limits if not logged in
        if ( $access === 'public' && ! is_user_logged_in() ) {
            $guest_limit = (int) get_option( 'sie_guest_query_limit', 3 );
            if ( $guest_limit > 0 ) {
                $guest_key   = 'sie_guest_' . md5( $ip );
                $guest_count = (int) get_transient( $guest_key );
                if ( $guest_count >= $guest_limit ) {
                    $msg = get_option( 'sie_guest_limit_msg', 'Sign in to continue the conversation.' );
                    return new WP_Error( 'sie_guest_limit', $msg, [ 'status' => 403 ] );
                }
                set_transient( $guest_key, $guest_count + 1, DAY_IN_SECONDS );
            }
        }

        return true;
    }

    /**
     * Get client IP, respecting Cloudflare and proxy headers.
     */
    private static function get_client_ip() {
        foreach ( [ 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' ] as $header ) {
            if ( ! empty( $_SERVER[ $header ] ) ) {
                // X-Forwarded-For may contain multiple IPs — take the first
                $ip = strtok( $_SERVER[ $header ], ',' );
                $ip = trim( $ip );
                if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
                    return $ip;
                }
            }
        }
        return '0.0.0.0';
    }

    public function handle_chat( WP_REST_Request $request ) {
        $query        = $request->get_param( 'query' );
        $agent_key    = $request->get_param( 'agent' );
        $openai_key   = get_option( 'sie_openai_api_key',   '' );
        $pinecone_key = get_option( 'sie_pinecone_api_key', '' );
        $pinecone_host = get_option( 'sie_pinecone_host',   '' );

        if ( ! $openai_key || ! $pinecone_key || ! $pinecone_host ) {
            return new WP_Error( 'sie_not_configured', 'SIE is not fully configured.', [ 'status' => 503 ] );
        }

        // Resolve agent config (if selected)
        $agent = null;
        if ( $agent_key ) {
            $agent = SIE_Agents::get_agent( $agent_key );
        }

        // Provider + model — agent can override
        $provider = get_option( 'sie_llm_provider', 'openai' );

        // Agent model override: "openai:gpt-4o", "anthropic:claude-sonnet-4-5-20250514", "gemini:gemini-2.5-flash"
        if ( $agent && ! empty( $agent['model'] ) ) {
            $parts = explode( ':', $agent['model'], 2 );
            if ( count( $parts ) === 2 ) {
                $provider = $parts[0];
                $model    = $parts[1];
            }
        }

        if ( ! isset( $model ) ) {
            if ( $provider === 'openai' ) {
                $model = get_option( 'sie_openai_model', 'gpt-4o-mini' );
            } elseif ( $provider === 'gemini' ) {
                $model = get_option( 'sie_gemini_model', 'gemini-2.5-flash' );
            } else {
                $model = get_option( 'sie_anthropic_model', 'claude-sonnet-4-5-20250514' );
            }
        }

        if ( $provider === 'openai' ) {
            $llm_key = $openai_key;
        } elseif ( $provider === 'gemini' ) {
            $llm_key = get_option( 'sie_gemini_api_key', '' );
            if ( ! $llm_key ) {
                return new WP_Error( 'sie_not_configured', 'Gemini API key is not configured.', [ 'status' => 503 ] );
            }
        } else {
            $llm_key = get_option( 'sie_anthropic_api_key', '' );
            if ( ! $llm_key ) {
                return new WP_Error( 'sie_not_configured', 'Anthropic API key is not configured.', [ 'status' => 503 ] );
            }
        }

        // 1. Embed (always OpenAI)
        $embedding = $this->get_embedding( $query, $openai_key );
        if ( is_wp_error( $embedding ) ) return $embedding;

        // 2. Pinecone retrieval
        $retrieval = $this->query_pinecone( $embedding, $pinecone_host, $pinecone_key );
        if ( is_wp_error( $retrieval ) ) return $retrieval;

        $context   = $retrieval['context'];
        $sources   = $retrieval['sources'];
        $top_score = $retrieval['top_score'];

        // 3. Confidence check
        $threshold  = floatval( get_option( 'sie_confidence_threshold', '0.6' ) );
        $confidence = 'high';

        if ( $top_score === null ) {
            $confidence = 'none';
        } elseif ( $top_score < $threshold ) {
            $confidence = 'low';
        }

        // Low/no confidence — return fallback without hitting LLM
        if ( $confidence !== 'high' ) {
            $fallback = get_option(
                'sie_low_confidence_msg',
                'I don\'t have enough information in the knowledge base to answer that confidently. Please try rephrasing or contact us directly.'
            );

            SIE_Chat_Log::log( [
                'query'      => $query,
                'response'   => $fallback,
                'provider'   => $provider,
                'model'      => 'n/a (guardrail)',
                'sources'    => wp_json_encode( $sources ),
                'top_score'  => $top_score,
                'confidence' => $confidence,
            ] );

            return rest_ensure_response( [
                'response'   => $fallback,
                'sources'    => $sources,
                'confidence' => $confidence,
                'log_id'     => $GLOBALS['wpdb']->insert_id ?? null,
            ] );
        }

        // 4. Ask LLM — agent can override temperature and system prompt
        $temperature = floatval( get_option( 'sie_temperature', '0.2' ) );
        if ( $agent && $agent['temperature'] !== '' ) {
            $temperature = floatval( $agent['temperature'] );
        }

        $system_prompt = null;
        if ( $agent && ! empty( $agent['prompt'] ) ) {
            $system_prompt = $agent['prompt'];
        }

        // Append integrity principles to every system prompt
        $integrity_fragment = SIE_Settings::get_integrity_prompt();
        if ( $integrity_fragment ) {
            if ( $system_prompt ) {
                $system_prompt .= $integrity_fragment;
            } else {
                // Will be appended to the default prompt inside ask_* methods
                $system_prompt = get_option(
                    'sie_system_prompt',
                    'You are a knowledgeable assistant. Answer based only on the provided context. ' .
                    'If the context does not contain the answer, say so clearly. ' .
                    'Cite source URLs when referencing specific information.'
                ) . $integrity_fragment;
            }
        }

        if ( $provider === 'openai' ) {
            $response = $this->ask_openai( $query, $context, $llm_key, $model, $temperature, $system_prompt );
        } elseif ( $provider === 'gemini' ) {
            $response = $this->ask_gemini( $query, $context, $llm_key, $model, $temperature, $system_prompt );
        } else {
            $response = $this->ask_anthropic( $query, $context, $llm_key, $model, $temperature, $system_prompt );
        }

        if ( is_wp_error( $response ) ) return $response;

        // 5. Log
        $log_id = SIE_Chat_Log::log( [
            'query'      => $query,
            'response'   => $response,
            'provider'   => $provider,
            'model'      => $model,
            'sources'    => wp_json_encode( $sources ),
            'top_score'  => $top_score,
            'confidence' => $confidence,
        ] );

        return rest_ensure_response( [
            'response'   => $response,
            'sources'    => $sources,
            'confidence' => $confidence,
            'log_id'     => $log_id,
        ] );
    }

    // -------------------------------------------------------------------------
    // Embedding (always OpenAI)
    // -------------------------------------------------------------------------

    private function get_embedding( $text, $api_key ) {
        $res = wp_remote_post( 'https://api.openai.com/v1/embeddings', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'      => 'text-embedding-3-small',
                'input'      => $text,
                'dimensions' => 512,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $vec  = $body['data'][0]['embedding'] ?? null;

        return $vec ?? new WP_Error( 'sie_embed_error', 'Embedding request failed.' );
    }

    // -------------------------------------------------------------------------
    // Pinecone retrieval — returns context string + structured sources
    // -------------------------------------------------------------------------

    private function query_pinecone( $vector, $host, $api_key ) {
        $res = wp_remote_post( rtrim( $host, '/' ) . '/query', [
            'headers' => [
                'Api-Key'      => $api_key,
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'vector'          => $vector,
                'topK'            => 5,
                'includeMetadata' => true,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body    = json_decode( wp_remote_retrieve_body( $res ), true );
        $matches = $body['matches'] ?? [];
        $context = '';
        $sources = [];
        $top_score = null;

        foreach ( $matches as $i => $match ) {
            $score = $match['score'] ?? 0;
            if ( $i === 0 ) $top_score = $score;

            $meta  = $match['metadata'] ?? [];
            $title = $meta['title'] ?? '';
            $text  = substr( $meta['text'] ?? '', 0, 800 );
            $url   = $meta['url'] ?? '';

            if ( $text ) {
                $context .= "## {$title}\n{$text}\n[Source: {$url}]\n\n";
                $sources[] = [
                    'title' => $title,
                    'url'   => $url,
                    'score' => round( $score, 3 ),
                ];
            }
        }

        return [
            'context'   => $context ?: 'No relevant context found in the knowledge base.',
            'sources'   => $sources,
            'top_score' => $top_score,
        ];
    }

    // -------------------------------------------------------------------------
    // OpenAI completion
    // -------------------------------------------------------------------------

    private function ask_openai( $query, $context, $api_key, $model, $temperature, $system_prompt = null ) {
        $system = $system_prompt ?? get_option(
            'sie_system_prompt',
            'You are a knowledgeable assistant. Answer based only on the provided context. ' .
            'If the context does not contain the answer, say so clearly. ' .
            'Cite source URLs when referencing specific information.'
        );

        $res = wp_remote_post( 'https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'       => $model,
                'messages'    => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => "Context:\n{$context}\n\nQuestion: {$query}" ],
                ],
                'max_tokens'  => 800,
                'temperature' => $temperature,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = $body['choices'][0]['message']['content'] ?? null;

        return $text ?? new WP_Error( 'sie_openai_error', 'OpenAI response failed.' );
    }

    // -------------------------------------------------------------------------
    // Anthropic completion
    // -------------------------------------------------------------------------

    private function ask_anthropic( $query, $context, $api_key, $model, $temperature, $system_prompt = null ) {
        $system = $system_prompt ?? get_option(
            'sie_system_prompt',
            'You are a knowledgeable assistant. Answer based only on the provided context. ' .
            'If the context does not contain the answer, say so clearly. ' .
            'Cite source URLs when referencing specific information.'
        );

        $res = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'headers' => [
                'x-api-key'         => $api_key,
                'anthropic-version'  => '2023-06-01',
                'Content-Type'       => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'model'      => $model,
                'system'     => $system,
                'messages'   => [
                    [ 'role' => 'user', 'content' => "Context:\n{$context}\n\nQuestion: {$query}" ],
                ],
                'max_tokens'  => 800,
                'temperature' => $temperature,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = $body['content'][0]['text'] ?? null;

        return $text ?? new WP_Error( 'sie_anthropic_error', 'Anthropic response failed.' );
    }

    // -------------------------------------------------------------------------
    // Gemini completion
    // -------------------------------------------------------------------------

    private function ask_gemini( $query, $context, $api_key, $model, $temperature, $system_prompt = null ) {
        $system = $system_prompt ?? get_option(
            'sie_system_prompt',
            'You are a knowledgeable assistant. Answer based only on the provided context. ' .
            'If the context does not contain the answer, say so clearly. ' .
            'Cite source URLs when referencing specific information.'
        );

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . $model . ':generateContent?key=' . $api_key;

        $res = wp_remote_post( $url, [
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'systemInstruction' => [
                    'parts' => [ [ 'text' => $system ] ],
                ],
                'contents' => [
                    [
                        'role'  => 'user',
                        'parts' => [ [ 'text' => "Context:\n{$context}\n\nQuestion: {$query}" ] ],
                    ],
                ],
                'generationConfig' => [
                    'temperature'     => $temperature,
                    'maxOutputTokens' => 800,
                ],
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        return $text ?? new WP_Error( 'sie_gemini_error', 'Gemini response failed.' );
    }

    // -------------------------------------------------------------------------
    // Widget
    // -------------------------------------------------------------------------

    private function user_can_see_widget() {
        $access = get_option( 'sie_chat_access', 'logged_in' );
        if ( $access === 'public'    ) return true;
        if ( $access === 'logged_in' ) return is_user_logged_in();
        return current_user_can( get_option( 'sie_chat_role', 'subscriber' ) );
    }

    public function enqueue_assets() {
        if ( ! $this->user_can_see_widget() ) return;

        // Shared localized data for both widget and page chat
        $localized = [
            'apiUrl'        => rest_url( 'sie/v1/chat' ),
            'feedbackUrl'   => rest_url( 'sie/v1/chat-feedback' ),
            'nonce'         => wp_create_nonce( 'wp_rest' ),
            'title'         => get_option( 'sie_chat_title', 'Ask the Knowledge Base' ),
            'pageTitle'     => get_option( 'sie_page_chat_title', 'Chat with an AI Expert' ),
            'pageSubtitle'  => get_option( 'sie_page_chat_subtitle', 'Ask anything — powered by our knowledge base.' ),
            'loginUrl'      => wp_login_url( get_permalink() ),
            'disclaimer'    => get_option( 'sie_chat_disclaimer', '' ),
            'agents'        => array_values( array_map( function ( $key, $agent ) {
                return [
                    'key'         => $key,
                    'name'        => $agent['name'],
                    'icon'        => $agent['icon'],
                    'description' => $agent['description'],
                ];
            }, array_keys( SIE_Agents::get_active_agents() ), SIE_Agents::get_active_agents() ) ),
        ];

        // Always register both — only enqueue widget if page chat isn't active
        wp_register_style(  'sie-chat',      SIE_PLUGIN_URL . 'assets/chat-widget.css', [], SIE_VERSION );
        wp_register_script( 'sie-chat',      SIE_PLUGIN_URL . 'assets/chat-widget.js',  [], SIE_VERSION, true );
        wp_register_style(  'sie-chat-page', SIE_PLUGIN_URL . 'assets/chat-page.css',   [], SIE_VERSION );
        wp_register_script( 'sie-chat-page', SIE_PLUGIN_URL . 'assets/chat-page.js',    [], SIE_VERSION, true );

        // Inject color CSS custom properties
        $colors = sprintf(
            ':root{--sie-primary:%s;--sie-user-bubble:%s;--sie-assistant-bg:%s;--sie-header-bg:%s;}',
            sanitize_hex_color( get_option( 'sie_color_primary',      '#2563eb' ) ),
            sanitize_hex_color( get_option( 'sie_color_user_bubble',  '#2563eb' ) ),
            sanitize_hex_color( get_option( 'sie_color_assistant_bg', '#f1f5f9' ) ),
            sanitize_hex_color( get_option( 'sie_color_header_bg',    '#2563eb' ) )
        );
        wp_add_inline_style( 'sie-chat', $colors );
        wp_add_inline_style( 'sie-chat-page', $colors );

        // Localize both scripts with the same config
        wp_localize_script( 'sie-chat',      'sieChat', $localized );
        wp_localize_script( 'sie-chat-page', 'sieChat', $localized );

        // Widget assets enqueue happens in wp_footer to check if page chat was rendered
        add_action( 'wp_footer', [ $this, 'maybe_enqueue_widget' ], 1 );
    }

    /**
     * Auto-inject the floating widget on every page (via wp_footer),
     * unless [sie_chat_page] is active on this page.
     */
    public function maybe_enqueue_widget() {
        if ( $this->page_chat_active ) return;

        wp_enqueue_style( 'sie-chat' );
        wp_enqueue_script( 'sie-chat' );

        // Inject widget markup into the footer so it appears site-wide
        echo '<div id="sie-chat-root"></div>';
    }

    /** Shortcode [sie_chat] — kept for backwards compat but widget auto-injects now */
    public function render_widget( $atts ) {
        // Widget is auto-injected via wp_footer, shortcode is a no-op
        return '';
    }

    /** Shortcode [sie_chat_page] — full-page search-style chat */
    public function render_page_chat( $atts ) {
        if ( ! $this->user_can_see_widget() ) return '';

        $this->page_chat_active = true;

        wp_enqueue_style( 'sie-chat-page' );
        wp_enqueue_script( 'sie-chat-page' );

        return '<div id="sie-chat-page-root"></div>';
    }
}
