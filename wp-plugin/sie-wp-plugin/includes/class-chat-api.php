<?php
/**
 * AI Chat API + Widget
 *
 * REST endpoint: POST /wp-json/sie/v1/chat  { "query": "..." }
 * Shortcode:     [sie_chat]
 *
 * Flow: embed query → query Pinecone → ask OpenAI with context → return response.
 * Access: configurable via SIE Settings (public / logged-in / specific role).
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Chat_API {

    public function init() {
        add_action( 'rest_api_init',       [ $this, 'register_routes' ] );
        add_action( 'wp_enqueue_scripts',  [ $this, 'enqueue_assets'  ] );
        add_shortcode( 'sie_chat',         [ $this, 'render_widget'   ] );
    }

    // -------------------------------------------------------------------------
    // REST endpoint
    // -------------------------------------------------------------------------

    public function register_routes() {
        register_rest_route( 'sie/v1', '/chat', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_chat' ],
            'permission_callback' => [ $this, 'access_check' ],
            'args'                => [
                'query' => [
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ],
            ],
        ] );
    }

    public function access_check() {
        $access = get_option( 'sie_chat_access', 'logged_in' );

        if ( $access === 'public'    ) return true;
        if ( $access === 'logged_in' ) return is_user_logged_in();

        // Role-based
        $role = get_option( 'sie_chat_role', 'subscriber' );
        return current_user_can( $role );
    }

    public function handle_chat( WP_REST_Request $request ) {
        $query        = $request->get_param( 'query' );
        $openai_key   = get_option( 'sie_openai_api_key',   '' );
        $pinecone_key = get_option( 'sie_pinecone_api_key', '' );
        $pinecone_host = get_option( 'sie_pinecone_host',   '' );

        if ( ! $openai_key || ! $pinecone_key || ! $pinecone_host ) {
            return new WP_Error( 'sie_not_configured', 'SIE is not fully configured.', [ 'status' => 503 ] );
        }

        $embedding = $this->get_embedding( $query, $openai_key );
        if ( is_wp_error( $embedding ) ) return $embedding;

        $context = $this->query_pinecone( $embedding, $pinecone_host, $pinecone_key );
        if ( is_wp_error( $context ) ) return $context;

        $response = $this->ask_openai( $query, $context, $openai_key );
        if ( is_wp_error( $response ) ) return $response;

        return rest_ensure_response( [ 'response' => $response ] );
    }

    // -------------------------------------------------------------------------
    // OpenAI + Pinecone helpers
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

        foreach ( $matches as $match ) {
            if ( ( $match['score'] ?? 0 ) < 0.6 ) continue;
            $meta    = $match['metadata'] ?? [];
            $title   = $meta['title'] ?? '';
            $text    = substr( $meta['text'] ?? '', 0, 800 );
            $url     = $meta['url'] ?? '';
            if ( $text ) {
                $context .= "## {$title}\n{$text}\n[Source: {$url}]\n\n";
            }
        }

        return $context ?: 'No relevant context found in the knowledge base.';
    }

    private function ask_openai( $query, $context, $api_key ) {
        $system = get_option(
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
                'model'      => 'gpt-4o-mini',
                'messages'   => [
                    [ 'role' => 'system', 'content' => $system ],
                    [ 'role' => 'user',   'content' => "Context:\n{$context}\n\nQuestion: {$query}" ],
                ],
                'max_tokens' => 800,
            ] ),
            'timeout' => 30,
        ] );

        if ( is_wp_error( $res ) ) return $res;

        $body = json_decode( wp_remote_retrieve_body( $res ), true );
        $text = $body['choices'][0]['message']['content'] ?? null;

        return $text ?? new WP_Error( 'sie_openai_error', 'OpenAI response failed.' );
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

        wp_enqueue_style(
            'sie-chat',
            SIE_PLUGIN_URL . 'assets/chat-widget.css',
            [],
            SIE_VERSION
        );
        wp_enqueue_script(
            'sie-chat',
            SIE_PLUGIN_URL . 'assets/chat-widget.js',
            [],
            SIE_VERSION,
            true
        );
        wp_localize_script( 'sie-chat', 'sieChat', [
            'apiUrl' => rest_url( 'sie/v1/chat' ),
            'nonce'  => wp_create_nonce( 'wp_rest' ),
            'title'  => get_option( 'sie_chat_title', 'Ask the Knowledge Base' ),
        ] );
    }

    /** Shortcode [sie_chat] */
    public function render_widget( $atts ) {
        if ( ! $this->user_can_see_widget() ) return '';
        return '<div id="sie-chat-root"></div>';
    }
}
