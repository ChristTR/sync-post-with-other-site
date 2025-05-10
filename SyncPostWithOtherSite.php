<?php
/*
Plugin Name: Sync Post With Other Site v2
Plugin URI: https://kp4coder.com/
Description: Sincronização bidirecional segura entre sites WordPress
Version: 2.1.0
Author: kp4coder
Author URI: https://kp4coder.com/
Text Domain: spsv2
License: GPLv2 or later
*/

defined('ABSPATH') || exit;

// =================== CONSTANTES =================== //
define('SPSV2_VERSION', '2.1.0');
define('SPSV2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPSV2_INCLUDES_DIR', SPSV2_PLUGIN_DIR . 'includes/');
define('SPSV2_BASENAME', plugin_basename(__FILE__));

class SyncPostWithOtherSiteV2 {

    private $settings;
    private $admin_hook_suffix;

    public function __construct() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init_plugin']);
        add_action('admin_init', [$this, 'check_ssl']);
    }

    // =================== ATIVAÇÃO =================== //
    public function activate() {
        if (!current_user_can('activate_plugins')) {
            wp_die(__('Permissão insuficiente para ativar o plugin', 'spsv2'));
        }

        update_option('spsv2_settings', [
            'hosts' => [],
            'security' => [
                'force_ssl' => true,
                'min_tls' => '1.2'
            ]
        ]);

        if (!wp_next_scheduled('spsv2_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'spsv2_daily_cleanup');
        }
    }

    // =================== DESATIVAÇÃO =================== //
    public function deactivate() {
        wp_clear_scheduled_hook('spsv2_daily_cleanup');
    }

    // =================== INICIALIZAÇÃO =================== //
    public function init_plugin() {
        $this->load_textdomain();
        $this->load_dependencies();
        $this->initialize_modules();
        $this->register_hooks();
    }

    private function load_textdomain() {
        load_plugin_textdomain(
            'spsv2',
            false,
            dirname(SPSV2_BASENAME) . '/languages/'
        );
    }

    private function load_dependencies() {
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_settings.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_sync.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_logger.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_post_meta.class.php');
    }

    private function initialize_modules() {
        $this->settings = new SPSv2_Settings();
        new SPSv2_Sync();
        new SPSv2_Post_Meta();
        SPSv2_Logger::get_instance();
    }

    // =================== HOOKS =================== //
    private function register_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
        add_action('spsv2_daily_cleanup', [$this, 'daily_cleanup_task']);
    }

    // =================== ADMIN =================== //
    public function add_admin_menu() {
        $this->admin_hook_suffix = add_menu_page(
            __('Sync Post v2', 'spsv2'),
            __('Sync Post v2', 'spsv2'),
            'manage_options',
            'spsv2-settings',
            [$this->settings, 'render_settings_page'],
            'dashicons-rest-api',
            80
        );
    }

    public function enqueue_admin_assets($hook) {
        if ($hook !== $this->admin_hook_suffix) return;

        wp_enqueue_style(
            'spsv2-admin-css',
            plugins_url('assets/css/admin.css', __FILE__),
            [],
            SPSV2_VERSION
        );

        wp_enqueue_script(
            'spsv2-admin-js',
            plugins_url('assets/js/admin.js', __FILE__),
            ['jquery', 'wp-i18n'],
            SPSV2_VERSION,
            true
        );
    }

    // =================== TAREFAS AGENDADAS =================== //
    public function daily_cleanup_task() {
        SPSv2_Logger::get_instance()::clean_old_logs(30);
    }
}

// Inicialização do plugin
global $spsv2_core;
$spsv2_core = new SyncPostWithOtherSiteV2();
