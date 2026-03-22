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
    ];

    const TABS = [
        'general'   => 'General',
        'models'    => 'Models',
        'guardrails'=> 'Guardrails',
        'documents' => 'Documents',
    ];

    public function init() {
        add_action( 'admin_menu', [ $this, 'add_menu' ] );
        add_action( 'admin_init', [ $this, 'register_settings' ] );
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
        <p>Select post types to connect to the SIE ecosystem. Connected types get the <strong>SIE Topics</strong> taxonomy attached and become eligible for chat indexing via Pinecone. SIE's own types (Knowledge Base, FAQ, Insight, Guide) are always included.</p>
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
        <table class="form-table" role="presentation">
            <tr>
                <th>Knowledge Topics</th>
                <td>
                    <p>Configure path patterns for automatic topic assignment during sync.</p>
                    <p><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=knowledge_topic&post_type=knowledge_base' ) ); ?>"
                          class="button">Manage Knowledge Topics</a></p>
                    <p class="description" style="margin-top:8px;">
                        Edit each term to set its <strong>KB Path Pattern</strong> (e.g. <code>/AI/0_fundamentals/</code>).
                        The sync pipeline uses these patterns to auto-assign topics to imported articles.
                    </p>
                </td>
            </tr>
            <tr>
                <th>SIE Topics</th>
                <td>
                    <p>Shared taxonomy across FAQ, Insight, Guide, and connected post types.</p>
                    <p><a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=sie_topic' ) ); ?>"
                          class="button">Manage SIE Topics</a></p>
                </td>
            </tr>
            <tr>
                <th>Topic API Endpoint</th>
                <td>
                    <code><?php echo esc_url( rest_url( 'sie/v1/topics' ) ); ?></code>
                    <p class="description">Used by kb_sync.py for dynamic topic mapping during automated sync.</p>
                </td>
            </tr>
        </table>
        <?php
    }
}
