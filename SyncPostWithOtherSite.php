<?php
/*
Plugin Name: Sync Post With Other Site v2
Plugin URI: https://kp4coder.com/
Description: Versão 2 - Sincronização segura de posts entre sites WordPress via REST API
Version: 2.0.2
Author: kp4coder
Author URI: https://kp4coder.com/
Text Domain: spsv2
License: GPLv2 or later
*/

defined('ABSPATH') || exit;

// =================== CONSTANTES DO PLUGIN =================== //
define('SPSV2_VERSION', '2.0.2');
define('SPSV2_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SPSV2_INCLUDES_DIR', SPSV2_PLUGIN_DIR . 'includes/');
define('SPSV2_BASENAME', plugin_basename(__FILE__));

class SyncPostWithOtherSiteV2 {

    private $settings;
    
    public function __construct() {
        // Registra hooks de ativação/desativação
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        // Inicializa funcionalidades
        add_action('plugins_loaded', [$this, 'init_plugin']);
        add_action('admin_init', [$this, 'check_ssl']);
    }

    // =================== ATIVAÇÃO/DESATIVAÇÃO =================== //
    
    public function activate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        // Cria opções iniciais
        add_option('spsv2_settings', [
            'hosts' => [],
            'security' => [
                'force_ssl' => true,
                'min_tls' => '1.2'
            ]
        ]);
        
        // Agenda limpeza de logs
        if (!wp_next_scheduled('spsv2_daily_cleanup')) {
            wp_schedule_event(time(), 'daily', 'spsv2_daily_cleanup');
        }
    }

    public function deactivate() {
        if (!current_user_can('activate_plugins')) {
            return;
        }
        
        // Remove agendamentos
        wp_clear_scheduled_hook('spsv2_daily_cleanup');
    }

    // =================== INICIALIZAÇÃO PRINCIPAL =================== //
    
    public function init_plugin() {
        // Carrega traduções
        load_plugin_textdomain(
            'spsv2',
            false,
            dirname(SPSV2_BASENAME) . '/languages/'
        );

        // Carrega dependências
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_settings.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_sync.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_logger.class.php');
        require_once(SPSV2_INCLUDES_DIR . 'spsv2_post_meta.class.php');

        // Inicializa módulos
        $this->settings = new SPSv2_Settings();
        new SPSv2_Sync();
        new SPSv2_Post_Meta();
        SPSv2_Logger::get_instance();

        // Registra hooks
        $this->register_admin_hooks();
        $this->register_security_hooks();
    }

    // =================== SEGURANÇA =================== //
    
    public function check_ssl() {
        $settings = get_option('spsv2_settings');
        
        if ($settings['security']['force_ssl'] && !is_ssl()) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>';
                _e('O Sync Post v2 requer conexão HTTPS para funcionar com segurança!', 'spsv2');
                echo '</p></div>';
            });
        }
    }

    private function register_security_hooks() {
        add_filter('https_ssl_verify', '__return_true');
        add_filter('https_local_ssl_verify', '__return_true');
        
        // Força TLS 1.2+
        add_action('http_api_curl', function($handle) {
            curl_setopt($handle, CURLOPT_SSLVERSION, 6); // CURL_SSLVERSION_TLSv1_2
            curl_setopt($handle, CURLOPT_SSL_CIPHER_LIST, 'ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256');
        });
    }

    // =================== ADMIN =================== //
    
    private function register_admin_hooks() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);
    }

    public function add_admin_menu() {
        add_menu_page(
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
        if (strpos($hook, 'spsv2-settings') === false) {
            return;
        }

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

        wp_set_script_translations('spsv2-admin-js', 'spsv2');
    }
}

// Inicializa o plugin
global $spsv2_core;
$spsv2_core = new SyncPostWithOtherSiteV2();

// =================== HOOKS DE LIMPEZA =================== //
add_action('spsv2_daily_cleanup', function() {
    $logger = SPSv2_Logger::get_instance();
    $logger::clean_old_logs(30); // Mantém logs por 30 dias
});

// =================== VERIFICAÇÃO DE SSL =================== //
add_action('wp_loaded', function() {
    $settings = get_option('spsv2_settings');
    
    if ($settings['security']['force_ssl'] && !is_ssl()) {
        wp_die(__('Este plugin requer HTTPS para funcionar com segurança. Por favor, migre seu site para HTTPS.', 'spsv2'));
    }
});
