<?php
if ( ! class_exists( 'SPS_Sync' ) ) {

    class SPS_Sync {
        /** Caminho do arquivo de log em wp-content/uploads/ */
        private $log_file;

        /** Último título antes da atualização */
        private $post_old_title = '';

        public function __construct() {
            // Define o caminho do arquivo de log
            $upload_dir     = wp_upload_dir();
            $this->log_file = trailingslashit( $upload_dir['basedir'] ) . 'sps-debug.log';

            // DEBUG: log no construtor
            $this->write_log( 'Construtor SPS_Sync inicializado.' );

            // Hooks principais
            add_filter( 'wp_insert_post_data',   [ $this, 'filter_post_data' ], 99, 2 );
            add_action( 'rest_insert_post',       [ $this, 'sps_rest_insert_post' ], 10, 3 );
            add_action( 'save_post',              [ $this, 'sps_save_post' ], 10, 3 );
            add_action( 'spsp_after_save_data',   [ $this, 'spsp_grab_content_images' ], 10, 2 );
            add_action( 'rest_api_init',          [ $this, 'rest_api_init_func' ] );
        }

        /**
         * Registra rota REST para receber sincronizações
         */
        public function rest_api_init_func() {
            register_rest_route( 'sps/v1', '/data', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'sps_get_request' ],
                'permission_callback' => '__return_true',
            ] );
            $this->write_log( 'REST route /sps/v1/data registrada.' );
        }

        /**
         * Captura o título antigo antes de atualizar o post
         */
        public function filter_post_data( $data, $postarr ) {
            if ( ! empty( $postarr['ID'] ) ) {
                $old = get_post( $postarr['ID'] );
                if ( $old && isset( $old->post_title ) ) {
                    $this->post_old_title = $old->post_title;
                }
            }
            return $data;
        }

        /**
         * Garante que tags do Gutenberg sejam processadas
         */
        public function sps_rest_insert_post( $post, $request, $creating ) {
            $json = $request->get_json_params();
            $this->sps_save_post( $post->ID, $post, $creating );
        }

        /**
         * Hook save_post: monta payload, faz debug e dispara sync
         */
        public function sps_save_post( $post_ID, $post, $update ) {
            // DEBUG: entrou no método
            $this->write_log( "Entrou em sps_save_post para post_ID={$post_ID}" );

            // Ignora autosave
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                $this->write_log( 'Abortado por DOING_AUTOSAVE' );
                return;
            }
            // Ignora revisão
            if ( wp_is_post_revision( $post_ID ) ) {
                $this->write_log( 'Abortado por revisão' );
                return;
            }

            // Quais sites foram selecionados no metabox?
            $sites = isset( $_POST['sps_sites'] ) ? (array) $_POST['sps_sites'] : [];
            $this->write_log( 'Sites selecionados: ' . ( empty( $sites ) ? 'nenhum' : implode( ',', $sites ) ) );

            if ( empty( $sites ) ) {
                $this->write_log( 'Nenhum site escolhido, cancelando sync.' );
                return;
            }

            // Monta payload básico
            $payload = [
                'ID'              => $post_ID,
                'post_title'      => get_the_title( $post_ID ),
                'post_content'    => apply_filters( 'the_content', $post->post_content ),
                'post_author'     => $post->post_author,
                'post_type'       => $post->post_type,
                'post_old_title'  => $this->post_old_title ?: get_the_title( $post_ID ),
            ];

            // Featured image
            if ( has_post_thumbnail( $post_ID ) ) {
                $payload['featured_image'] = get_the_post_thumbnail_url( $post_ID, 'full' );
            }

            // Taxonomias
            $taxes = get_object_taxonomies( $post->post_type );
            if ( ! empty( $taxes ) ) {
                $payload['taxonomies'] = [];
                foreach ( $taxes as $tax ) {
                    $payload['taxonomies'][ $tax ] = wp_get_post_terms( $post_ID, $tax, [ 'fields' => 'all' ] );
                }
            }

            // Metadados (exceto _sps_sites)
            $all_meta = get_post_meta( $post_ID );
            unset( $all_meta['_sps_sites'] );
            $payload['meta'] = [];
            foreach ( $all_meta as $m_key => $m_val ) {
                $payload['meta'][ $m_key ] = maybe_unserialize( $m_val[0] );
            }

            // Dispara o envio para cada site
            $this->write_log( 'Disparando envio para sites...' );
            $this->sps_send_data_to( 'add_update_post', $payload, $sites );
        }

        /**
         * Envia dados para todos os sites configurados
         */
        private function sps_send_data_to( $action, $data, $site_keys ) {
            global $sps_settings;
            $all_sites = $sps_settings->get_remote_sites();

            foreach ( $site_keys as $key ) {
                if ( empty( $all_sites[ $key ] ) ) {
                    $this->write_log( "Chave {$key} não encontrada em get_remote_sites()" );
                    continue;
                }

                $site = $all_sites[ $key ];
                $endpoint = untrailingslashit( $site['url'] ) . '/wp-json/sps/v1/data';
                $this->write_log( "POST para {$endpoint}" );

                // Monta payload com autenticação
                $payload = $data;
                $payload['sps_action']        = $action;
                $payload['sps']['host_name']  = $site['url'];
                $payload['sps']['content_username'] = $site['username'];
                $payload['sps']['content_password'] = $site['app_password'];

                $response = wp_remote_post( $endpoint, [
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                    $this->write_log( 'WP_Error: ' . $response->get_error_message() );
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $body = wp_remote_retrieve_body( $response );
                    $this->write_log( "Resposta {$code}: {$body}" );
                }
            }
        }

        /**
         * Callback REST para receber requisições de sync
         */
        public function sps_get_request( WP_REST_Request $request ) {
            $data   = $request->get_json_params();
            $action = isset( $data['sps_action'] ) ? $data['sps_action'] : '';

            // Autentica usuário remoto
            $user = wp_authenticate( $data['sps']['content_username'], $data['sps']['content_password'] );
            if ( is_wp_error( $user ) || empty( $user->ID ) ) {
                return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'Auth failed' ], 200 );
            }

            // Define flag para não voltar recursivamente
            $this->is_website_post = false;

            // Limpa dados de autenticação
            unset( $data['sps'], $data['sps_action'] );

            // Chama o método correto (e.g. sps_add_update_post)
            $method = 'sps_' . $action;
            if ( method_exists( $this, $method ) ) {
                return call_user_func( [ $this, $method ], $user, $data );
            }

            return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'Unknown action' ], 200 );
        }

        /**
         * Conserto de permissões e import de imagens
         */
        public function spsp_grab_content_images( $post_id, $data ) {
            if ( empty( $data['post_content'] ) ) {
                return;
            }
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/', stripslashes( $data['post_content'] ), $m );
            if ( empty( $m[1] ) ) {
                return;
            }
            foreach ( $m[1] as $src ) {
                $this->import_featured_image( $src, $post_id );
            }
        }

        /**
         * Importa imagem externa como featured image
         */
        private function import_featured_image( $url, $post_id ) {
            $tmp = download_url( $url );
            if ( is_wp_error( $tmp ) ) {
                return;
            }
            $file_array = [ 'name' => basename( $url ), 'tmp_name' => $tmp ];
            $attach_id = media_handle_sideload( $file_array, $post_id );
            if ( ! is_wp_error( $attach_id ) ) {
                set_post_thumbnail( $post_id, $attach_id );
            }
        }

        /**
         * Grava mensagem no arquivo de log em uploads/
         *
         * @param string $message
         */
        private function write_log( $message ) {
            $line = '[' . date( 'Y-m-d H:i:s' ) . '] ' . $message . "\n";
            @file_put_contents( $this->log_file, $line, FILE_APPEND | LOCK_EX );
        }
    }

    // Inicializa a classe
    global $sps_sync;
    $sps_sync = new SPS_Sync();
}
