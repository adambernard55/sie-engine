<?php
/**
 * Plugin Name: SIE WordPress Plugin
 * Plugin URI:  https://github.com/adambernard55/sie-engine
 * Description: Strategic Intelligence Engine — topic discovery API, AI chat with multi-provider support, evaluation logging, and related content widgets.
 * Version:     1.4.0
 * Author:      Adam Bernard
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SIE_VERSION',    '1.4.0' );
define( 'SIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/**
 * Get an SIE option with wp-config.php constant override.
 *
 * API keys and sensitive credentials can be defined as PHP constants in
 * wp-config.php to prevent accidental deletion via the admin settings.
 * Constants take priority over database values.
 *
 * Constant naming: option name uppercased → SIE_OPENAI_API_KEY, etc.
 *
 * Usage in wp-config.php:
 *   define( 'SIE_OPENAI_API_KEY',    'sk-...' );
 *   define( 'SIE_ANTHROPIC_API_KEY', 'sk-ant-...' );
 *   define( 'SIE_GEMINI_API_KEY',    'AI...' );
 *   define( 'SIE_PINECONE_API_KEY',  'pcsk_...' );
 *   define( 'SIE_PINECONE_HOST',     'https://...' );
 *   define( 'SIE_PINECONE_INDEX',    'index-name' );
 *   define( 'SIE_GITHUB_TOKEN',      'ghp_...' );
 */
function sie_get_option( string $option, $default = '' ) {
    $const = strtoupper( $option );
    if ( defined( $const ) ) {
        return constant( $const );
    }
    return get_option( $option, $default );
}

/**
 * Check if an SIE option is locked via a wp-config.php constant.
 */
function sie_option_is_locked( string $option ): bool {
    return defined( strtoupper( $option ) );
}

require_once SIE_PLUGIN_DIR . 'includes/class-cpt.php';
require_once SIE_PLUGIN_DIR . 'includes/class-topic-api.php';
require_once SIE_PLUGIN_DIR . 'includes/class-chat-log.php';
require_once SIE_PLUGIN_DIR . 'includes/class-chat-api.php';
require_once SIE_PLUGIN_DIR . 'includes/class-settings.php';
require_once SIE_PLUGIN_DIR . 'includes/class-permalink.php';
require_once SIE_PLUGIN_DIR . 'includes/class-seo-meta.php';
require_once SIE_PLUGIN_DIR . 'includes/class-agents.php';
require_once SIE_PLUGIN_DIR . 'includes/class-related-content.php';

add_action( 'plugins_loaded', function () {
    ( new SIE_CPT() )->init();
    ( new SIE_Topic_API() )->init();
    ( new SIE_Chat_Log() )->init();
    ( new SIE_Chat_API() )->init();
    ( new SIE_Settings() )->init();
    ( new SIE_Permalink() )->init();
    ( new SIE_SEO_Meta() )->init();
    ( new SIE_Agents() )->init();
    ( new SIE_Related_Content() )->init();
    SIE_Related_Content::register_ajax();

    // Flush rewrite rules on version upgrade (SFTP updates skip activation hook)
    $stored = get_option( 'sie_db_version', '' );
    if ( $stored !== SIE_VERSION ) {
        add_action( 'init', function () {
            ( new SIE_CPT() )->register_post_types();
            ( new SIE_CPT() )->register_taxonomies();
            flush_rewrite_rules();
        }, 999 );
        SIE_Chat_Log::create_table();
        update_option( 'sie_db_version', SIE_VERSION );
    }
} );

// On activation: create log table, register CPTs, flush rewrite rules.
register_activation_hook( __FILE__, function () {
    SIE_Chat_Log::create_table();
    ( new SIE_CPT() )->register_post_types();
    ( new SIE_CPT() )->register_taxonomies();
    ( new SIE_Permalink() )->init();
    flush_rewrite_rules();
} );
