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
        // GitHub sync
        'sie_github_repo'          => '',
        'sie_github_token'         => '',
        'sie_sync_workflow'        => 'kb-sync.yml',
    ];

    const TABS = [
        'general'   => 'General',
        'models'    => 'Models',
        'guardrails'=> 'Guardrails',
        'documents' => 'Documents',
    ];

    public function init() {
        add_action( 'admin_menu',    [ $this, 'add_menu' ] );
        add_action( 'admin_init',    [ $this, 'register_settings' ] );
        add_action( 'wp_ajax_sie_dispatch_sync', [ $this, 'ajax_dispatch_sync' ] );
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

    public function register_settings() {
        foreach ( self::OPTIONS as $key => $default ) {
            register_setting( 'sie_settings_group', $key, [ 'default' => $default ] );
        }
        register_setting( 'sie_settings_group', 'sie_connected_cpts', [
            'default'           => [],
            'sanitize_callback' => function ( $value ) {
                return is_array( $value ) ? array_map( 'sanitize_key', $value ) : [];
            },
        ] );
    }

    // =========================================================================
    // Main render — tab router
    // =========================================================================

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;

        $active = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'general';
        if ( ! array_key_exists( $active, self::TABS ) ) $active = 'general';
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

            <form method="post" action="options.php">
                <?php
                settings_fields( 'sie_settings_group' );

                switch ( $active ) {
                    case 'general':   $this->tab_general();   break;
                    case 'models':    $this->tab_models();    break;
                    case 'guardrails':$this->tab_guardrails();break;
                    case 'documents': $this->tab_documents(); break;
                }

                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    // =========================================================================
    // Tab: General
    // =========================================================================

    private function tab_general() {
        $access = get_option( 'sie_chat_access', 'logged_in' );
        ?>
        <h2>API Credentials</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_openai_api_key">OpenAI API Key</label></th>
                <td><input type="password" name="sie_openai_api_key" id="sie_openai_api_key"
                           value="<?php echo esc_attr( get_option( 'sie_openai_api_key' ) ); ?>"
                           class="regular-text" autocomplete="off" />
                    <p class="description">Required for embeddings. Also used for chat if OpenAI is selected.</p></td>
            </tr>
            <tr>
                <th><label for="sie_anthropic_api_key">Anthropic API Key</label></th>
                <td><input type="password" name="sie_anthropic_api_key" id="sie_anthropic_api_key"
                           value="<?php echo esc_attr( get_option( 'sie_anthropic_api_key' ) ); ?>"
                           class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="sie_gemini_api_key">Google Gemini API Key</label></th>
                <td><input type="password" name="sie_gemini_api_key" id="sie_gemini_api_key"
                           value="<?php echo esc_attr( get_option( 'sie_gemini_api_key' ) ); ?>"
                           class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="sie_pinecone_api_key">Pinecone API Key</label></th>
                <td><input type="password" name="sie_pinecone_api_key" id="sie_pinecone_api_key"
                           value="<?php echo esc_attr( get_option( 'sie_pinecone_api_key' ) ); ?>"
                           class="regular-text" autocomplete="off" /></td>
            </tr>
            <tr>
                <th><label for="sie_pinecone_host">Pinecone Host</label></th>
                <td><input type="text" name="sie_pinecone_host" id="sie_pinecone_host"
                           value="<?php echo esc_attr( get_option( 'sie_pinecone_host' ) ); ?>"
                           class="regular-text" placeholder="https://your-index.svc.pinecone.io" /></td>
            </tr>
            <tr>
                <th><label for="sie_pinecone_index">Pinecone Index Name</label></th>
                <td><input type="text" name="sie_pinecone_index" id="sie_pinecone_index"
                           value="<?php echo esc_attr( get_option( 'sie_pinecone_index' ) ); ?>"
                           class="regular-text" /></td>
            </tr>
        </table>

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

        <h2>Appearance</h2>
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
                           class="regular-text" />
                    <p class="description">Subtext below the title. E.g. "Chat with an AI SEO expert".</p></td>
            </tr>
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

        <h2>System Prompt</h2>
        <table class="form-table" role="presentation">
            <tr>
                <th><label for="sie_system_prompt">System prompt</label></th>
                <td><textarea name="sie_system_prompt" id="sie_system_prompt"
                              rows="5" class="large-text"><?php
                    echo esc_textarea( get_option( 'sie_system_prompt' ) );
                ?></textarea>
                <p class="description">Instructions sent to the LLM with every query. Controls tone, behavior, and citation style.</p></td>
            </tr>
        </table>

        <hr>
        <h2>Shortcodes</h2>
        <p><code>[sie_chat_page]</code> — full-page search-style chat interface. Widget bubble auto-hides on these pages.</p>
        <p>The floating chat bubble appears site-wide automatically.</p>
        <p><strong>Topic mapping endpoint:</strong>
            <code><?php echo esc_url( rest_url( 'sie/v1/topics' ) ); ?></code></p>

        <script>
        document.getElementById('sie_chat_access').addEventListener('change', function () {
            document.getElementById('sie_role_row').style.display = this.value === 'role' ? '' : 'none';
        });
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
                    else :
                        echo '<p class="description">No additional public post types found.</p>';
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
            <tr>
                <th><label for="sie_github_token">GitHub Token</label></th>
                <td><input type="password" name="sie_github_token" id="sie_github_token"
                           value="<?php echo esc_attr( get_option( 'sie_github_token' ) ); ?>"
                           class="regular-text" autocomplete="off" />
                    <p class="description">Personal access token with <code>repo</code> and <code>workflow</code> scopes. Used to dispatch sync workflows.</p></td>
            </tr>
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
        $token = get_option( 'sie_github_token' );
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
