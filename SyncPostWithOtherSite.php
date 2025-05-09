<?php
/*
Plugin Name: Sync Post With Other Site v2
Plugin URI: https://kp4coder.com/
Description: Versão 2 - Sincronização de posts com múltiplos sites + recursos aprimorados
Version: 2.0
Author: kp4coder
Author URI: https://kp4coder.com/
Domain Path: /languages
Text Domain: spsv2_text_domain
License: GPL2 or later 
*/

if (!defined('ABSPATH')) exit;

// Definições da Versão 2
define( 'SPSV2_PLUGIN', '/sync-post-with-other-site-v2/');
define( 'SPSV2_PLUGIN_DIR', WP_PLUGIN_DIR . SPSV2_PLUGIN);
define( 'SPSV2_INCLUDES_DIR', SPSV2_PLUGIN_DIR . 'includes/');
define( 'SPSV2_ASSETS_DIR', SPSV2_PLUGIN_DIR . 'assets/');
define( 'SPSV2_txt_domain', 'spsv2_text_domain' );

class SyncPostWithOtherSiteV2 {

    var $spsv2_setting = 'spsv2_settings';
    
    function __construct() {
        register_activation_hook(__FILE__, array(&$this, 'spsv2_install'));
        register_deactivation_hook(__FILE__, array(&$this, 'spsv2_deactivation'));
        add_action('admin_menu', array($this, 'spsv2_add_menu'));
        add_action('admin_enqueue_scripts', array($this, 'spsv2_enqueue_scripts'));
        add_action('plugins_loaded', array($this, 'spsv2_load_textdomain'));
    }

    function spsv2_load_textdomain() {
        load_plugin_textdomain(SPSV2_txt_domain, false, basename(dirname(__FILE__)) . '/languages');
    }

    static function spsv2_install() {
        update_option("spsv2_plugin", true);
        update_option("spsv2_version", '2.0');
    }

    static function spsv2_deactivation() {
        // Ações de desativação específicas da v2
    }

    function spsv2_add_menu() {
        add_menu_page(
            __('Sync Post v2', SPSV2_txt_domain),
            __('Sync Post v2', SPSV2_txt_domain),
            'manage_options',
            $this->spsv2_setting,
            array(&$this, 'spsv2_route'),
            'dashicons-update-alt',
            11
        );
    }

    function spsv2_enqueue_scripts() {
        if ($this->spsv2_is_admin_page()) {
            wp_enqueue_style(
                'spsv2_admin_style',
                plugins_url('assets/css/spsv2_admin_style.css', __FILE__),
                array(),
                '2.0'
            );
            
            wp_enqueue_script(
                'spsv2_admin_js',
                plugins_url('assets/js/spsv2_admin_js.js', __FILE__),
                array('jquery'),
                '2.0',
                true
            );
        }
    }

    function spsv2_is_admin_page() {
        return isset($_GET['page']) && $_GET['page'] === $this->spsv2_setting;
    }

    function spsv2_route() {
        if (file_exists(SPSV2_INCLUDES_DIR . 'spsv2_settings.class.php')) {
            include_once(SPSV2_INCLUDES_DIR . 'spsv2_settings.class.php');
            $settings = new SPSv2_Settings();
            $settings->spsv2_display_settings();
        }
    }
}

// Inicializar a Versão 2
global $spsv2;
$spsv2 = new SyncPostWithOtherSiteV2();

// Carregar módulos da Versão 2
require_once(SPSV2_INCLUDES_DIR . 'spsv2_sync.class.php');
require_once(SPSV2_INCLUDES_DIR . 'spsv2_post_meta.class.php');
require_once(SPSV2_INCLUDES_DIR . 'spsv2_logger.class.php');

new SPSv2_Sync();
new SPSv2_Post_Meta();
