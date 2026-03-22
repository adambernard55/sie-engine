<?php
/**
 * SIE Agents — preset AI personas for the chat interface
 *
 * Each agent has its own system prompt, model, and temperature.
 * Users select an agent in the chat to change the AI's behavior.
 * Custom agents can be added via the admin panel.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Agents {

    const OPTION_KEY = 'sie_agents';

    /** Default agents shipped with SIE. */
    private static function defaults(): array {
        return [
            'assistant' => [
                'name'        => 'Assistant',
                'icon'        => 'dashicons-format-chat',
                'description' => 'General knowledge base Q&A — answers questions clearly and cites sources.',
                'prompt'      => 'You are a knowledgeable assistant. Answer based only on the provided context. If the context does not contain the answer, say so clearly. Cite source URLs when referencing specific information.',
                'model'       => '',
                'temperature' => '',
                'active'      => true,
                'builtin'     => true,
            ],
            'researcher' => [
                'name'        => 'Researcher',
                'icon'        => 'dashicons-search',
                'description' => 'Deep analysis — synthesizes multiple sources, identifies patterns, provides comprehensive answers.',
                'prompt'      => "You are a thorough researcher. When answering:\n- Synthesize information from ALL provided sources, not just the top result\n- Identify patterns, contradictions, or gaps across sources\n- Provide comprehensive answers with multiple perspectives\n- Always cite specific sources with URLs\n- If information is incomplete, clearly state what's missing and suggest what to look for\n- Structure long answers with headers and bullet points for readability",
                'model'       => '',
                'temperature' => '0.3',
                'active'      => true,
                'builtin'     => true,
            ],
            'editor' => [
                'name'        => 'Editor',
                'icon'        => 'dashicons-edit',
                'description' => 'Content review — improves writing, checks accuracy against the knowledge base, suggests rewrites.',
                'prompt'      => "You are an expert content editor. When the user shares text or asks about content:\n- Review for clarity, accuracy, and engagement\n- Check claims against the provided knowledge base context\n- Suggest specific rewrites with before/after examples\n- Flag any statements that contradict the knowledge base\n- Improve structure, flow, and readability\n- Keep the author's voice while tightening the prose\n- If asked to write, produce polished, publication-ready content",
                'model'       => '',
                'temperature' => '0.2',
                'active'      => true,
                'builtin'     => true,
            ],
            'strategist' => [
                'name'        => 'Strategist',
                'icon'        => 'dashicons-chart-area',
                'description' => 'Strategic advisor — provides actionable recommendations, plans, and business insights.',
                'prompt'      => "You are a strategic advisor. When answering:\n- Focus on actionable recommendations, not just information\n- Frame answers in terms of impact, priority, and next steps\n- Consider business implications and ROI\n- Provide structured action plans when appropriate\n- Identify quick wins vs. long-term investments\n- Back recommendations with evidence from the knowledge base\n- Be direct and decisive — state what you'd recommend and why",
                'model'       => '',
                'temperature' => '0.3',
                'active'      => true,
                'builtin'     => true,
            ],
            'analyst' => [
                'name'        => 'Analyst',
                'icon'        => 'dashicons-chart-bar',
                'description' => 'Data-driven analysis — comparisons, pros/cons, metrics, and structured evaluations.',
                'prompt'      => "You are a data-driven analyst. When answering:\n- Use structured formats: tables, comparisons, pros/cons lists\n- Quantify when possible — metrics, percentages, benchmarks\n- Compare options objectively with clear criteria\n- Identify trade-offs and dependencies\n- Present findings in a logical, evidence-based structure\n- Separate facts (from the knowledge base) from inferences\n- Conclude with a clear recommendation based on the analysis",
                'model'       => '',
                'temperature' => '0.1',
                'active'      => true,
                'builtin'     => true,
            ],
        ];
    }

    public function init() {
        add_action( 'rest_api_init', [ $this, 'register_routes' ] );
    }

    /**
     * Get all agents (defaults merged with saved customizations).
     */
    public static function get_agents(): array {
        $saved    = get_option( self::OPTION_KEY, [] );
        $defaults = self::defaults();

        // Merge: saved values override defaults, custom agents added
        $agents = $defaults;
        if ( is_array( $saved ) ) {
            foreach ( $saved as $key => $agent ) {
                if ( isset( $agents[ $key ] ) ) {
                    $agents[ $key ] = array_merge( $agents[ $key ], $agent );
                } else {
                    $agents[ $key ] = $agent;
                }
            }
        }

        return $agents;
    }

    /**
     * Get a specific agent's config, falling back to defaults.
     */
    public static function get_agent( string $key ): ?array {
        $agents = self::get_agents();
        return $agents[ $key ] ?? null;
    }

    /**
     * Get active agents only.
     */
    public static function get_active_agents(): array {
        return array_filter( self::get_agents(), fn( $a ) => ! empty( $a['active'] ) );
    }

    /**
     * Save agents to options (only saves diff from defaults for builtins).
     */
    public static function save_agents( array $agents ) {
        update_option( self::OPTION_KEY, $agents );
    }

    // -------------------------------------------------------------------------
    // REST endpoint — GET /sie/v1/agents (public list for chat UI)
    // -------------------------------------------------------------------------

    public function register_routes() {
        register_rest_route( 'sie/v1', '/agents', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'list_agents' ],
            'permission_callback' => '__return_true',
            'show_in_index'       => false,
        ] );
    }

    public function list_agents() {
        $agents = self::get_active_agents();
        $list   = [];

        foreach ( $agents as $key => $agent ) {
            $list[] = [
                'key'         => $key,
                'name'        => $agent['name'],
                'icon'        => $agent['icon'],
                'description' => $agent['description'],
            ];
        }

        return rest_ensure_response( $list );
    }
}
