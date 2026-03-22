<?php
/**
 * Plugin Name: SIE WordPress Plugin
 * Plugin URI:  https://github.com/adambernard55/sie-engine
 * Description: Strategic Intelligence Engine — topic discovery API, AI chat with multi-provider support, evaluation logging, and related content widgets.
 * Version:     1.3.0
 * Author:      Adam Bernard
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SIE_VERSION',    '1.3.0' );
define( 'SIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

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
} );

// On activation: create log table, register CPTs, flush rewrite rules.
register_activation_hook( __FILE__, function () {
    SIE_Chat_Log::create_table();
    ( new SIE_CPT() )->register_post_types();
    ( new SIE_CPT() )->register_taxonomies();
    ( new SIE_Permalink() )->init();
    flush_rewrite_rules();
} );
