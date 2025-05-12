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
define( 'SPS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SPS_INCLUDES_DIR', SPS_PLUGIN_DIR . 'includes/' );
define( 'SPS_ASSETS_URL', plugin_dir_url( __FILE__ ) . 'assets/' );
define( 'SPS_JS_URL', SPS_ASSETS_URL . 'js/' );
define( 'SPS_CSS_URL', SPS_ASSETS_URL . 'css/' );
define( 'SPS_TEXT_DOMAIN', 'sps_text_domain' ); 

// Versão do plugin
global $sps_version;
$sps_version = '1.9';

class SyncPostWithOtherSite {

    public $sps_setting = 'sync-post-with-other-site';

    public function __construct() {
        add_action( 'rest_api_init', [ $this, 'register_sync_endpoint' ] );
        add_action( 'admin_menu', [ $this, 'sps_add_menu' ] ); // Adicionando o menu no painel
        add_action( 'admin_init', [ $this, 'register_settings' ] ); // Registrando configurações
    }

public function sps_is_activate() {
    if ( function_exists( 'is_plugin_active' ) ) {
        return is_plugin_active( plugin_basename( __FILE__ ) );
    }
    return false;  // Retorna false caso a função não exista
}


    public function register_sync_endpoint() {
        register_rest_route( 'sync/v1', '/post', [
            'methods'             => 'POST',
            'callback'            => [ $this, 'handle_remote_post' ],
            'permission_callback' => '__return_true',
        ]);
    }

    /**
     * Adiciona o menu de administração
     */
    public function sps_add_menu() {
        $sps_main_page_name = __('Sync Post', SPS_TEXT_DOMAIN);
        $sps_main_page_capa = 'manage_options';
        $sps_main_page_slug = $this->sps_setting;

        $sps_get_sub_menu   = $this->sps_get_sub_menu();

        add_menu_page(
            $sps_main_page_name, 
            $sps_main_page_name, 
            $sps_main_page_capa, 
            $sps_main_page_slug, 
            [ $this, 'sps_route' ], 
            'dashicons-update-alt', 
            11
        );

        foreach ($sps_get_sub_menu as $sps_menu_key => $sps_menu_value) {
            add_submenu_page(
                $sps_main_page_slug, 
                $sps_menu_value['name'], 
                $sps_menu_value['name'], 
                $sps_menu_value['cap'], 
                $sps_menu_value['slug'], 
                [ $this, 'sps_route' ]
            );    
        }
    }

    /**
     * Retorna o submenu configurado
     */
    public function sps_get_sub_menu() {
        $sps_admin_menu = array(
            array(
                'name' => __('Setting', SPS_TEXT_DOMAIN),
                'cap'  => 'manage_options',
                'slug' => $this->sps_setting,
            ),
        );
        return $sps_admin_menu;
    }

    /**
     * Página de configuração do plugin
     */
    public function sps_route() {
        ?>
        <div class="wrap">
            <h1>Configurações de Sincronização de Posts</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'sps_options_group' );
                do_settings_sections( 'sync-post-with-other-site' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /**
     * Registra as configurações
     */
    public function register_settings() {
        register_setting( 'sps_options_group', 'sps_remote_sites' );

        add_settings_section(
            'sps_section_remote_sites', 
            'Sites Remotos', 
            [ $this, 'section_description' ], 
            'sync-post-with-other-site'
        );

        add_settings_field(
            'sps_field_remote_sites', 
            'URLs dos Sites Remotos', 
            [ $this, 'remote_sites_field' ], 
            'sync-post-with-other-site', 
            'sps_section_remote_sites'
        );
    }

    /**
     * Descrição da seção de sites remotos
     */
    public function section_description() {
        echo 'Aqui você pode configurar os sites remotos para sincronização de posts.';
    }

    /**
     * Campo de entrada para URLs dos sites remotos
     */
    public function remote_sites_field() {
        $sites = get_option( 'sps_remote_sites', [] );
        ?>
        <textarea name="sps_remote_sites" rows="5" cols="50" class="large-text"><?php echo esc_textarea( implode( "\n", $sites ) ); ?></textarea>
        <p>Digite uma URL por linha. Exemplo: https://outrosite.com</p>
        <?php
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
     * Envia um post para outro site via REST API
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
        ]);

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

    /**
     * Escreve logs em arquivo dentro da pasta de uploads
     */
    public function sps_write_log( $content = '', $file_name = 'sps_log.txt' ) {
        $upload_dir = wp_upload_dir();
        $log_dir    = trailingslashit( $upload_dir['basedir'] ) . 'sps-logs/';

        if ( ! file_exists( $log_dir ) ) {
            wp_mkdir_p( $log_dir );
        }

        $log_file = $log_dir . $file_name;
        $log_line = date( 'Y-m-d H:i:s' ) . ' - ' . $content . "\n";

        file_put_contents( $log_file, $log_line, FILE_APPEND | LOCK_EX );
    }

}

// Inicializa o plugin
global $sps;
$sps = new SyncPostWithOtherSite();

if ( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_settings.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_settings.class.php" );
}

if ( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_sync.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_sync.class.php" );
}

if ( $sps->sps_is_activate() && file_exists( SPS_INCLUDES_DIR . "sps_post_meta.class.php" ) ) {
    include_once( SPS_INCLUDES_DIR . "sps_post_meta.class.php" );
}
