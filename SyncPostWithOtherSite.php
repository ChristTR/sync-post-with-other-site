<?php
/*
Plugin Name: Sync Post With Other Site
Plugin URI: https://kp4coder.com/
Description: Allows user to sync post with multiple websites via WP REST API.
Version: 1.9
Author: kp4coder
Author URI: https://kp4coder.com/
Domain Path: /languages
Text Domain: sps_text_domain
License: GPL2 or later 
*/

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Definições de diretórios e URLs
define( 'SPS_PLUGIN', '/sync-post-with-other-site/' );
define( 'SPS_PLUGIN_DIR', WP_PLUGIN_DIR . SPS_PLUGIN );
define( 'SPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPS_INCLUDES_DIR', SPS_PLUGIN_DIR . 'includes/' );
define( 'SPS_ASSETS_URL', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'SPS_JS_URL', SPS_ASSETS_URL . 'js/' );
define( 'SPS_CSS_URL', SPS_ASSETS_URL . 'css/' );
define( 'SPS_txt_domain', 'sps_text_domain' );


// Versão do plugin
global $sps_version;
$sps_version = '1.9';

class SyncPostWithOtherSite {

    private $sps_setting = 'sps_setting';

    function __construct() {
        register_activation_hook(   __FILE__, [ $this, 'sps_install' ] );
        register_deactivation_hook( __FILE__, [ $this, 'sps_deactivation' ] );
        add_action( 'admin_menu',          [ $this, 'sps_add_menu' ] );
        add_action( 'admin_enqueue_scripts',[ $this, 'sps_enqueue_scripts' ] );
        add_action( 'wp_enqueue_scripts',   [ $this, 'sps_front_enqueue_scripts' ] );
        add_action( 'plugins_loaded',       [ $this, 'sps_load_textdomain' ] );
        // **Registro do endpoint REST**
        add_action( 'rest_api_init',        [ $this, 'register_sync_endpoint' ] );
    }

    function sps_load_textdomain() {
        load_plugin_textdomain( SPS_txt_domain, false, basename( __DIR__ ) . '/languages' );
    }

    static function sps_install() {
        update_option( 'sps_plugin', true );
        update_option( 'sps_version', get_plugin_data( __FILE__ )['Version'] );
    }

    static function sps_deactivation() {
        // Rotinas de desativação (opcional)
    }

    // Cria menu no Admin
    function sps_add_menu() {
        add_menu_page(
            __('Sync Post', SPS_txt_domain),
            __('Sync Post', SPS_txt_domain),
            'manage_options',
            $this->sps_setting,
            [ $this, 'sps_route' ],
            'dashicons-update-alt',
            11
        );
        add_submenu_page(
            $this->sps_setting,
            __('Settings', SPS_txt_domain),
            __('Settings', SPS_txt_domain),
            'manage_options',
            $this->sps_setting,
            [ $this, 'sps_route' ]
        );
    }

    function sps_route() {
        if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] === $this->sps_setting ) {
            if ( file_exists( SPS_INCLUDES_DIR . 'sps_settings.class.php' ) ) {
                include_once SPS_INCLUDES_DIR . 'sps_settings.class.php';
                $settings = new SPS_Settings();
                $settings->sps_display_settings();
            }
        }
    }

    function sps_enqueue_scripts() {
        global $sps_version;
        if ( isset( $_REQUEST['page'] ) && $_REQUEST['page'] === $this->sps_setting ) {
            wp_enqueue_style( 'sps_admin', SPS_CSS_URL . 'sps_admin_style.css', [], $sps_version );
            wp_enqueue_script( 'sps_admin', SPS_JS_URL . 'sps_admin_js.js', [ 'jquery' ], $sps_version, true );
        }
    }

    function sps_front_enqueue_scripts() {
        global $sps_version;
        wp_enqueue_style( 'sps_front', SPS_CSS_URL . 'sps_front_style.css', [], $sps_version );
        wp_enqueue_script( 'sps_front', SPS_JS_URL . 'sps_front_js.js', [ 'jquery' ], $sps_version, true );
        echo "<script>var ajaxurl = '" . admin_url( 'admin-ajax.php' ) . "';</script>";
    }

    /**
     * Registra o endpoint REST /wp-json/sync/v1/post
     */
public function register_sync_endpoint() {
    register_rest_route(
        'sync/v1',
        '/post',
        [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ $this, 'handle_remote_post' ],
            'permission_callback' => function( $request ) {
                return current_user_can( 'edit_posts' );
            },
        ]
    );

    // Log personalizado usando a função do plugin
    $this->sps_write_log('Endpoint /wp-json/sync/v1/post registrado com sucesso');
}

    /**
     * Callback para processar criação de post remoto
     */
    public function handle_remote_post( WP_REST_Request $request ) {
        $data = $request->get_json_params();
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field( $data['title'] ),
            'post_content' => wp_kses_post( $data['content'] ),
            'post_status'  => 'publish',
            'post_author'  => intval( $data['author'] ),
        ]);

        if ( is_wp_error( $post_id ) ) {
            return new WP_Error( 'post_failed', 'Falha ao criar post', [ 'status' => 500 ] );
        }

        return rest_ensure_response( [ 'id' => $post_id ] );
    }

    /**
     * Exemplo de rotina para enviar post a outro site via REST
     */
    public function send_post_to_remote( $payload, $remote_url, $username, $app_password ) {
        $endpoint = rtrim( $remote_url, '/' ) . '/wp-json/sync/v1/post';
        $response = wp_remote_request( $endpoint, [
            'method'    => 'POST',
            'headers'   => [
                'Authorization' => 'Basic ' . base64_encode( "{$username}:{$app_password}" ),
                'Content-Type'  => 'application/json',
            ],
            'body'      => wp_json_encode( $payload ),
        ]); // :contentReference[oaicite:4]{index=4}:contentReference[oaicite:5]{index=5}

        if ( is_wp_error( $response ) ) {
            $this->sps_write_log( $response->get_error_message(), 'error_log.txt' );
            return false;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code !== 201 && $code !== 200 ) {
            $this->sps_write_log( wp_remote_retrieve_body( $response ), 'error_log.txt' );
            return false;
        }

        return json_decode( wp_remote_retrieve_body( $response ), true );
    }
}

function sps_write_log( $content = '', $file_name = 'sps_log.txt' ) {
    $upload_dir = wp_upload_dir();
    $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sps-logs/';

    if ( ! file_exists( $log_dir ) ) {
        wp_mkdir_p( $log_dir );
    }

    $log_file = $log_dir . $file_name;
    $log_line = date( 'Y-m-d H:i:s' ) . ' - ' . $content . "\n";

    file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX );
}
// Inicialização
new SyncPostWithOtherSite();
