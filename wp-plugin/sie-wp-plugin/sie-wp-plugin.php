<?php
/**
 * Plugin Name: SIE WordPress Plugin
 * Plugin URI:  https://github.com/adambernard55/sie-engine
 * Description: Strategic Intelligence Engine — topic discovery API and AI chat widget.
 * Version:     1.0.0
 * Author:      Adam Bernard
 * License:     MIT
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'SIE_VERSION',    '1.0.0' );
define( 'SIE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

require_once SIE_PLUGIN_DIR . 'includes/class-cpt.php';
require_once SIE_PLUGIN_DIR . 'includes/class-topic-api.php';
require_once SIE_PLUGIN_DIR . 'includes/class-chat-api.php';
require_once SIE_PLUGIN_DIR . 'includes/class-settings.php';
require_once SIE_PLUGIN_DIR . 'includes/class-permalink.php';

add_action( 'plugins_loaded', function () {
    ( new SIE_CPT() )->init();
    ( new SIE_Topic_API() )->init();
    ( new SIE_Chat_API() )->init();
    ( new SIE_Settings() )->init();
    ( new SIE_Permalink() )->init();
} );

// Flush rewrite rules on activation so new permalink structure takes effect.
register_activation_hook( __FILE__, function () {
    ( new SIE_Permalink() )->init();
    flush_rewrite_rules();
} );
