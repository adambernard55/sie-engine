<?php
/**
 * SIE Settings Page  (Settings → SIE)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Settings {

    private const OPTIONS = [
        // API keys
        'sie_openai_api_key'     => '',
        'sie_anthropic_api_key'  => '',
        'sie_gemini_api_key'     => '',
        'sie_pinecone_api_key'   => '',
        'sie_pinecone_host'      => '',
        'sie_pinecone_index'     => '',
        // Model
        'sie_llm_provider'       => 'openai',
        'sie_openai_model'       => 'gpt-4o-mini',
        'sie_anthropic_model'    => 'claude-sonnet-4-5-20250514',
        'sie_gemini_model'       => 'gemini-2.5-flash',
        'sie_temperature'        => '0.2',
        // Chat widget
        'sie_chat_access'        => 'logged_in',
        'sie_chat_role'          => 'subscriber',
        'sie_chat_title'         => 'Ask the Knowledge Base',
        'sie_system_prompt'      => 'You are a knowledgeable assistant. Answer based only on the provided context. If the context does not contain the answer, say so clearly. Cite source URLs when referencing specific information.',
        // Guardrails
        'sie_confidence_threshold' => '0.6',
        'sie_low_confidence_msg'   => 'I don\'t have enough information in the knowledge base to answer that confidently. Please try rephrasing or contact us directly.',
        'sie_enable_logging'       => '1',
        'sie_daily_query_limit'    => '0',
    ];

    public function init() {
        add_action( 'admin_menu',  [ $this, 'add_menu'           ] );
        add_action( 'admin_init',  [ $this, 'register_settings'  ] );
    }

    public function add_menu() {
        add_options_page(
            'SIE Settings',
            'SIE',
            'manage_options',
            'sie-settings',
            [ $this, 'render_page' ]
        );
    }

    public function register_settings() {
        foreach ( self::OPTIONS as $key => $default ) {
            register_setting( 'sie_settings_group', $key, [ 'default' => $default ] );
        }
        // Array option — connected CPTs
        register_setting( 'sie_settings_group', 'sie_connected_cpts', [
            'default'           => [],
            'sanitize_callback' => function ( $value ) {
                return is_array( $value ) ? array_map( 'sanitize_key', $value ) : [];
            },
        ] );
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $access   = get_option( 'sie_chat_access', 'logged_in' );
        $provider = get_option( 'sie_llm_provider', 'openai' );
        ?>
        <div class="wrap">
            <h1>SIE Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'sie_settings_group' ); ?>

                <!-- ==================== API Credentials ==================== -->
                <h2>API Credentials</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="sie_openai_api_key">OpenAI API Key</label></th>
                        <td><input type="password" name="sie_openai_api_key" id="sie_openai_api_key"
                                   value="<?php echo esc_attr( get_option( 'sie_openai_api_key' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">Used for embeddings and chat completions (if OpenAI selected).</p></td>
                    </tr>
                    <tr>
                        <th><label for="sie_anthropic_api_key">Anthropic API Key</label></th>
                        <td><input type="password" name="sie_anthropic_api_key" id="sie_anthropic_api_key"
                                   value="<?php echo esc_attr( get_option( 'sie_anthropic_api_key' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">Used for chat completions when Anthropic is selected. Embeddings still use OpenAI.</p></td>
                    </tr>
                    <tr>
                        <th><label for="sie_gemini_api_key">Google Gemini API Key</label></th>
                        <td><input type="password" name="sie_gemini_api_key" id="sie_gemini_api_key"
                                   value="<?php echo esc_attr( get_option( 'sie_gemini_api_key' ) ); ?>"
                                   class="regular-text" autocomplete="off" />
                            <p class="description">Used for chat completions when Gemini is selected. Embeddings still use OpenAI.</p></td>
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
                                   class="regular-text"
                                   placeholder="https://your-index.svc.pinecone.io" /></td>
                    </tr>
                    <tr>
                        <th><label for="sie_pinecone_index">Pinecone Index Name</label></th>
                        <td><input type="text" name="sie_pinecone_index" id="sie_pinecone_index"
                                   value="<?php echo esc_attr( get_option( 'sie_pinecone_index' ) ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                </table>

                <!-- ==================== Model Configuration ==================== -->
                <h2>Model Configuration</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="sie_llm_provider">LLM Provider</label></th>
                        <td>
                            <select name="sie_llm_provider" id="sie_llm_provider">
                                <option value="openai"    <?php selected( $provider, 'openai'    ); ?>>OpenAI</option>
                                <option value="anthropic" <?php selected( $provider, 'anthropic' ); ?>>Anthropic (Claude)</option>
                                <option value="gemini"    <?php selected( $provider, 'gemini'    ); ?>>Google (Gemini)</option>
                            </select>
                        </td>
                    </tr>
                    <tr id="sie_openai_model_row" <?php echo $provider !== 'openai' ? 'style="display:none"' : ''; ?>>
                        <th><label for="sie_openai_model">OpenAI Model</label></th>
                        <td>
                            <?php $omodel = get_option( 'sie_openai_model', 'gpt-4o-mini' ); ?>
                            <select name="sie_openai_model" id="sie_openai_model">
                                <option value="gpt-4o-mini" <?php selected( $omodel, 'gpt-4o-mini' ); ?>>GPT-4o Mini (fast, low cost)</option>
                                <option value="gpt-4o"      <?php selected( $omodel, 'gpt-4o'      ); ?>>GPT-4o (balanced)</option>
                                <option value="gpt-4.1"     <?php selected( $omodel, 'gpt-4.1'     ); ?>>GPT-4.1 (latest)</option>
                                <option value="gpt-4.1-mini" <?php selected( $omodel, 'gpt-4.1-mini' ); ?>>GPT-4.1 Mini (latest, low cost)</option>
                                <option value="o3-mini"     <?php selected( $omodel, 'o3-mini'     ); ?>>o3-mini (reasoning)</option>
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
                                <option value="gemini-2.5-flash"   <?php selected( $gmodel, 'gemini-2.5-flash'   ); ?>>Gemini 2.5 Flash (fast, low cost)</option>
                                <option value="gemini-2.5-pro"     <?php selected( $gmodel, 'gemini-2.5-pro'     ); ?>>Gemini 2.5 Pro (balanced)</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sie_temperature">Temperature</label></th>
                        <td>
                            <input type="range" name="sie_temperature" id="sie_temperature"
                                   min="0" max="1" step="0.1"
                                   value="<?php echo esc_attr( get_option( 'sie_temperature', '0.2' ) ); ?>" />
                            <span id="sie_temp_value"><?php echo esc_html( get_option( 'sie_temperature', '0.2' ) ); ?></span>
                            <p class="description">Lower = more factual/consistent. Higher = more creative. Recommended: 0.1–0.3 for enterprise.</p>
                        </td>
                    </tr>
                </table>

                <!-- ==================== Connected Post Types ==================== -->
                <h2>Connected Post Types</h2>
                <p>Select additional post types to connect to the SIE ecosystem. Connected types get the <strong>SIE Topics</strong> taxonomy and are eligible for chat indexing. SIE's own types (Knowledge Base, FAQ, Insight, Guide) are always included.</p>
                <table class="form-table" role="presentation">
                    <tr>
                        <th>Post Types</th>
                        <td>
                            <?php
                            $connected  = get_option( 'sie_connected_cpts', [] );
                            if ( ! is_array( $connected ) ) $connected = [];
                            $connectable = SIE_CPT::get_connectable_cpts();
                            if ( $connectable ) :
                                foreach ( $connectable as $slug => $label ) :
                            ?>
                                <label style="display:block;margin-bottom:6px;">
                                    <input type="checkbox" name="sie_connected_cpts[]"
                                           value="<?php echo esc_attr( $slug ); ?>"
                                           <?php checked( in_array( $slug, $connected, true ) ); ?> />
                                    <?php echo esc_html( $label ); ?>
                                    <code style="color:#888;">(<?php echo esc_html( $slug ); ?>)</code>
                                </label>
                            <?php
                                endforeach;
                            else :
                                echo '<p class="description">No additional public post types found.</p>';
                            endif;
                            ?>
                            <p class="description" style="margin-top:10px;">Common types: Posts, Pages, Portfolio, Products (WooCommerce).</p>
                        </td>
                    </tr>
                </table>

                <!-- ==================== Guardrails ==================== -->
                <h2>Guardrails &amp; Evaluation</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="sie_confidence_threshold">Confidence Threshold</label></th>
                        <td>
                            <input type="range" name="sie_confidence_threshold" id="sie_confidence_threshold"
                                   min="0" max="1" step="0.05"
                                   value="<?php echo esc_attr( get_option( 'sie_confidence_threshold', '0.6' ) ); ?>" />
                            <span id="sie_conf_value"><?php echo esc_html( get_option( 'sie_confidence_threshold', '0.6' ) ); ?></span>
                            <p class="description">Minimum Pinecone similarity score. Below this, the low-confidence message is shown instead of querying the LLM.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="sie_low_confidence_msg">Low-Confidence Message</label></th>
                        <td><textarea name="sie_low_confidence_msg" id="sie_low_confidence_msg"
                                      rows="3" class="large-text"><?php
                            echo esc_textarea( get_option( 'sie_low_confidence_msg', self::OPTIONS['sie_low_confidence_msg'] ) );
                        ?></textarea>
                        <p class="description">Shown when no KB content meets the confidence threshold — avoids hallucination.</p></td>
                    </tr>
                    <tr>
                        <th><label for="sie_daily_query_limit">Daily Query Limit</label></th>
                        <td><input type="number" name="sie_daily_query_limit" id="sie_daily_query_limit"
                                   value="<?php echo esc_attr( get_option( 'sie_daily_query_limit', '0' ) ); ?>"
                                   class="small-text" min="0" step="1" />
                            <p class="description">Maximum chat queries per day across all users. 0 = unlimited. Protects against runaway API costs.</p></td>
                    </tr>
                    <tr>
                        <th><label for="sie_enable_logging">Chat Logging</label></th>
                        <td>
                            <label>
                                <input type="checkbox" name="sie_enable_logging" id="sie_enable_logging" value="1"
                                       <?php checked( get_option( 'sie_enable_logging', '1' ), '1' ); ?> />
                                Log all chat queries, responses, and sources for evaluation
                            </label>
                            <p class="description">Logs are stored in the database and viewable under Tools → SIE Chat Log.</p>
                        </td>
                    </tr>
                </table>

                <!-- ==================== Chat Widget ==================== -->
                <h2>Chat Widget</h2>
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
                    <tr>
                        <th><label for="sie_chat_title">Widget title</label></th>
                        <td><input type="text" name="sie_chat_title" id="sie_chat_title"
                                   value="<?php echo esc_attr( get_option( 'sie_chat_title', 'Ask the Knowledge Base' ) ); ?>"
                                   class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="sie_system_prompt">System prompt</label></th>
                        <td><textarea name="sie_system_prompt" id="sie_system_prompt"
                                      rows="4" class="large-text"><?php
                            echo esc_textarea( get_option( 'sie_system_prompt' ) );
                        ?></textarea></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr>
            <h2>Usage</h2>
            <p><strong>Chat widget:</strong> add <code>[sie_chat]</code> to any page or post.</p>
            <p><strong>Topic mapping endpoint:</strong>
                <code><?php echo esc_url( rest_url( 'sie/v1/topics' ) ); ?></code>
                — used automatically by kb_sync when credentials are configured.</p>
            <p><strong>Topic path patterns:</strong> go to
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=knowledge_topic&post_type=knowledge' ) ); ?>">
                    Knowledge Topics</a> and edit each term to set its KB Path Pattern.</p>
        </div>

        <script>
        // Toggle provider model rows
        document.getElementById('sie_llm_provider').addEventListener('change', function () {
            document.getElementById('sie_openai_model_row').style.display    = this.value === 'openai'    ? '' : 'none';
            document.getElementById('sie_anthropic_model_row').style.display = this.value === 'anthropic' ? '' : 'none';
            document.getElementById('sie_gemini_model_row').style.display    = this.value === 'gemini'    ? '' : 'none';
        });
        // Toggle role row
        document.getElementById('sie_chat_access').addEventListener('change', function () {
            document.getElementById('sie_role_row').style.display = this.value === 'role' ? '' : 'none';
        });
        // Range sliders
        document.getElementById('sie_temperature').addEventListener('input', function () {
            document.getElementById('sie_temp_value').textContent = this.value;
        });
        document.getElementById('sie_confidence_threshold').addEventListener('input', function () {
            document.getElementById('sie_conf_value').textContent = this.value;
        });
        </script>
        <?php
    }
}
