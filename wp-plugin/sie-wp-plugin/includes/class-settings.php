<?php
/**
 * SIE Admin Settings — tabbed admin panel
 *
 * Top-level menu "SIE" with sub-pages:
 *   - General      — API keys, access, appearance, system prompt
 *   - Models       — provider, model selection, temperature
 *   - Guardrails   — confidence, logging, daily limits
 *   - Documents    — connected post types, topic mapping
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Settings {

    private const OPTIONS = [
        // API keys
        'sie_openai_api_key'       => '',
        'sie_anthropic_api_key'    => '',
        'sie_gemini_api_key'       => '',
        'sie_pinecone_api_key'     => '',
        'sie_pinecone_host'        => '',
        'sie_pinecone_index'       => '',
        // Model
        'sie_llm_provider'         => 'openai',
        'sie_openai_model'         => 'gpt-4o-mini',
        'sie_anthropic_model'      => 'claude-sonnet-4-5-20250514',
        'sie_gemini_model'         => 'gemini-2.5-flash',
        'sie_temperature'          => '0.2',
        // Chat widget & appearance
        'sie_chat_access'          => 'logged_in',
        'sie_chat_role'            => 'subscriber',
        'sie_chat_title'           => 'Ask the Knowledge Base',
        'sie_page_chat_title'      => 'Chat with an AI Expert',
        'sie_page_chat_subtitle'   => 'Ask anything — powered by our knowledge base.',
        'sie_system_prompt'        => 'You are a knowledgeable assistant. Answer based only on the provided context. If the context does not contain the answer, say so clearly. Cite source URLs when referencing specific information.',
        // Disclaimer
        'sie_chat_disclaimer'      => '',
        // Colors
        'sie_color_primary'        => '#2563eb',
        'sie_color_user_bubble'    => '#2563eb',
        'sie_color_assistant_bg'   => '#f1f5f9',
        'sie_color_header_bg'     => '#2563eb',
        // Guardrails
        'sie_confidence_threshold' => '0.6',
        'sie_low_confidence_msg'   => 'I don\'t have enough information in the knowledge base to answer that confidently. Please try rephrasing or contact us directly.',
        'sie_enable_logging'       => '1',
        'sie_daily_query_limit'    => '0',
        'sie_guest_query_limit'    => '3',
        'sie_guest_limit_msg'      => 'Sign in to continue the conversation and get full access to our knowledge base.',
        // SEO plugin
        'sie_seo_plugin'           => 'auto',
        // Triad labels (editable per site)
        'sie_label_faq_singular'     => '',
        'sie_label_faq_plural'       => '',
        'sie_label_faq_slug'         => '',
        'sie_label_insight_singular' => '',
        'sie_label_insight_plural'   => '',
        'sie_label_insight_slug'     => '',
        'sie_label_guide_singular'   => '',
        'sie_label_guide_plural'     => '',
        'sie_label_guide_slug'       => '',
        // Knowledge Base slug
        'sie_kb_slug'                => '',
        // Related content
        'sie_auto_related'           => '0',
        // GitHub sync
        'sie_github_repo'          => '',
        'sie_github_token'         => '',
        'sie_sync_workflow'        => 'kb-sync.yml',
    ];

    const TABS = [
        'home'      => 'Home',
        'settings'  => 'Settings',
        'integrity' => 'Integrity',
        'models'    => 'Models',
        'agents'    => 'Agents',
        'personas'  => 'Chat Personas',
        'content'   => 'Content',
        'guardrails'=> 'Guardrails',
        'documents' => 'Documents',
    ];

    public function init() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'rest_api_init', [ $this, 'register_agent_routes' ] );
        add_action( 'wp_ajax_sie_dispatch_sync', [ $this, 'ajax_dispatch_sync' ] );

        // Flush rewrite rules after SIE settings are saved (slug changes)
        add_action( 'update_option_sie_kb_slug',           function () { flush_rewrite_rules(); } );
        add_action( 'update_option_sie_label_faq_slug',    function () { flush_rewrite_rules(); } );
        add_action( 'update_option_sie_label_insight_slug', function () { flush_rewrite_rules(); } );
        add_action( 'update_option_sie_label_guide_slug',  function () { flush_rewrite_rules(); } );
    }

    public function add_menu() {
        add_menu_page(
            'SIE Settings',
            'SIE',
            'manage_options',
            'sie-settings',
            [ $this, 'render_page' ],
            'dashicons-analytics',
            30
        );
    }

    /**
     * Map each option to the tab (or tab+section) it appears on.
     * The Settings tab has sub-sections; each gets its own key:
     *   settings:api-keys, settings:appearance, settings:prompts, etc.
     * This prevents saving one sub-section from blanking another.
     */
    private const TAB_OPTIONS = [
        // Settings sub-sections
        'settings:api-keys' => [
            'sie_openai_api_key', 'sie_anthropic_api_key', 'sie_gemini_api_key',
            'sie_pinecone_api_key', 'sie_pinecone_host', 'sie_pinecone_index',
        ],
        'settings:appearance' => [
            'sie_chat_title', 'sie_page_chat_title', 'sie_page_chat_subtitle',
            'sie_color_primary', 'sie_color_user_bubble', 'sie_color_assistant_bg',
            'sie_color_header_bg',
        ],
        'settings:prompts' => [
            'sie_system_prompt', 'sie_chat_disclaimer',
        ],
        'settings:access' => [
            'sie_chat_access', 'sie_chat_role',
        ],
        'settings:seo' => [
            'sie_seo_plugin',
        ],
        'models' => [
            'sie_llm_provider', 'sie_openai_model', 'sie_anthropic_model',
            'sie_gemini_model', 'sie_temperature',
        ],
        'content' => [
            'sie_kb_slug', 'sie_label_faq_singular', 'sie_label_faq_plural', 'sie_label_faq_slug',
            'sie_label_insight_singular', 'sie_label_insight_plural', 'sie_label_insight_slug',
            'sie_label_guide_singular', 'sie_label_guide_plural', 'sie_label_guide_slug',
            'sie_auto_related',
        ],
        'guardrails' => [
            'sie_confidence_threshold', 'sie_low_confidence_msg', 'sie_enable_logging',
            'sie_daily_query_limit', 'sie_guest_query_limit', 'sie_guest_limit_msg',
        ],
        'documents' => [
            'sie_github_repo', 'sie_github_token', 'sie_sync_workflow',
        ],
    ];

    public function register_settings() {
        // Determine which tab is being saved (from the referer URL)
        // Determine which tab (and section) is being saved so we only register
        // those options. This prevents saving one tab/section from blanking another.
        $active_tab = '';
        $active_section = '';
        $referer = wp_get_referer();
        if ( $referer ) {
            if ( preg_match( '/[?&]tab=([a-z_]+)/', $referer, $m ) ) {
                $active_tab = $m[1];
            }
            if ( preg_match( '/[?&]section=([a-z_-]+)/', $referer, $m ) ) {
                $active_section = $m[1];
            }
        }

        // Build the TAB_OPTIONS lookup key.
        // Settings sub-sections use "settings:api-keys" format.
        $lookup_key = $active_tab;
        if ( $active_tab === 'settings' && $active_section ) {
            $lookup_key = 'settings:' . $active_section;
        } elseif ( $active_tab === 'settings' ) {
            $lookup_key = 'settings:api-keys'; // default sub-section
        }

        // Fall back to the first key that has registered options
        if ( ! $lookup_key || ! isset( self::TAB_OPTIONS[ $lookup_key ] ) ) {
            $lookup_key = array_key_first( self::TAB_OPTIONS ) ?: 'settings:api-keys';
        }

        // Only register options that belong to the active tab/section
        $tab_keys = self::TAB_OPTIONS[ $lookup_key ] ?? [];

        foreach ( self::OPTIONS as $key => $default ) {
            if ( in_array( $key, $tab_keys, true ) ) {
                register_setting( 'sie_settings_group', $key, [ 'default' => $default ] );
            }
        }

        // Connected CPTs only on documents tab
        if ( $active_tab === 'documents' ) {
            register_setting( 'sie_settings_group', 'sie_connected_cpts', [
                'default'           => [],
                'sanitize_callback' => function ( $value ) {
                    return is_array( $value ) ? array_map( 'sanitize_key', $value ) : [];
                },
            ] );
        }
    }

    // =========================================================================
    // REST API — Agent Job Queue (for CrewAI workers to poll and report)
    // =========================================================================

    public function register_agent_routes() {
        // Get next queued job
        register_rest_route( 'sie/v1', '/agent-jobs/next', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'api_next_job' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'show_in_index'       => false,
        ] );

        // Update job status/result
        register_rest_route( 'sie/v1', '/agent-jobs/(?P<id>[a-zA-Z0-9_]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'api_update_job' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'show_in_index'       => false,
            'args'                => [
                'status' => [
                    'required' => true,
                    'type'     => 'string',
                    'enum'     => [ 'running', 'completed', 'failed' ],
                ],
                'result' => [
                    'required' => false,
                    'type'     => 'string',
                ],
            ],
        ] );

        // List recent jobs
        register_rest_route( 'sie/v1', '/agent-jobs', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ $this, 'api_list_jobs' ],
            'permission_callback' => function () {
                return current_user_can( 'manage_options' );
            },
            'show_in_index'       => false,
        ] );
    }

    /** Return the next queued job and mark it as running. */
    public function api_next_job() {
        $jobs = get_option( 'sie_agent_jobs', [] );

        foreach ( $jobs as &$job ) {
            if ( $job['status'] === 'queued' ) {
                $job['status']     = 'running';
                $job['started_at'] = current_time( 'mysql' );
                update_option( 'sie_agent_jobs', $jobs );
                return rest_ensure_response( $job );
            }
        }

        return rest_ensure_response( null );
    }

    /** Update a job's status and result. */
    public function api_update_job( WP_REST_Request $request ) {
        $job_id = $request->get_param( 'id' );
        $status = $request->get_param( 'status' );
        $result = $request->get_param( 'result' );

        $jobs = get_option( 'sie_agent_jobs', [] );

        foreach ( $jobs as &$job ) {
            if ( $job['id'] === $job_id ) {
                $job['status'] = $status;
                if ( $result !== null ) {
                    $job['result'] = $result;
                }
                if ( in_array( $status, [ 'completed', 'failed' ], true ) ) {
                    $job['completed_at'] = current_time( 'mysql' );
                }
                update_option( 'sie_agent_jobs', $jobs );
                return rest_ensure_response( $job );
            }
        }

        return new WP_Error( 'not_found', 'Job not found.', [ 'status' => 404 ] );
    }

    /** List recent jobs. */
    public function api_list_jobs() {
        return rest_ensure_response( get_option( 'sie_agent_jobs', [] ) );
    }

    // =========================================================================
    // Main render — tab router
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'home';
        if ( ! array_key_exists( $active, self::TABS ) ) $active = 'home';
        ?>
        <div class="wrap">
            <h1>SIE — Strategic Intelligence Engine</h1>

            <nav class="nav-tab-wrapper">
                <?php foreach ( self::TABS as $slug => $label ) : ?>
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=' . $slug ) ); ?>"
                       class="nav-tab <?php echo $active === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html( $label ); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php
            // Tabs with their own save handlers (use self-posting forms)
            $self_managed = [ 'home', 'integrity', 'personas', 'agents' ];

            if ( in_array( $active, $self_managed, true ) ) {
                switch ( $active ) {
                    case 'home':      $this->tab_home();      break;
                    case 'integrity': $this->tab_integrity(); break;
                    case 'agents':    $this->tab_agents();    break;
                    case 'personas':  $this->tab_personas();  break;
                }
            } else {
                // Standard Settings API tabs — only register/save their own options
            ?>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'sie_settings_group' );

                switch ( $active ) {
                    case 'settings':  $this->tab_settings();  break;
                    case 'models':    $this->tab_models();    break;
                    case 'content':   $this->tab_content();   break;
                    case 'guardrails':$this->tab_guardrails();break;
                    case 'documents': $this->tab_documents(); break;
                }

                submit_button();
                ?>
            </form>
            <?php } ?>
        </div>
        <?php
    }

    // =========================================================================
    // Tab: Home (Dashboard)
    // =========================================================================

    private function tab_home() {
        $provider   = get_option( 'sie_llm_provider', 'openai' );
        $integrity  = self::get_integrity();
        $agents     = SIE_Agents::get_active_agents();
        $logging    = get_option( 'sie_enable_logging', '1' );
        $triad      = SIE_CPT::triad_labels();
        $jobs       = get_option( 'sie_agent_jobs', [] );
        $pending    = count( array_filter( $jobs, fn( $j ) => $j['status'] === 'queued' ) );

        // Quick status checks
        $has_openai   = ! empty( sie_get_option( 'sie_openai_api_key' ) );
        $has_pinecone = ! empty( sie_get_option( 'sie_pinecone_api_key' ) ) && ! empty( sie_get_option( 'sie_pinecone_host' ) );
        $providers    = [ 'openai' => 'OpenAI', 'anthropic' => 'Anthropic', 'gemini' => 'Gemini' ];
        ?>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:16px;margin-top:12px;">

            <!-- Status -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#1e293b;">System Status</h3>
                <table style="width:100%;font-size:13px;border-collapse:collapse;">
                    <tr>
                        <td style="padding:4px 0;">OpenAI (Embeddings)</td>
                        <td style="text-align:right;"><?php echo $has_openai ? '<span style="color:#16a34a;">Connected</span>' : '<span style="color:#dc2626;">Not configured</span>'; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Pinecone (KB)</td>
                        <td style="text-align:right;"><?php echo $has_pinecone ? '<span style="color:#16a34a;">Connected</span>' : '<span style="color:#dc2626;">Not configured</span>'; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">LLM Provider</td>
                        <td style="text-align:right;font-weight:600;"><?php echo esc_html( $providers[ $provider ] ?? $provider ); ?></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Integrity Layer</td>
                        <td style="text-align:right;"><?php echo $integrity['enabled'] ? '<span style="color:#16a34a;">Active</span>' : '<span style="color:#94a3b8;">Disabled</span>'; ?></td>
                    </tr>
                    <tr>
                        <td style="padding:4px 0;">Chat Logging</td>
                        <td style="text-align:right;"><?php echo $logging === '1' ? '<span style="color:#16a34a;">On</span>' : '<span style="color:#94a3b8;">Off</span>'; ?></td>
                    </tr>
                </table>
            </div>

            <!-- Quick Links -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#1e293b;">Quick Links</h3>
                <ul style="margin:0;padding:0;list-style:none;font-size:13px;">
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=settings' ) ); ?>">API Keys & Appearance</a></li>
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=agents' ) ); ?>">Worker Agents</a><?php if ( $pending ) : ?> <span style="background:#f59e0b;color:#fff;padding:1px 6px;border-radius:10px;font-size:11px;"><?php echo $pending; ?> queued</span><?php endif; ?></li>
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'tools.php?page=sie-chat-log' ) ); ?>">Chat Log</a></li>
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=integrity' ) ); ?>">Integrity Principles</a></li>
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=content' ) ); ?>">Content & Permalinks</a></li>
                    <li style="padding:4px 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=documents' ) ); ?>">Documents & Sync</a></li>
                </ul>
            </div>

            <!-- Active Chat Personas -->
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 12px;font-size:14px;color:#1e293b;">Active Chat Personas</h3>
                <?php foreach ( $agents as $key => $agent ) : ?>
                    <div style="display:flex;align-items:center;gap:8px;padding:3px 0;font-size:13px;">
                        <span class="dashicons <?php echo esc_attr( $agent['icon'] ); ?>" style="font-size:16px;width:16px;height:16px;color:#2563eb;"></span>
                        <span><?php echo esc_html( $agent['name'] ); ?></span>
                    </div>
                <?php endforeach; ?>
                <p style="margin:8px 0 0;"><a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=personas' ) ); ?>" style="font-size:12px;">Manage personas &rarr;</a></p>
            </div>

        </div>

        <!-- Shortcodes Reference -->
        <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;margin-top:16px;">
            <h3 style="margin:0 0 12px;font-size:14px;color:#1e293b;">Shortcodes</h3>
            <table style="font-size:13px;border-collapse:collapse;">
                <tr><td style="padding:3px 16px 3px 0;"><code>[sie_chat_page]</code></td><td>Full-page search-style chat interface</td></tr>
                <tr><td style="padding:3px 16px 3px 0;"><code>[sie_related]</code></td><td>All related <?php echo esc_html( $triad['sie_faq']['plural'] ); ?>, <?php echo esc_html( $triad['sie_insight']['plural'] ); ?> &amp; <?php echo esc_html( $triad['sie_guide']['plural'] ); ?></td></tr>
                <tr><td style="padding:3px 16px 3px 0;"><code>[sie_faqs]</code></td><td>Related <?php echo esc_html( $triad['sie_faq']['plural'] ); ?> only</td></tr>
                <tr><td style="padding:3px 16px 3px 0;"><code>[sie_insights]</code></td><td>Related <?php echo esc_html( $triad['sie_insight']['plural'] ); ?> only</td></tr>
                <tr><td style="padding:3px 16px 3px 0;"><code>[sie_guides]</code></td><td>Related <?php echo esc_html( $triad['sie_guide']['plural'] ); ?> only</td></tr>
            </table>
            <p style="margin:8px 0 0;font-size:12px;color:#64748b;">The floating chat widget appears site-wide automatically. It hides on pages with <code>[sie_chat_page]</code>.</p>
        </div>

        <div style="margin-top:16px;">
            <p style="font-size:12px;color:#94a3b8;">
                SIE v<?php echo esc_html( SIE_VERSION ); ?> &mdash;
                <strong>Topic API:</strong> <code style="font-size:11px;"><?php echo esc_url( rest_url( 'sie/v1/topics' ) ); ?></code>
            </p>
        </div>
        <?php
    }

    // =========================================================================
    // Tab: Settings (API Keys, Appearance, System Prompt, Access, SEO)
    // =========================================================================

    private function tab_settings() {
        $sub = isset( $_GET['section'] ) ? sanitize_key( $_GET['section'] ) : 'api-keys';
        $sections = [
            'api-keys'    => 'API Keys',
            'appearance'  => 'Appearance',
            'prompts'     => 'System Prompt',
            'access'      => 'Access Control',
            'seo'         => 'SEO',
        ];
        ?>
        <div style="display:flex;gap:8px;margin:12px 0 20px;flex-wrap:wrap;">
            <?php foreach ( $sections as $skey => $slabel ) : ?>
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=settings&section=' . $skey ) ); ?>"
                   style="padding:6px 14px;border-radius:4px;text-decoration:none;font-size:13px;
                          <?php echo $sub === $skey
                              ? 'background:#2563eb;color:#fff;font-weight:600;'
                              : 'background:#f1f5f9;color:#475569;'; ?>">
                    <?php echo esc_html( $slabel ); ?>
                </a>
            <?php endforeach; ?>
        </div>
        <?php

        switch ( $sub ) {
            case 'api-keys':    $this->section_api_keys();    break;
            case 'appearance':  $this->section_appearance();  break;
            case 'prompts':     $this->section_prompts();     break;
            case 'access':      $this->section_access();      break;
            case 'seo':         $this->section_seo();         break;
        }
    }

    /**
     * Render a credential field — locked (read-only) if a wp-config constant is set.
     */
    private function render_credential_field( string $option, string $label, string $type = 'password', string $description = '', string $placeholder = '' ) {
        $locked = sie_option_is_locked( $option );
        $value  = $locked ? '••••••••' : esc_attr( get_option( $option, '' ) );
        ?>
        <tr>
            <th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $label ); ?></label></th>
            <td>
                <?php if ( $locked ) : ?>
                    <input type="text" id="<?php echo esc_attr( $option ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="regular-text" disabled="disabled"
                           style="background:#f0f0f1;color:#50575e;" />
                    <span style="color:#16a34a;margin-left:8px;">&#x1f512; Set in wp-config.php</span>
                <?php else : ?>
                    <input type="<?php echo esc_attr( $type ); ?>"
                           name="<?php echo esc_attr( $option ); ?>"
                           id="<?php echo esc_attr( $option ); ?>"
                           value="<?php echo esc_attr( $value ); ?>"
                           class="regular-text" autocomplete="off"
                           <?php echo $placeholder ? 'placeholder="' . esc_attr( $placeholder ) . '"' : ''; ?> />
                <?php endif; ?>
                <?php if ( $description ) : ?>
                    <p class="description"><?php echo esc_html( $description ); ?></p>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }

    private function section_api_keys() {
        $any_locked = sie_option_is_locked( 'sie_openai_api_key' )
            || sie_option_is_locked( 'sie_pinecone_api_key' );
        ?>
        <h2>API Credentials</h2>
        <?php if ( ! $any_locked ) : ?>
            <p class="description" style="margin-bottom:12px;">
                <strong>Tip:</strong> For protection against accidental deletion, define API keys as constants in
                <code>wp-config.php</code> instead. Example: <code>define( 'SIE_OPENAI_API_KEY', 'sk-...' );</code>
            </p>
        <?php endif; ?>
        <table class="form-table" role="presentation">
            <?php
            $this->render_credential_field( 'sie_openai_api_key',    'OpenAI API Key',    'password', 'Required for embeddings. Also used for chat if OpenAI is selected.' );
            $this->render_credential_field( 'sie_anthropic_api_key', 'Anthropic API Key' );
            $this->render_credential_field( 'sie_gemini_api_key',    'Google Gemini API Key' );
            $this->render_credential_field( 'sie_pinecone_api_key',  'Pinecone API Key' );
            $this->render_credential_field( 'sie_pinecone_host',     'Pinecone Host', 'text', '', 'https://your-index.svc.pinecone.io' );
            $this->render_credential_field( 'sie_pinecone_index',    'Pinecone Index Name', 'text' );
            ?>
        </table>
        <?php
    }

    private function section_appearance() {
        ?>
        <h2>Chat Titles</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_chat_title">Widget title</label></th>
                <td><input type="text" name="sie_chat_title" id="sie_chat_title"
                           value="<?php echo esc_attr( get_option( 'sie_chat_title', 'Ask the Knowledge Base' ) ); ?>"
                           class="regular-text" />
                    <p class="description">Title shown in the floating chat bubble panel.</p></td>
            </tr>
            <tr>
                <th><label for="sie_page_chat_title">Page chat title</label></th>
                <td><input type="text" name="sie_page_chat_title" id="sie_page_chat_title"
                           value="<?php echo esc_attr( get_option( 'sie_page_chat_title', 'Chat with an AI Expert' ) ); ?>"
                           class="regular-text" />
                    <p class="description">Heading above the search bar on <code>[sie_chat_page]</code> pages.</p></td>
            </tr>
            <tr>
                <th><label for="sie_page_chat_subtitle">Page chat subtitle</label></th>
                <td><input type="text" name="sie_page_chat_subtitle" id="sie_page_chat_subtitle"
                           value="<?php echo esc_attr( get_option( 'sie_page_chat_subtitle', 'Ask anything — powered by our knowledge base.' ) ); ?>"
                           class="regular-text" /></td>
            </tr>
        </table>

        <h2>Colors</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_color_primary">Primary color</label></th>
                <td><input type="color" name="sie_color_primary" id="sie_color_primary"
                           value="<?php echo esc_attr( get_option( 'sie_color_primary', '#2563eb' ) ); ?>" />
                    <span class="description" style="margin-left:8px;">Send button, search bar focus, toggle button.</span></td>
            </tr>
            <tr>
                <th><label for="sie_color_user_bubble">User message color</label></th>
                <td><input type="color" name="sie_color_user_bubble" id="sie_color_user_bubble"
                           value="<?php echo esc_attr( get_option( 'sie_color_user_bubble', '#2563eb' ) ); ?>" />
                    <span class="description" style="margin-left:8px;">Background of user messages in chat widget.</span></td>
            </tr>
            <tr>
                <th><label for="sie_color_assistant_bg">Assistant message background</label></th>
                <td><input type="color" name="sie_color_assistant_bg" id="sie_color_assistant_bg"
                           value="<?php echo esc_attr( get_option( 'sie_color_assistant_bg', '#f1f5f9' ) ); ?>" />
                    <span class="description" style="margin-left:8px;">Background of assistant responses.</span></td>
            </tr>
            <tr>
                <th><label for="sie_color_header_bg">Widget header background</label></th>
                <td><input type="color" name="sie_color_header_bg" id="sie_color_header_bg"
                           value="<?php echo esc_attr( get_option( 'sie_color_header_bg', '#2563eb' ) ); ?>" />
                    <span class="description" style="margin-left:8px;">Header bar of the floating chat panel.</span></td>
            </tr>
        </table>

        <h2>Disclaimer</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_chat_disclaimer">Chat disclaimer</label></th>
                <td><textarea name="sie_chat_disclaimer" id="sie_chat_disclaimer"
                              rows="2" class="large-text"><?php
                    echo esc_textarea( get_option( 'sie_chat_disclaimer', '' ) );
                ?></textarea>
                <p class="description">Displayed below the chat input in both the widget and page chat. Use for legal disclaimers, AI disclosure, etc. Leave blank to hide.</p></td>
            </tr>
        </table>
        <?php
    }

    private function section_prompts() {
        ?>
        <h2>System Prompt</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_system_prompt">Default system prompt</label></th>
                <td><textarea name="sie_system_prompt" id="sie_system_prompt"
                              rows="5" class="large-text"><?php
                    echo esc_textarea( get_option( 'sie_system_prompt' ) );
                ?></textarea>
                <p class="description">Instructions sent to the LLM with every query. Controls tone, behavior, and citation style. Chat persona prompts override this when selected.</p></td>
            </tr>
        </table>

        <?php
        $integrity_prompt = self::get_integrity_prompt();
        if ( $integrity_prompt ) : ?>
        <h2>Active Integrity Addendum</h2>
        <p class="description">This is automatically appended to every system prompt via the <a href="<?php echo esc_url( admin_url( 'admin.php?page=sie-settings&tab=integrity' ) ); ?>">Integrity tab</a>.</p>
        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;font-family:monospace;font-size:12px;white-space:pre-wrap;color:#475569;max-height:200px;overflow-y:auto;">
            <?php echo esc_html( $integrity_prompt ); ?>
        </div>
        <?php endif;
    }

    private function section_access() {
        $access = get_option( 'sie_chat_access', 'logged_in' );
        ?>
        <h2>Access Control</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_chat_access">Who can use the chat?</label></th>
                <td>
                    <select name="sie_chat_access" id="sie_chat_access">
                        <option value="public"    <?php selected( $access, 'public'    ); ?>>Public (anyone)</option>
                        <option value="logged_in" <?php selected( $access, 'logged_in' ); ?>>Logged-in users</option>
                        <option value="role"      <?php selected( $access, 'role'      ); ?>>Specific role only</option>
                    </select>
                </td>
            </tr>
            <tr id="sie_role_row" <?php echo $access !== 'role' ? 'style="display:none"' : ''; ?>>
                <th><label for="sie_chat_role">Required role</label></th>
                <td>
                    <select name="sie_chat_role" id="sie_chat_role">
                        <?php
                        $current = get_option( 'sie_chat_role', 'subscriber' );
                        foreach ( wp_roles()->get_names() as $role_key => $role_name ) {
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr( $role_key ),
                                selected( $current, $role_key, false ),
                                esc_html( $role_name )
                            );
                        }
                        ?>
                    </select>
                </td>
            </tr>
        </table>
        <script>
        document.getElementById('sie_chat_access').addEventListener('change', function () {
            document.getElementById('sie_role_row').style.display = this.value === 'role' ? '' : 'none';
        });
        </script>
        <?php
    }

    private function section_seo() {
        ?>
        <h2>SEO Integration</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_seo_plugin">Active SEO Plugin</label></th>
                <td>
                    <select name="sie_seo_plugin" id="sie_seo_plugin">
                        <?php
                        $current_seo = get_option( 'sie_seo_plugin', 'auto' );
                        foreach ( SIE_SEO_Meta::get_supported_plugins() as $val => $label ) {
                            printf(
                                '<option value="%s"%s>%s</option>',
                                esc_attr( $val ),
                                selected( $current_seo, $val, false ),
                                esc_html( $label )
                            );
                        }
                        ?>
                    </select>
                    <p class="description">Used by sync tools to read/write focus keywords, meta descriptions, and SEO titles via REST API.</p>
                </td>
            </tr>
        </table>
        <?php
    }

    // =========================================================================
    // Tab: Integrity
    // =========================================================================

    /** Default core principles (Bill Bernard Standard). */
    private static function default_core_principles(): array {
        return [
            'quiet_hand' => [
                'name'      => 'The Quiet Hand',
                'subtitle'  => 'Humility, Service & Protection',
                'guideline' => 'Focus on helping the user, not self-promotion. Cite sources rather than taking credit. Prioritize the human over the process. Strength is used to lift others up.',
                'active'    => true,
            ],
            'iron_word' => [
                'name'      => 'The Iron Word',
                'subtitle'  => 'Reliability, Honesty & Stewardship',
                'guideline' => 'Only state what the knowledge base supports. Say "I don\'t know" rather than fabricate. Be transparent about confidence levels. Trust is only generated when integrity costs something.',
                'active'    => true,
            ],
            'unshakable_compass' => [
                'name'      => 'The Unshakable Compass',
                'subtitle'  => 'Moral Courage & Agency',
                'guideline' => 'Do not bend answers to what the user wants to hear. Maintain accuracy under pressure. Present the evidence and let the user decide. Values are not negotiable based on convenience.',
                'active'    => true,
            ],
            'steady_presence' => [
                'name'      => 'The Steady Presence',
                'subtitle'  => 'Composure & Antifragility',
                'guideline' => 'Respond with calm clarity, not noise. Structure answers for readability. When information is incomplete, state what is known and what is missing without hedging excessively.',
                'active'    => true,
            ],
        ];
    }

    /**
     * Get the full integrity config (core principles + site values).
     */
    public static function get_integrity(): array {
        $defaults = self::default_core_principles();
        $saved    = get_option( 'sie_integrity', [] );

        $core = $defaults;
        if ( isset( $saved['core'] ) && is_array( $saved['core'] ) ) {
            foreach ( $saved['core'] as $key => $overrides ) {
                if ( isset( $core[ $key ] ) ) {
                    $core[ $key ] = array_merge( $core[ $key ], $overrides );
                }
            }
        }

        $site_values = isset( $saved['site_values'] ) && is_array( $saved['site_values'] )
            ? $saved['site_values']
            : [];

        $enabled = isset( $saved['enabled'] ) ? (bool) $saved['enabled'] : true;

        return [
            'enabled'     => $enabled,
            'core'        => $core,
            'site_values' => $site_values,
        ];
    }

    /**
     * Build the integrity prompt fragment for injection into system prompts.
     */
    public static function get_integrity_prompt(): string {
        $integrity = self::get_integrity();
        if ( ! $integrity['enabled'] ) return '';

        $lines = [];
        $lines[] = "\n\n## Integrity Principles\nYou must adhere to these principles in every response:\n";

        // Core principles
        foreach ( $integrity['core'] as $p ) {
            if ( empty( $p['active'] ) ) continue;
            $lines[] = '- **' . $p['name'] . '** (' . $p['subtitle'] . '): ' . $p['guideline'];
        }

        // Site values
        if ( ! empty( $integrity['site_values'] ) ) {
            $lines[] = "\n### Organization Values";
            foreach ( $integrity['site_values'] as $v ) {
                if ( empty( $v['active'] ) ) continue;
                $lines[] = '- **' . $v['name'] . '**: ' . $v['guideline'];
            }
        }

        return implode( "\n", $lines );
    }

    private function tab_integrity() {
        // Handle save
        if ( isset( $_POST['sie_integrity_save'] ) && check_admin_referer( 'sie_integrity_save' ) ) {
            $data = [ 'enabled' => isset( $_POST['integrity_enabled'] ), 'core' => [], 'site_values' => [] ];

            // Core principles
            $core_keys = $_POST['core_key'] ?? [];
            foreach ( $core_keys as $i => $key ) {
                $key = sanitize_key( $key );
                if ( ! $key ) continue;
                $data['core'][ $key ] = [
                    'name'      => sanitize_text_field( $_POST['core_name'][ $i ] ?? '' ),
                    'subtitle'  => sanitize_text_field( $_POST['core_subtitle'][ $i ] ?? '' ),
                    'guideline' => sanitize_textarea_field( $_POST['core_guideline'][ $i ] ?? '' ),
                    'active'    => isset( $_POST['core_active'][ $i ] ),
                ];
            }

            // Site values
            $sv_names = $_POST['sv_name'] ?? [];
            foreach ( $sv_names as $i => $name ) {
                $name = sanitize_text_field( $name );
                if ( ! $name ) continue;
                $data['site_values'][] = [
                    'name'      => $name,
                    'guideline' => sanitize_textarea_field( $_POST['sv_guideline'][ $i ] ?? '' ),
                    'active'    => isset( $_POST['sv_active'][ $i ] ),
                ];
            }

            update_option( 'sie_integrity', $data );
            echo '<div class="notice notice-success is-dismissible"><p>Integrity settings saved.</p></div>';
        }

        $integrity = self::get_integrity();
        ?>
        <p>The Integrity layer governs how the AI behaves at a fundamental level. <strong>Core Principles</strong> (the Bill Bernard Standard) are always present. <strong>Organization Values</strong> are site-specific and layered on top. Both are injected into every system prompt.</p>

        <form method="post">
            <?php wp_nonce_field( 'sie_integrity_save' ); ?>
            <input type="hidden" name="sie_integrity_save" value="1" />

            <table class="form-table" role="presentation">
                <tr>
                    <th>Enable Integrity Layer</th>
                    <td>
                        <label>
                            <input type="checkbox" name="integrity_enabled" value="1"
                                   <?php checked( $integrity['enabled'] ); ?> />
                            Inject integrity principles into every AI response
                        </label>
                        <p class="description">When enabled, the core principles and organization values below are appended to the system prompt for all agents.</p>
                    </td>
                </tr>
            </table>

            <h2>Core Principles <span style="font-weight:normal;font-size:13px;color:#64748b;">— The Bill Bernard Standard</span></h2>
            <p class="description">The ethical kernel. These govern honesty, humility, courage, and composure in every response. Customize the guidelines to match your tone, or deactivate individual principles.</p>

            <?php $ci = 0; foreach ( $integrity['core'] as $key => $p ) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-bottom:12px;">
                <input type="hidden" name="core_key[<?php echo $ci; ?>]" value="<?php echo esc_attr( $key ); ?>" />
                <div style="display:flex;align-items:center;gap:12px;margin-bottom:10px;">
                    <label style="font-weight:600;flex:1;">
                        <input type="checkbox" name="core_active[<?php echo $ci; ?>]" value="1"
                               <?php checked( ! empty( $p['active'] ) ); ?> />
                        <input type="text" name="core_name[<?php echo $ci; ?>]"
                               value="<?php echo esc_attr( $p['name'] ); ?>"
                               style="width:200px;font-weight:600;" />
                    </label>
                    <input type="text" name="core_subtitle[<?php echo $ci; ?>]"
                           value="<?php echo esc_attr( $p['subtitle'] ); ?>"
                           style="width:280px;color:#64748b;" placeholder="Subtitle" />
                </div>
                <textarea name="core_guideline[<?php echo $ci; ?>]" rows="2"
                          class="large-text" placeholder="AI behavioral guideline..."><?php echo esc_textarea( $p['guideline'] ); ?></textarea>
            </div>
            <?php $ci++; endforeach; ?>

            <h2>Organization Values <span style="font-weight:normal;font-size:13px;color:#64748b;">— Site-Specific</span></h2>
            <p class="description">Add your organization's core values. These are layered on top of the Bill Bernard Standard and customized per SIE instance. E.g., "Hardworking", "Ethical", "Partnership".</p>

            <div id="sie-site-values">
            <?php $si = 0; foreach ( $integrity['site_values'] as $v ) : ?>
                <div class="sie-sv-row" style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;">
                    <label style="flex-shrink:0;padding-top:4px;">
                        <input type="checkbox" name="sv_active[<?php echo $si; ?>]" value="1"
                               <?php checked( ! empty( $v['active'] ) ); ?> />
                    </label>
                    <input type="text" name="sv_name[<?php echo $si; ?>]"
                           value="<?php echo esc_attr( $v['name'] ); ?>"
                           style="width:160px;font-weight:600;" placeholder="Value name" />
                    <textarea name="sv_guideline[<?php echo $si; ?>]" rows="2"
                              style="flex:1;" placeholder="How this value should guide AI responses..."><?php echo esc_textarea( $v['guideline'] ); ?></textarea>
                    <button type="button" class="button sie-sv-remove" title="Remove" style="color:#dc2626;">&times;</button>
                </div>
            <?php $si++; endforeach; ?>
            </div>

            <button type="button" id="sie-sv-add" class="button" style="margin-bottom:16px;">+ Add Value</button>

            <h2>Prompt Preview</h2>
            <p class="description">This text is appended to every system prompt (and agent prompts) when the integrity layer is enabled.</p>
            <div id="sie-integrity-preview" style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:16px;font-family:monospace;font-size:13px;white-space:pre-wrap;color:#334155;max-height:300px;overflow-y:auto;"><?php
                echo esc_html( self::get_integrity_prompt() ?: '(Integrity layer is disabled)' );
            ?></div>

            <?php submit_button( 'Save Integrity Settings' ); ?>
        </form>

        <script>
        (function(){
            var idx = <?php echo max( $si, 0 ); ?>;
            document.getElementById('sie-sv-add').addEventListener('click', function(){
                var html =
                    '<div class="sie-sv-row" style="display:flex;align-items:flex-start;gap:10px;margin-bottom:10px;background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:12px;">' +
                        '<label style="flex-shrink:0;padding-top:4px;"><input type="checkbox" name="sv_active['+idx+']" value="1" checked /></label>' +
                        '<input type="text" name="sv_name['+idx+']" style="width:160px;font-weight:600;" placeholder="Value name" />' +
                        '<textarea name="sv_guideline['+idx+']" rows="2" style="flex:1;" placeholder="How this value should guide AI responses..."></textarea>' +
                        '<button type="button" class="button sie-sv-remove" title="Remove" style="color:#dc2626;">&times;</button>' +
                    '</div>';
                document.getElementById('sie-site-values').insertAdjacentHTML('beforeend', html);
                idx++;
            });

            document.getElementById('sie-site-values').addEventListener('click', function(e){
                if(e.target.classList.contains('sie-sv-remove')){
                    e.target.closest('.sie-sv-row').remove();
                }
            });
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Tab: Models
    // =========================================================================

    private function tab_models() {
        $provider = get_option( 'sie_llm_provider', 'openai' );
        ?>
        <h2>LLM Provider</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_llm_provider">Active Provider</label></th>
                <td>
                    <select name="sie_llm_provider" id="sie_llm_provider">
                        <option value="openai"    <?php selected( $provider, 'openai'    ); ?>>OpenAI</option>
                        <option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
                        <option value="gemini"    <?php selected( $provider, 'gemini'    ); ?>>Google (Gemini)</option>
                    </select>
                    <p class="description">Embeddings always use OpenAI. Only the chat completion provider changes.</p>
                </td>
            </tr>
        </table>

        <h2>Model Selection</h2>
        <table class="form-table" role="presentation">
            <tr id="sie_openai_model_row" <?php echo $provider !== 'openai' ? 'style="display:none"' : ''; ?>>
                <th><label for="sie_openai_model">OpenAI Model</label></th>
                <td>
                    <?php $omodel = get_option( 'sie_openai_model', 'gpt-4o-mini' ); ?>
                    <select name="sie_openai_model" id="sie_openai_model">
                        <option value="gpt-4o-mini"  <?php selected( $omodel, 'gpt-4o-mini'  ); ?>>GPT-4o Mini (fast, low cost)</option>
                        <option value="gpt-4o"       <?php selected( $omodel, 'gpt-4o'       ); ?>>GPT-4o (balanced)</option>
                        <option value="gpt-4.1"      <?php selected( $omodel, 'gpt-4.1'      ); ?>>GPT-4.1 (latest)</option>
                        <option value="gpt-4.1-mini" <?php selected( $omodel, 'gpt-4.1-mini' ); ?>>GPT-4.1 Mini (latest, low cost)</option>
                        <option value="o3-mini"      <?php selected( $omodel, 'o3-mini'      ); ?>>o3-mini (reasoning)</option>
                    </select>
                </td>
            </tr>
            <tr id="sie_anthropic_model_row" <?php echo $provider !== 'anthropic' ? 'style="display:none"' : ''; ?>>
                <th><label for="sie_anthropic_model">Anthropic Model</label></th>
                <td>
                    <?php $amodel = get_option( 'sie_anthropic_model', 'claude-sonnet-4-5-20250514' ); ?>
                    <select name="sie_anthropic_model" id="sie_anthropic_model">
                        <option value="claude-haiku-4-5-20251001"  <?php selected( $amodel, 'claude-haiku-4-5-20251001'  ); ?>>Claude Haiku 4.5 (fast, low cost)</option>
                        <option value="claude-sonnet-4-5-20250514" <?php selected( $amodel, 'claude-sonnet-4-5-20250514' ); ?>>Claude Sonnet 4.5 (balanced)</option>
                        <option value="claude-opus-4-6"            <?php selected( $amodel, 'claude-opus-4-6'            ); ?>>Claude Opus 4.6 (most capable)</option>
                    </select>
                </td>
            </tr>
            <tr id="sie_gemini_model_row" <?php echo $provider !== 'gemini' ? 'style="display:none"' : ''; ?>>
                <th><label for="sie_gemini_model">Gemini Model</label></th>
                <td>
                    <?php $gmodel = get_option( 'sie_gemini_model', 'gemini-2.5-flash' ); ?>
                    <select name="sie_gemini_model" id="sie_gemini_model">
                        <option value="gemini-2.5-flash" <?php selected( $gmodel, 'gemini-2.5-flash' ); ?>>Gemini 2.5 Flash (fast, low cost)</option>
                        <option value="gemini-2.5-pro"   <?php selected( $gmodel, 'gemini-2.5-pro'   ); ?>>Gemini 2.5 Pro (balanced)</option>
                    </select>
                </td>
            </tr>
        </table>

        <h2>Generation Settings</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_temperature">Temperature</label></th>
                <td>
                    <input type="range" name="sie_temperature" id="sie_temperature"
                           min="0" max="1" step="0.1"
                           value="<?php echo esc_attr( get_option( 'sie_temperature', '0.2' ) ); ?>" />
                    <span id="sie_temp_value" style="font-weight:600;margin-left:8px;"><?php echo esc_html( get_option( 'sie_temperature', '0.2' ) ); ?></span>
                    <p class="description">Lower = more factual/consistent. Higher = more creative. Recommended: 0.1–0.3 for enterprise.</p>
                </td>
            </tr>
        </table>

        <script>
        document.getElementById('sie_llm_provider').addEventListener('change', function () {
            document.getElementById('sie_openai_model_row').style.display    = this.value === 'openai'    ? '' : 'none';
            document.getElementById('sie_anthropic_model_row').style.display = this.value === 'anthropic' ? '' : 'none';
            document.getElementById('sie_gemini_model_row').style.display    = this.value === 'gemini'    ? '' : 'none';
        });
        document.getElementById('sie_temperature').addEventListener('input', function () {
            document.getElementById('sie_temp_value').textContent = this.value;
        });
        </script>
        <?php
    }

    // =========================================================================
    // Tab: Content (Triad Labels + Related Content)
    // =========================================================================

    private function tab_content() {
        $triad = SIE_CPT::triad_labels();
        ?>
        <h2>Permalinks</h2>
        <p class="description">Customize URL slugs for SIE content types. Leave blank to use defaults. Rewrites flush automatically on save.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_kb_slug">Knowledge Base</label></th>
                <td>
                    <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                    <input type="text" name="sie_kb_slug" id="sie_kb_slug"
                           value="<?php echo esc_attr( get_option( 'sie_kb_slug' ) ); ?>"
                           style="width:120px;" placeholder="kb" />
                    <code>/%knowledge_topic%/post-name/</code>
                    <p class="description">Archive and single URL base for Knowledge Base articles.</p>
                </td>
            </tr>
            <tr>
                <th><label for="sie_label_faq_slug">FAQ</label></th>
                <td>
                    <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                    <input type="text" name="sie_label_faq_slug" id="sie_label_faq_slug"
                           value="<?php echo esc_attr( get_option( 'sie_label_faq_slug' ) ); ?>"
                           style="width:120px;" placeholder="faq" />
                    <code>/post-name/</code>
                </td>
            </tr>
            <tr>
                <th><label for="sie_label_insight_slug">Insight</label></th>
                <td>
                    <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                    <input type="text" name="sie_label_insight_slug" id="sie_label_insight_slug"
                           value="<?php echo esc_attr( get_option( 'sie_label_insight_slug' ) ); ?>"
                           style="width:120px;" placeholder="insights" />
                    <code>/post-name/</code>
                </td>
            </tr>
            <tr>
                <th><label for="sie_label_guide_slug">Guide</label></th>
                <td>
                    <code><?php echo esc_html( home_url( '/' ) ); ?></code>
                    <input type="text" name="sie_label_guide_slug" id="sie_label_guide_slug"
                           value="<?php echo esc_attr( get_option( 'sie_label_guide_slug' ) ); ?>"
                           style="width:120px;" placeholder="guides" />
                    <code>/post-name/</code>
                </td>
            </tr>
        </table>

        <h2>Content Type Labels</h2>
        <p class="description">Customize the display names of the three content types. Changes apply to admin menus, shortcode headings, and the REST API. Leave blank for defaults.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th>FAQ</th>
                <td>
                    <input type="text" name="sie_label_faq_singular"
                           value="<?php echo esc_attr( get_option( 'sie_label_faq_singular' ) ); ?>"
                           class="regular-text" placeholder="FAQ" />
                    <input type="text" name="sie_label_faq_plural"
                           value="<?php echo esc_attr( get_option( 'sie_label_faq_plural' ) ); ?>"
                           class="regular-text" placeholder="FAQs" />
                    <p class="description">Singular / Plural</p>
                </td>
            </tr>
            <tr>
                <th>Insight</th>
                <td>
                    <input type="text" name="sie_label_insight_singular"
                           value="<?php echo esc_attr( get_option( 'sie_label_insight_singular' ) ); ?>"
                           class="regular-text" placeholder="Insight" />
                    <input type="text" name="sie_label_insight_plural"
                           value="<?php echo esc_attr( get_option( 'sie_label_insight_plural' ) ); ?>"
                           class="regular-text" placeholder="Insights" />
                    <p class="description">Singular / Plural (e.g., "Pro Tip" / "Pro Tips" for another site)</p>
                </td>
            </tr>
            <tr>
                <th>Guide</th>
                <td>
                    <input type="text" name="sie_label_guide_singular"
                           value="<?php echo esc_attr( get_option( 'sie_label_guide_singular' ) ); ?>"
                           class="regular-text" placeholder="Guide" />
                    <input type="text" name="sie_label_guide_plural"
                           value="<?php echo esc_attr( get_option( 'sie_label_guide_plural' ) ); ?>"
                           class="regular-text" placeholder="Guides" />
                    <p class="description">Singular / Plural</p>
                </td>
            </tr>
        </table>

        <h2>Related Content</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th>Auto-Append</th>
                <td>
                    <label>
                        <input type="checkbox" name="sie_auto_related" value="1"
                               <?php checked( get_option( 'sie_auto_related', '0' ), '1' ); ?> />
                        Automatically display related FAQs, Insights &amp; Guides after post content
                    </label>
                    <p class="description">Applies to Knowledge Base articles, Posts, Pages, and Products. Skipped if a shortcode is already present.</p>
                </td>
            </tr>
        </table>

        <h2>Shortcode Reference</h2>
        <table class="widefat striped" style="max-width:700px;">
            <thead>
                <tr><th>Shortcode</th><th>Description</th></tr>
            </thead>
            <tbody>
                <tr><td><code>[sie_related]</code></td><td>All related FAQs, Insights &amp; Guides for the current post</td></tr>
                <tr><td><code>[sie_faqs]</code></td><td>Related FAQs only</td></tr>
                <tr><td><code>[sie_insights]</code></td><td>Related Insights only</td></tr>
                <tr><td><code>[sie_guides]</code></td><td>Related Guides only</td></tr>
            </tbody>
        </table>
        <p class="description" style="margin-top:8px;">
            Attributes: <code>style="accordion|list|cards"</code> &nbsp; <code>limit="5"</code> &nbsp; <code>post_id="123"</code>
        </p>
        <?php
    }

    // =========================================================================
    // Tab: Agents (CrewAI Worker Agents)
    // =========================================================================

    /** Worker agent definitions — maps to CrewAI agents in sie-engine/agents/ */
    private static function worker_agents(): array {
        return [
            'research' => [
                'name'        => 'Research Agent',
                'icon'        => 'dashicons-search',
                'role'        => 'External Intelligence Gatherer & Knowledge Steward',
                'description' => 'Queries the Knowledge Core (Pinecone), conducts web research, scrapes URLs, and synthesizes findings into actionable intelligence.',
                'tools'       => [ 'Pinecone Search', 'Web Search (SerperDev)', 'URL Scraper (Firecrawl)' ],
                'script'      => 'run_test.py',
                'tasks'       => [
                    'research_topic' => 'Research a topic — query KB, web search, deep-read articles, produce gap analysis',
                ],
            ],
            'analyst' => [
                'name'        => 'Analyst Agent',
                'icon'        => 'dashicons-chart-bar',
                'role'        => 'Knowledge Synthesis & Gap Detection Specialist',
                'description' => 'Analyzes the Knowledge Core for coverage gaps, content freshness, semantic relationships, and strategic content opportunities.',
                'tools'       => [ 'Pinecone Search', 'Gap Detection', 'Freshness Scoring', 'Semantic Links' ],
                'script'      => 'run_analyst_test.py',
                'tasks'       => [
                    'analyze_coverage' => 'Analyze topic coverage — identify gaps, assess freshness, suggest internal links',
                    'content_audit'    => 'Audit content quality — broken links, outdated information, missing metadata',
                ],
            ],
            'editor' => [
                'name'        => 'Editor Agent',
                'icon'        => 'dashicons-edit',
                'role'        => 'Content Generation & WordPress Integration Specialist',
                'description' => 'Transforms research and outlines into publication-ready articles, validates schema compliance, inserts internal links, and saves to WordPress as drafts.',
                'tools'       => [ 'WordPress REST API', 'Schema Validation', 'Internal Link Insertion' ],
                'script'      => 'run_editor_test.py',
                'tasks'       => [
                    'generate_article' => 'Generate article from outline — expand, validate schema, add links, save as draft',
                    'improve_content'  => 'Improve existing post — enhance structure, add citations, optimize for SEO',
                ],
            ],
        ];
    }

    private function tab_agents() {
        // Handle dispatch
        if ( isset( $_POST['sie_agent_dispatch'] ) && check_admin_referer( 'sie_agent_dispatch' ) ) {
            $agent_key = sanitize_key( $_POST['dispatch_agent'] ?? '' );
            $task_key  = sanitize_key( $_POST['dispatch_task'] ?? '' );
            $input     = sanitize_textarea_field( $_POST['dispatch_input'] ?? '' );

            if ( $agent_key && $task_key && $input ) {
                $job = [
                    'id'         => 'sie_job_' . wp_generate_password( 8, false ),
                    'agent'      => $agent_key,
                    'task'       => $task_key,
                    'input'      => $input,
                    'status'     => 'queued',
                    'queued_at'  => current_time( 'mysql' ),
                    'started_at' => null,
                    'completed_at' => null,
                    'result'     => null,
                    'user'       => wp_get_current_user()->user_login,
                ];

                $jobs = get_option( 'sie_agent_jobs', [] );
                array_unshift( $jobs, $job );
                // Keep last 50 jobs
                $jobs = array_slice( $jobs, 0, 50 );
                update_option( 'sie_agent_jobs', $jobs );

                echo '<div class="notice notice-success is-dismissible"><p>Job <strong>' . esc_html( $job['id'] ) . '</strong> queued for <strong>' . esc_html( self::worker_agents()[ $agent_key ]['name'] ?? $agent_key ) . '</strong>.</p></div>';
            }
        }

        $agents = self::worker_agents();
        $jobs   = get_option( 'sie_agent_jobs', [] );
        ?>
        <p>Worker agents are autonomous AI systems powered by <strong>CrewAI</strong> that perform background tasks — research, analysis, content generation, and site auditing. They operate on the Knowledge Core (Pinecone) and WordPress, always saving as drafts for human review.</p>

        <h2>Available Agents</h2>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;margin-bottom:24px;">
        <?php foreach ( $agents as $key => $agent ) : ?>
            <div style="background:#fff;border:1px solid #c3c4c7;border-radius:6px;padding:20px;">
                <h3 style="margin:0 0 4px;display:flex;align-items:center;gap:8px;">
                    <span class="dashicons <?php echo esc_attr( $agent['icon'] ); ?>" style="color:#2563eb;"></span>
                    <?php echo esc_html( $agent['name'] ); ?>
                </h3>
                <p style="margin:0 0 8px;color:#64748b;font-size:12px;font-style:italic;"><?php echo esc_html( $agent['role'] ); ?></p>
                <p style="margin:0 0 12px;font-size:13px;color:#475569;"><?php echo esc_html( $agent['description'] ); ?></p>

                <div style="margin-bottom:12px;">
                    <strong style="font-size:12px;color:#334155;">Tools:</strong>
                    <div style="display:flex;flex-wrap:wrap;gap:4px;margin-top:4px;">
                    <?php foreach ( $agent['tools'] as $tool ) : ?>
                        <span style="display:inline-block;padding:2px 8px;background:#f1f5f9;border-radius:4px;font-size:11px;color:#475569;">
                            <?php echo esc_html( $tool ); ?>
                        </span>
                    <?php endforeach; ?>
                    </div>
                </div>

                <div style="margin-bottom:0;">
                    <strong style="font-size:12px;color:#334155;">Tasks:</strong>
                    <ul style="margin:4px 0 0;padding-left:16px;">
                    <?php foreach ( $agent['tasks'] as $task_key => $task_desc ) : ?>
                        <li style="font-size:12px;color:#475569;margin-bottom:2px;">
                            <code style="font-size:11px;"><?php echo esc_html( $task_key ); ?></code>
                            — <?php echo esc_html( $task_desc ); ?>
                        </li>
                    <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        <?php endforeach; ?>
        </div>

        <h2>Dispatch a Task</h2>
        <form method="post">
            <?php wp_nonce_field( 'sie_agent_dispatch' ); ?>
            <input type="hidden" name="sie_agent_dispatch" value="1" />

            <table class="form-table" role="presentation">
                <tr>
                    <th><label for="dispatch_agent">Agent</label></th>
                    <td>
                        <select name="dispatch_agent" id="dispatch_agent">
                            <?php foreach ( $agents as $key => $agent ) : ?>
                                <option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $agent['name'] ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="dispatch_task">Task</label></th>
                    <td>
                        <select name="dispatch_task" id="dispatch_task"></select>
                        <p class="description" id="dispatch_task_desc"></p>
                    </td>
                </tr>
                <tr>
                    <th><label for="dispatch_input">Input</label></th>
                    <td>
                        <textarea name="dispatch_input" id="dispatch_input" rows="3" class="large-text"
                                  placeholder="e.g., Research 'content clustering strategies' for the knowledge base..."></textarea>
                        <p class="description">Topic, URL, post ID, or detailed instructions for the agent.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button( 'Queue Job', 'primary', 'submit', true ); ?>
        </form>

        <h2>Job History</h2>
        <?php if ( empty( $jobs ) ) : ?>
            <p class="description">No agent jobs have been dispatched yet.</p>
        <?php else : ?>
            <table class="widefat striped" style="max-width:900px;">
                <thead>
                    <tr>
                        <th>Job ID</th>
                        <th>Agent</th>
                        <th>Task</th>
                        <th>Input</th>
                        <th>Status</th>
                        <th>Queued</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ( array_slice( $jobs, 0, 20 ) as $job ) :
                    $agent_name = $agents[ $job['agent'] ]['name'] ?? $job['agent'];
                    $status_colors = [
                        'queued'    => '#f59e0b',
                        'running'   => '#3b82f6',
                        'completed' => '#22c55e',
                        'failed'    => '#ef4444',
                    ];
                    $status_color = $status_colors[ $job['status'] ] ?? '#94a3b8';
                ?>
                    <tr>
                        <td><code style="font-size:11px;"><?php echo esc_html( $job['id'] ); ?></code></td>
                        <td><?php echo esc_html( $agent_name ); ?></td>
                        <td><code style="font-size:11px;"><?php echo esc_html( $job['task'] ); ?></code></td>
                        <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="<?php echo esc_attr( $job['input'] ); ?>">
                            <?php echo esc_html( wp_trim_words( $job['input'], 8 ) ); ?>
                        </td>
                        <td>
                            <span style="display:inline-block;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;color:#fff;background:<?php echo esc_attr( $status_color ); ?>;">
                                <?php echo esc_html( ucfirst( $job['status'] ) ); ?>
                            </span>
                        </td>
                        <td style="font-size:12px;color:#64748b;"><?php echo esc_html( $job['queued_at'] ); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <script>
        (function(){
            var agentTasks = <?php echo wp_json_encode( array_map( function( $a ) { return $a['tasks']; }, $agents ) ); ?>;
            var agentSelect = document.getElementById('dispatch_agent');
            var taskSelect  = document.getElementById('dispatch_task');
            var taskDesc    = document.getElementById('dispatch_task_desc');

            function updateTasks() {
                var key   = agentSelect.value;
                var tasks = agentTasks[key] || {};
                taskSelect.innerHTML = '';
                taskDesc.textContent = '';
                for (var tk in tasks) {
                    var opt = document.createElement('option');
                    opt.value = tk;
                    opt.textContent = tk.replace(/_/g, ' ');
                    taskSelect.appendChild(opt);
                }
                updateDesc();
            }

            function updateDesc() {
                var key   = agentSelect.value;
                var tasks = agentTasks[key] || {};
                taskDesc.textContent = tasks[taskSelect.value] || '';
            }

            agentSelect.addEventListener('change', updateTasks);
            taskSelect.addEventListener('change', updateDesc);
            updateTasks();
        })();
        </script>
        <?php
    }

    // =========================================================================
    // Tab: Chat Personas
    // =========================================================================

    private function tab_personas() {
        // Handle save
        if ( isset( $_POST['sie_agents_save'] ) && check_admin_referer( 'sie_agents_save' ) ) {
            $agents = [];
            $keys   = $_POST['agent_key'] ?? [];
            foreach ( $keys as $i => $key ) {
                $key = sanitize_key( $key );
                if ( ! $key ) continue;
                $agents[ $key ] = [
                    'name'        => sanitize_text_field( $_POST['agent_name'][ $i ] ?? '' ),
                    'icon'        => sanitize_text_field( $_POST['agent_icon'][ $i ] ?? 'dashicons-admin-generic' ),
                    'description' => sanitize_text_field( $_POST['agent_desc'][ $i ] ?? '' ),
                    'prompt'      => sanitize_textarea_field( $_POST['agent_prompt'][ $i ] ?? '' ),
                    'model'       => sanitize_text_field( $_POST['agent_model'][ $i ] ?? '' ),
                    'temperature' => sanitize_text_field( $_POST['agent_temp'][ $i ] ?? '' ),
                    'active'      => isset( $_POST['agent_active'][ $i ] ),
                ];
            }
            SIE_Agents::save_agents( $agents );
            echo '<div class="notice notice-success is-dismissible"><p>Agents saved.</p></div>';
        }

        $agents = SIE_Agents::get_agents();
        ?>
        <p>Chat personas are AI personalities with specialized system prompts. Users switch personas in the chat UI to get different styles of response — all powered by the same knowledge base.</p>

        <form method="post">
            <?php wp_nonce_field( 'sie_agents_save' ); ?>
            <input type="hidden" name="sie_agents_save" value="1" />

            <div id="sie-agents-list">
            <?php $idx = 0; foreach ( $agents as $key => $agent ) : ?>
                <div class="sie-agent-card" style="background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:16px;margin-bottom:16px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                        <h3 style="margin:0;">
                            <span class="dashicons <?php echo esc_attr( $agent['icon'] ); ?>" style="margin-right:6px;color:#2563eb;"></span>
                            <?php echo esc_html( $agent['name'] ); ?>
                            <?php if ( ! empty( $agent['builtin'] ) ) : ?>
                                <span style="font-size:11px;color:#94a3b8;font-weight:normal;margin-left:8px;">built-in</span>
                            <?php endif; ?>
                        </h3>
                        <label>
                            <input type="checkbox" name="agent_active[<?php echo $idx; ?>]" value="1"
                                   <?php checked( ! empty( $agent['active'] ) ); ?> />
                            Active
                        </label>
                    </div>

                    <input type="hidden" name="agent_key[<?php echo $idx; ?>]" value="<?php echo esc_attr( $key ); ?>" />

                    <table class="form-table" style="margin:0;" role="presentation">
                        <tr>
                            <th style="width:120px;"><label>Name</label></th>
                            <td><input type="text" name="agent_name[<?php echo $idx; ?>]"
                                       value="<?php echo esc_attr( $agent['name'] ); ?>" class="regular-text" /></td>
                        </tr>
                        <tr>
                            <th><label>Icon</label></th>
                            <td><input type="text" name="agent_icon[<?php echo $idx; ?>]"
                                       value="<?php echo esc_attr( $agent['icon'] ); ?>" class="regular-text" />
                                <p class="description">Dashicons class, e.g. <code>dashicons-search</code>. <a href="https://developer.wordpress.org/resource/dashicons/" target="_blank">Browse icons</a></p></td>
                        </tr>
                        <tr>
                            <th><label>Description</label></th>
                            <td><input type="text" name="agent_desc[<?php echo $idx; ?>]"
                                       value="<?php echo esc_attr( $agent['description'] ); ?>" class="large-text" /></td>
                        </tr>
                        <tr>
                            <th><label>System Prompt</label></th>
                            <td><textarea name="agent_prompt[<?php echo $idx; ?>]"
                                          rows="5" class="large-text"><?php echo esc_textarea( $agent['prompt'] ); ?></textarea></td>
                        </tr>
                        <tr>
                            <th><label>Model Override</label></th>
                            <td><input type="text" name="agent_model[<?php echo $idx; ?>]"
                                       value="<?php echo esc_attr( $agent['model'] ?? '' ); ?>" class="regular-text"
                                       placeholder="Use global setting" />
                                <p class="description">Leave empty to use the model from the Models tab. Or specify e.g. <code>gpt-4o</code>, <code>claude-opus-4-6</code>.</p></td>
                        </tr>
                        <tr>
                            <th><label>Temperature Override</label></th>
                            <td><input type="text" name="agent_temp[<?php echo $idx; ?>]"
                                       value="<?php echo esc_attr( $agent['temperature'] ?? '' ); ?>" class="small-text"
                                       placeholder="Global" />
                                <p class="description">Leave empty for global setting. Range: 0–1.</p></td>
                        </tr>
                    </table>
                </div>
            <?php $idx++; endforeach; ?>
            </div>

            <?php submit_button( 'Save Personas' ); ?>
        </form>
        <?php
    }

    // =========================================================================
    // Tab: Guardrails
    // =========================================================================

    private function tab_guardrails() {
        ?>
        <h2>Confidence &amp; Safety</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_confidence_threshold">Confidence Threshold</label></th>
                <td>
                    <input type="range" name="sie_confidence_threshold" id="sie_confidence_threshold"
                           min="0" max="1" step="0.05"
                           value="<?php echo esc_attr( get_option( 'sie_confidence_threshold', '0.6' ) ); ?>" />
                    <span id="sie_conf_value" style="font-weight:600;margin-left:8px;"><?php echo esc_html( get_option( 'sie_confidence_threshold', '0.6' ) ); ?></span>
                    <p class="description">Minimum Pinecone similarity score. Below this, the fallback message is shown instead of querying the LLM.</p>
                </td>
            </tr>
            <tr>
                <th><label for="sie_low_confidence_msg">Fallback Message</label></th>
                <td><textarea name="sie_low_confidence_msg" id="sie_low_confidence_msg"
                              rows="3" class="large-text"><?php
                    echo esc_textarea( get_option( 'sie_low_confidence_msg', self::OPTIONS['sie_low_confidence_msg'] ) );
                ?></textarea>
                <p class="description">Shown when no KB content meets the threshold — prevents hallucination and saves API costs.</p></td>
            </tr>
            <tr>
                <th><label for="sie_daily_query_limit">Daily Query Limit</label></th>
                <td><input type="number" name="sie_daily_query_limit" id="sie_daily_query_limit"
                           value="<?php echo esc_attr( get_option( 'sie_daily_query_limit', '0' ) ); ?>"
                           class="small-text" min="0" step="1" />
                    <p class="description">Maximum queries per day (all users). 0 = unlimited. Cost ceiling.</p></td>
            </tr>
        </table>

        <h2>Guest Access Limits</h2>
        <p class="description">When chat access is set to "Public", guests can ask a limited number of questions before being prompted to sign in. Set to 0 to disable guest access entirely.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_guest_query_limit">Guest queries per session</label></th>
                <td><input type="number" name="sie_guest_query_limit" id="sie_guest_query_limit"
                           value="<?php echo esc_attr( get_option( 'sie_guest_query_limit', '3' ) ); ?>"
                           class="small-text" min="0" step="1" />
                    <p class="description">Number of questions a non-logged-in visitor can ask before seeing the sign-in prompt.</p></td>
            </tr>
            <tr>
                <th><label for="sie_guest_limit_msg">Sign-in prompt message</label></th>
                <td><textarea name="sie_guest_limit_msg" id="sie_guest_limit_msg"
                              rows="2" class="large-text"><?php
                    echo esc_textarea( get_option( 'sie_guest_limit_msg', self::OPTIONS['sie_guest_limit_msg'] ) );
                ?></textarea></td>
            </tr>
        </table>

        <h2>Logging &amp; Evaluation</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_enable_logging">Chat Logging</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="sie_enable_logging" id="sie_enable_logging" value="1"
                               <?php checked( get_option( 'sie_enable_logging', '1' ), '1' ); ?> />
                        Log all chat queries, responses, and sources for evaluation
                    </label>
                    <p class="description">
                        View logs at <a href="<?php echo esc_url( admin_url( 'tools.php?page=sie-chat-log' ) ); ?>">Tools → SIE Chat Log</a>.
                        Includes confidence scores, source citations, and user feedback.
                    </p>
                </td>
            </tr>
        </table>

        <script>
        document.getElementById('sie_confidence_threshold').addEventListener('input', function () {
            document.getElementById('sie_conf_value').textContent = this.value;
        });
        </script>
        <?php
    }

    // =========================================================================
    // Tab: Documents
    // =========================================================================

    private function tab_documents() {
        ?>
        <h2>Connected Post Types</h2>
        <p>Select post types to connect to the SIE ecosystem. Connected types get the <strong>SIE Topics</strong> taxonomy attached and become eligible for chat indexing via Pinecone.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th>Post Types</th>
                <td>
                    <?php
                    $connected   = get_option( 'sie_connected_cpts', [] );
                    if ( ! is_array( $connected ) ) $connected = [];
                    $connectable = SIE_CPT::get_connectable_cpts();

                    // Show SIE's built-in triad CPTs as always-connected
                    $triad       = SIE_CPT::triad_labels();
                    $builtin_map = [
                        'sie_faq'     => $triad['sie_faq']['plural'],
                        'sie_insight' => $triad['sie_insight']['plural'],
                        'sie_guide'   => $triad['sie_guide']['plural'],
                    ];
                    foreach ( $builtin_map as $slug => $label ) :
                    ?>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" checked disabled />
                            <strong><?php echo esc_html( $label ); ?></strong>
                            <code style="color:#888;margin-left:4px;"><?php echo esc_html( $slug ); ?></code>
                            <span style="color:#16a34a;font-size:12px;margin-left:4px;">built-in</span>
                        </label>
                    <?php endforeach;

                    if ( $connectable ) :
                        foreach ( $connectable as $slug => $label ) :
                    ?>
                        <label style="display:block;margin-bottom:8px;">
                            <input type="checkbox" name="sie_connected_cpts[]"
                                   value="<?php echo esc_attr( $slug ); ?>"
                                   <?php checked( in_array( $slug, $connected, true ) ); ?> />
                            <strong><?php echo esc_html( $label ); ?></strong>
                            <code style="color:#888;margin-left:4px;"><?php echo esc_html( $slug ); ?></code>
                        </label>
                    <?php
                        endforeach;
                    endif;
                    ?>
                </td>
            </tr>
        </table>

        <h2>Topic Mapping</h2>
        <p>Folder paths in the knowledge base are mapped to WordPress taxonomy terms. The sync pipeline uses these to auto-assign topics.</p>

        <?php
        // Show current mappings inline
        $terms = get_terms( [ 'taxonomy' => 'knowledge_topic', 'hide_empty' => false ] );
        if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) :
        ?>
        <table class="widefat striped" style="max-width:700px;">
            <thead>
                <tr>
                    <th>Topic</th>
                    <th>Path Pattern</th>
                    <th>Term ID</th>
                    <th>Posts</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $terms as $term ) :
                $pattern = get_term_meta( $term->term_id, '_sie_path_pattern', true );
            ?>
                <tr>
                    <td>
                        <a href="<?php echo esc_url( admin_url( 'term.php?taxonomy=knowledge_topic&tag_ID=' . $term->term_id . '&post_type=knowledge_base' ) ); ?>">
                            <?php
                            // Indent children
                            if ( $term->parent ) {
                                $depth = 0;
                                $p = $term->parent;
                                while ( $p ) {
                                    $depth++;
                                    $parent_term = get_term( $p, 'knowledge_topic' );
                                    $p = $parent_term && ! is_wp_error( $parent_term ) ? $parent_term->parent : 0;
                                }
                                echo str_repeat( '&mdash; ', $depth );
                            }
                            echo esc_html( $term->name );
                            ?>
                        </a>
                    </td>
                    <td><code><?php echo $pattern ? esc_html( $pattern ) : '<span style="color:#94a3b8;">not set</span>'; ?></code></td>
                    <td><?php echo intval( $term->term_id ); ?></td>
                    <td><?php echo intval( $term->count ); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;">
            <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=knowledge_topic&post_type=knowledge_base' ) ); ?>"
               class="button">Edit Knowledge Topics</a>
        </p>
        <?php else : ?>
        <p class="description">No knowledge topics found.
            <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=knowledge_topic&post_type=knowledge_base' ) ); ?>">Create topics</a>
        </p>
        <?php endif; ?>

        <table class="form-table" role="presentation" style="margin-top:10px;">
            <tr>
                <th>SIE Topics</th>
                <td>
                    <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=sie_topic' ) ); ?>"
                       class="button">Manage SIE Topics</a>
                    <p class="description" style="margin-top:4px;">Shared taxonomy across FAQ, Insight, Guide, and connected post types.</p>
                </td>
            </tr>
            <tr>
                <th>Topic API</th>
                <td>
                    <code><?php echo esc_url( rest_url( 'sie/v1/topics' ) ); ?></code>
                    <p class="description">Used by kb_sync.py for dynamic topic mapping.</p>
                </td>
            </tr>
        </table>

        <h2>Sync Configuration</h2>
        <p>Connect to your GitHub repository to trigger sync runs from this dashboard.</p>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_github_repo">GitHub Repository</label></th>
                <td><input type="text" name="sie_github_repo" id="sie_github_repo"
                           value="<?php echo esc_attr( get_option( 'sie_github_repo' ) ); ?>"
                           class="regular-text" placeholder="owner/repo-name" />
                    <p class="description">The instance repo (e.g. <code>adambernard55/sie-adambernard</code>), not the engine repo.</p></td>
            </tr>
            <?php $this->render_credential_field( 'sie_github_token', 'GitHub Token', 'password', 'Personal access token with repo and workflow scopes. Used to dispatch sync workflows.' ); ?>
            <tr>
                <th><label for="sie_sync_workflow">Workflow File</label></th>
                <td><input type="text" name="sie_sync_workflow" id="sie_sync_workflow"
                           value="<?php echo esc_attr( get_option( 'sie_sync_workflow', 'kb-sync.yml' ) ); ?>"
                           class="regular-text" />
                    <p class="description">GitHub Actions workflow filename to dispatch.</p></td>
            </tr>
        </table>

        <h2>Run Sync</h2>
        <?php
        $repo = get_option( 'sie_github_repo' );
        $token = sie_get_option( 'sie_github_token' );
        if ( $repo && $token ) :
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th>Filter path</th>
                <td>
                    <input type="text" id="sie_sync_filter" placeholder="e.g. AI/ or GROWTH/" class="regular-text" />
                    <p class="description">Optional. Only sync files under this folder prefix. Leave empty for all.</p>
                </td>
            </tr>
            <tr>
                <th>Batch size</th>
                <td>
                    <input type="number" id="sie_sync_batch" value="50" class="small-text" min="0" />
                    <p class="description">Max files per run. 0 = unlimited.</p>
                </td>
            </tr>
            <tr>
                <th>Offset</th>
                <td>
                    <input type="number" id="sie_sync_offset" value="0" class="small-text" min="0" />
                    <p class="description">Skip this many files (for paginated sync runs).</p>
                </td>
            </tr>
            <tr>
                <th>Dry run</th>
                <td>
                    <label>
                        <input type="checkbox" id="sie_sync_dry_run" />
                        Preview changes without syncing
                    </label>
                </td>
            </tr>
            <tr>
                <th></th>
                <td>
                    <button type="button" id="sie_dispatch_sync" class="button button-primary">Dispatch Sync</button>
                    <span id="sie_sync_status" style="margin-left:12px;"></span>
                </td>
            </tr>
        </table>

        <script>
        document.getElementById('sie_dispatch_sync').addEventListener('click', function () {
            var btn    = this;
            var status = document.getElementById('sie_sync_status');
            btn.disabled = true;
            status.textContent = 'Dispatching...';
            status.style.color = '#64748b';

            var data = new FormData();
            data.append('action', 'sie_dispatch_sync');
            data.append('_wpnonce', '<?php echo wp_create_nonce( 'sie_dispatch_sync' ); ?>');
            data.append('filter', document.getElementById('sie_sync_filter').value);
            data.append('batch_size', document.getElementById('sie_sync_batch').value);
            data.append('offset', document.getElementById('sie_sync_offset').value);
            data.append('dry_run', document.getElementById('sie_sync_dry_run').checked ? '1' : '0');

            fetch(ajaxurl, { method: 'POST', body: data })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    btn.disabled = false;
                    if (res.success) {
                        status.textContent = 'Sync dispatched successfully!';
                        status.style.color = '#16a34a';
                    } else {
                        status.textContent = 'Error: ' + (res.data || 'Unknown error');
                        status.style.color = '#dc2626';
                    }
                })
                .catch(function() {
                    btn.disabled = false;
                    status.textContent = 'Network error — check connection.';
                    status.style.color = '#dc2626';
                });
        });
        </script>
        <?php else : ?>
        <p class="description">Configure the GitHub repository and token above to enable sync dispatch from this dashboard.</p>
        <?php endif; ?>
        <?php
    }

    // =========================================================================
    // AJAX: Dispatch GitHub Actions workflow
    // =========================================================================

    public function ajax_dispatch_sync() {
        check_ajax_referer( 'sie_dispatch_sync' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( 'Unauthorized.' );
        }

        $repo     = get_option( 'sie_github_repo' );
        $token    = get_option( 'sie_github_token' );
        $workflow = get_option( 'sie_sync_workflow', 'kb-sync.yml' );

        if ( ! $repo || ! $token ) {
            wp_send_json_error( 'GitHub repo or token not configured.' );
        }

        $inputs = [];
        $filter = sanitize_text_field( $_POST['filter'] ?? '' );
        $batch  = absint( $_POST['batch_size'] ?? 50 );
        $offset = absint( $_POST['offset'] ?? 0 );
        $dry    = ( $_POST['dry_run'] ?? '0' ) === '1';

        if ( $filter ) $inputs['filter']     = $filter;
        if ( $batch )  $inputs['batch_size']  = (string) $batch;
        if ( $offset ) $inputs['offset']      = (string) $offset;
        if ( $dry )    $inputs['dry_run']     = 'true';

        $url = sprintf(
            'https://api.github.com/repos/%s/actions/workflows/%s/dispatches',
            sanitize_text_field( $repo ),
            sanitize_text_field( $workflow )
        );

        $response = wp_remote_post( $url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/vnd.github+json',
                'Content-Type'  => 'application/json',
            ],
            'body'    => wp_json_encode( [
                'ref'    => 'main',
                'inputs' => $inputs,
            ] ),
            'timeout' => 15,
        ] );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( $response->get_error_message() );
        }

        $code = wp_remote_retrieve_response_code( $response );

        if ( $code === 204 ) {
            wp_send_json_success( 'Workflow dispatched.' );
        } else {
            $body = json_decode( wp_remote_retrieve_body( $response ), true );
            wp_send_json_error( $body['message'] ?? "GitHub returned HTTP {$code}." );
        }
    }
}
