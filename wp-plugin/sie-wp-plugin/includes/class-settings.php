<?php
/**
 * SIE Settings Page  (Settings → SIE)
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class SIE_Settings {

    private const OPTIONS = [
        'sie_openai_api_key'   => '',
        'sie_pinecone_api_key' => '',
        'sie_pinecone_host'    => '',
        'sie_pinecone_index'   => '',
        'sie_chat_access'      => 'logged_in',
        'sie_chat_role'        => 'subscriber',
        'sie_chat_title'       => 'Ask the Knowledge Base',
        'sie_system_prompt'    => 'You are a knowledgeable assistant. Answer based only on the provided context. If the context does not contain the answer, say so clearly. Cite source URLs when referencing specific information.',
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
    }

    public function render_page() {
        if ( ! current_user_can( 'manage_options' ) ) return;
        $access = get_option( 'sie_chat_access', 'logged_in' );
        ?>
        <div class="wrap">
            <h1>SIE Settings</h1>
            <form method="post" action="options.php">
                <?php settings_fields( 'sie_settings_group' ); ?>

                <h2>API Credentials</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th><label for="sie_openai_api_key">OpenAI API Key</label></th>
                        <td><input type="password" name="sie_openai_api_key" id="sie_openai_api_key"
                                   value="<?php echo esc_attr( get_option( 'sie_openai_api_key' ) ); ?>"
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
                <a href="<?php echo esc_url( admin_url( 'edit-tags.php?taxonomy=knowledge_topics&post_type=knowledge' ) ); ?>">
                    Knowledge Topics</a> and edit each term to set its KB Path Pattern.</p>
        </div>

        <script>
        document.getElementById('sie_chat_access').addEventListener('change', function () {
            document.getElementById('sie_role_row').style.display = this.value === 'role' ? '' : 'none';
        });
        </script>
        <?php
    }
}
