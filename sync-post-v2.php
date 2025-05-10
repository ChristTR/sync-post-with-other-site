<?php
/*
Plugin Name: Sync Post With Other Site v2
Plugin URI: https://example.com/
Description: Sincronização segura entre sites WordPress
Version: 2.8.0
Author: Seu Nome
Text Domain: spsv2
*/

defined('ABSPATH') || exit;

// Carregar dependências
require_once plugin_dir_path(__FILE__) . 'includes/spsv2_settings.class.php';
require_once plugin_dir_path(__FILE__) . 'includes/spsv2_sync.class.php';
require_once plugin_dir_path(__FILE__) . 'includes/spsv2_post_meta.class.php';
require_once plugin_dir_path(__FILE__) . 'includes/spsv2_logger.class.php';
require_once plugin_dir_path(__FILE__) . 'includes/spsv2_settings.view.php';

// Inicialização
register_activation_hook(__FILE__, ['SPSv2_Settings', 'activate']);
register_deactivation_hook(__FILE__, ['SPSv2_Settings', 'deactivate']);

add_action('plugins_loaded', function() {
    SPSv2_Settings::init();
    SPSv2_Sync::init();
    SPSv2_Post_Meta::init();
    SPSv2_Logger::init();
});
