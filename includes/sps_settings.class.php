<?php
if ( ! class_exists( 'SPS_Settings' ) ) {

    class SPS_Settings {

        /** Nome da opção onde armazenamos todas as configurações */
        const OPTION_KEY = 'sps_general_options';

        /** Default: estrutura inicial das configurações */
        public function sps_default_setting_option() {
            return [
                'remote_sites'       => [],    // array de arrays: [ ['url'=>'','label'=>'','username'=>'','app_password'=>''], ... ]
                'strict_mode'        => '1',
                'content_match'      => 'title',
                'roles_allowed'      => [ 'administrator','editor','author' ],
            ];
        }

        /** Construtor: hooks */
        public function __construct() {
            // Quando salvar via AJAX ou form padrão
            add_action( 'admin_post_sps_save_settings', [ $this, 'sps_save_settings_func' ] );
        }

        /** Exibe a página de opções (view) */
        public function sps_display_settings() {
            $options = get_option( self::OPTION_KEY, $this->sps_default_setting_option() );
            include_once SPS_INCLUDES_DIR . 'sps_settings.view.php';
        }

        /**
         * Processa o POST de salvamento das configurações.
         * Hook: admin_post_sps_save_settings
         */
        public function sps_save_settings_func() {
            // Verifica nonce
            if ( empty( $_POST['sps_nonce'] ) || ! wp_verify_nonce( $_POST['sps_nonce'], 'sps_save_settings' ) ) {
                wp_die( __( 'Nonce verification failed', SPS_txt_domain ) );
            }

            // Permissões
            if ( ! current_user_can( 'manage_options' ) ) {
                wp_die( __( 'Insufficient permissions', SPS_txt_domain ) );
            }

            // Carrega default e sobrescreve com POST
            $opts = $this->sps_default_setting_option();

            // Captura os campos de sites remotos
            $sites = [];
            if ( isset( $_POST['remote_site_url'], $_POST['remote_site_label'], $_POST['remote_site_user'], $_POST['remote_site_pass'] ) ) {
                $urls      = array_map( 'esc_url_raw',   wp_unslash( $_POST['remote_site_url'] ) );
                $labels    = array_map( 'sanitize_text_field', wp_unslash( $_POST['remote_site_label'] ) );
                $users     = array_map( 'sanitize_user',  wp_unslash( $_POST['remote_site_user'] ) );
                $passwords = array_map( 'sanitize_text_field', wp_unslash( $_POST['remote_site_pass'] ) );

                foreach ( $urls as $i => $url ) {
                    if ( empty( $url ) ) {
                        continue;
                    }
                    $sites[] = [
                        'url'          => $url,
                        'label'        => $labels[ $i ] ?? $url,
                        'username'     => $users[ $i ] ?? '',
                        'app_password' => $passwords[ $i ] ?? '',
                    ];
                }
            }
            $opts['remote_sites'] = $sites;

            // Outros campos
            $opts['strict_mode']   = isset( $_POST['sps_strict_mode'] ) ? '1' : '0';
            $opts['content_match'] = sanitize_text_field( $_POST['sps_content_match'] ?? $opts['content_match'] );
            $opts['roles_allowed'] = array_map( 'sanitize_key', (array) ( $_POST['sps_roles_allowed'] ?? $opts['roles_allowed'] ) );

            // Salva no banco
            update_option( self::OPTION_KEY, $opts );

            // Redireciona de volta com status
            wp_redirect( add_query_arg( 'updated', 'true', wp_get_referer() ) );
            exit;
        }

        /**
         * Retorna um array $key => ['url','label','username','app_password']
         * para construir o metabox.
         */
        public function get_remote_sites() {
            $opts = get_option( self::OPTION_KEY, $this->sps_default_setting_option() );
            $sites = [];
            foreach ( $opts['remote_sites'] as $key => $site ) {
                // Garante índices válidos
                if ( empty( $site['url'] ) ) {
                    continue;
                }
                $sites[ $key ] = [
                    'url'          => $site['url'],
                    'label'        => $site['label'] ?? $site['url'],
                    'username'     => $site['username'] ?? '',
                    'app_password' => $site['app_password'] ?? '',
                ];
            }
            return $sites;
        }

        /**
         * Retorna os detalhes de um site específico pelo índice/chave.
         * Se não existir, devolve array vazio.
         *
         * @param string|int $key
         * @return array
         */
        public function get_remote_site_by_key( $key ) {
            $sites = $this->get_remote_sites();
            return isset( $sites[ $key ] ) ? $sites[ $key ] : [];
        }
    }

    // Inicialização: cria instância global
    global $sps_settings;
    $sps_settings = new SPS_Settings();
}
