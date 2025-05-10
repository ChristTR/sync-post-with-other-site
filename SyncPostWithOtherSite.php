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
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('plugins_loaded', [$this, 'init_plugin']);
        add_action('admin_init', [$this, 'check_ssl']);
    }

    // =================== ATIVAÇÃO/DESATIVAÇÃO =================== //
    
    public function activate() {
        try {
            if (!current_user_can('activate_plugins')) {
                throw new Exception(__('Permissão insuficiente para ativar o plugin', 'spsv2'));
            }

            update_option('spsv2_settings', [
                'hosts' => [],
                'security' => [
                    'force_ssl' => true,
                    'min_tls' => '1.2'
                ]
            ]);

            if (!wp_next_scheduled('spsv2_daily_cleanup')) {
                $scheduled = wp_schedule_event(time(), 'daily', 'spsv2_daily_cleanup');
                
                if ($scheduled === false) {
                    throw new Exception(__('Falha ao agendar tarefas recorrentes', 'spsv2'));
                }
            }
            
        } catch (Exception $e) {
            $this->handle_activation_error($e);
        }
    }

    public function deactivate() {
        try {
            if (!current_user_can('activate_plugins')) {
                return;
            }
            
            wp_clear_scheduled_hook('spsv2_daily_cleanup');
            
        } catch (Exception $e) {
            error_log('SYNC POST v2 DEACTIVATION ERROR: ' . $e->getMessage());
        }
    }

    // =================== INICIALIZAÇÃO PRINCIPAL =================== //
    
    public function init_plugin() {
        try {
            $this->load_textdomain();
            $this->load_dependencies();
            $this->initialize_modules();
            $this->register_hooks();
            
        } catch (Exception $e) {
            $this->handle_plugin_error($e);
        }
    }

    private function load_textdomain() {
        load_plugin_textdomain(
            'spsv2',
            false,
            dirname(SPSV2_BASENAME) . '/languages/'
        );
    }

    private function load_dependencies() {
        $files = [
            'spsv2_settings.class.php',
            'spsv2_sync.class.php',
            'spsv2_logger.class.php',
            'spsv2_post_meta.class.php'
        ];
        
        foreach ($files as $file) {
            $path = SPSV2_INCLUDES_DIR . $file;
            if (!file_exists($path)) {
                throw new Exception(sprintf(__('Arquivo essencial não encontrado: %s', 'spsv2'), $file));
            }
            require_once($path);
        }
    }

    private function initialize_modules() {
        $this->settings = new SPSv2_Settings();
        new SPSv2_Sync();
        new SPSv2_Post_Meta();
        SPSv2_Logger::get_instance();
    }

    private function register_hooks() {
        $this->register_admin_hooks();
        $this->register_security_hooks();
    }

    // =================== SEGURANÇA =================== //
    
    public function check_ssl() {
        try {
            $settings = get_option('spsv2_settings', []);
            
            if (!empty($settings['security']['force_ssl']) && !is_ssl()) {
                throw new Exception(__('HTTPS não detectado', 'spsv2'));
            }
            
        } catch (Exception $e) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    __('Erro de segurança: %s', 'spsv2'),
                    esc_html($e->getMessage())
                );
                echo '</p></div>';
            });
        }
    }

    private function register_security_hooks() {
        add_filter('https_ssl_verify', '__return_true');
        add_filter('https_local_ssl_verify', '__return_true');
        
        add_action('http_api_curl', function($handle) {
            curl_setopt($handle, CURLOPT_SSLVERSION, 6);
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

    // =================== TRATAMENTO DE ERROS =================== //
    
    private function handle_plugin_error(Exception $e) {
        // Log detalhado
        if (class_exists('SPSv2_Logger')) {
            SPSv2_Logger::log(
                sprintf(__('ERRO CRÍTICO: %s - Linha: %d', 'spsv2'), 
                    $e->getMessage(), 
                    $e->getLine()
                ),
                'critical',
                ['trace' => $e->getTraceAsString()]
            );
        }

        // Exibição controlada
        add_action('admin_notices', function() use ($e) {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-error"><p>';
                printf(
                    __('Erro no Sync Post v2: %s. Consulte os logs para detalhes.', 'spsv2'),
                    esc_html($e->getMessage())
                );
                echo '</p></div>';
            }
        });

        // Desativa funcionalidades críticas
        remove_action('admin_menu', [$this, 'add_admin_menu']);
        remove_action('save_post', ['SPSv2_Sync', 'synchronize_post']);
    }

    private function handle_activation_error(Exception $e) {
        error_log('SYNC POST v2 ACTIVATION ERROR: ' . $e->getMessage());
        deactivate_plugins(SPSV2_BASENAME);
        
        wp_die(sprintf(
            __('<h1>Ativação falhou</h1><p>%s</p><p>O plugin foi desativado automaticamente.</p><a href="%s">Voltar</a>', 'spsv2'),
            esc_html($e->getMessage()),
            esc_url(admin_url('plugins.php'))
        ));
    }
}

// =================== INICIALIZAÇÃO E HOOKS GLOBAIS =================== //
global $spsv2_core;
$spsv2_core = new SyncPostWithOtherSiteV2();

add_action('spsv2_daily_cleanup', function() {
    try {
        $logger = SPSv2_Logger::get_instance();
        $logger::clean_old_logs(30);
    } catch (Exception $e) {
        error_log('SYNC POST CLEANUP ERROR: ' . $e->getMessage());
    }
});

add_action('wp_loaded', function() {
    try {
        $settings = get_option('spsv2_settings', []);
        
        if (!empty($settings['security']['force_ssl']) && !is_ssl()) {
            wp_die(
                __('Este plugin requer HTTPS para funcionar com segurança. Por favor, migre seu site para HTTPS.', 'spsv2'),
                __('Erro de Segurança', 'spsv2'),
                ['response' => 403]
            );
        }
        
    } catch (Exception $e) {
        error_log('SYNC POST SSL CHECK ERROR: ' . $e->getMessage());
    }
});
