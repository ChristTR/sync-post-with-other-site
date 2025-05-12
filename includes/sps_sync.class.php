<?php
if ( ! class_exists( 'SPS_Sync' ) ) {

    class SPS_Sync {
        /** Flag para evitar loops */
        private $is_website_post = true;
        private $post_old_title = '';

        public function __construct() {
            // Captura título antigo antes de salvar
            add_filter( 'wp_insert_post_data', [ $this, 'filter_post_data' ], 99, 2 );

            // Garante que tags do Gutenberg sejam salvas
            add_action( 'rest_insert_post', [ $this, 'sps_rest_insert_post' ], 10, 3 );

            // Hook principal de salvamento de post
            add_action( 'save_post', [ $this, 'sps_save_post' ], 10, 3 );

            // Após sincronizar/remover imagens, puxa imagens hospedadas externamente
            add_action( 'spsp_after_save_data', [ $this, 'spsp_grab_content_images' ], 10, 2 );

            // Endpoint interno para receber dados via REST
            add_action( 'rest_api_init', [ $this, 'rest_api_init_func' ] );
        }

        /**
         * Registra rota REST para aceitar requisições de sincronização
         */
        public function rest_api_init_func() {
            register_rest_route( 'sps/v1', '/data', [
                'methods'             => 'POST',
                'callback'            => [ $this, 'sps_get_request' ],
                'permission_callback' => '__return_true',
            ] );
        }

        /**
         * Armazena o título antigo para usar em matching
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
         * Garante que tags venham no $tagids
         */
        public function sps_rest_insert_post( $post, $request, $creating ) {
            $json   = $request->get_json_params();
            $tagids = isset( $json['tags'] ) ? $json['tags'] : '';
            $this->sps_save_post( $post->ID, $post, $creating, $tagids );
        }

        /**
         * Hook save_post: monta $args e dispara sync, com debug no log
         */
        public function sps_save_post( $post_ID, $post, $update ) {
            // DEBUG: registrar que chegamos aqui
            $this->write_log( "Entrou em sps_save_post para post_ID={$post_ID}", 'debug_log.txt' );

            // Ignora autosaves e revisões
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                $this->write_log( "Abortado por DOING_AUTOSAVE", 'debug_log.txt' );
                return;
            }
            if ( wp_is_post_revision( $post_ID ) ) {
                $this->write_log( "Abortado por revisão", 'debug_log.txt' );
                return;
            }

            // Quais sites foram selecionados no metabox?
            $sps_websites = isset( $_POST['sps_sites'] ) ? (array) $_POST['sps_sites'] : [];
            $this->write_log( 'Sites selecionados: ' . ( empty( $sps_websites ) ? 'nenhum' : implode( ',', $sps_websites ) ), 'debug_log.txt' );

            if ( empty( $sps_websites ) ) {
                $this->write_log( 'Nenhum site selecionado, saindo.', 'debug_log.txt' );
                return;
            }

            // Monta payload básico
            $args = [
                'ID'           => $post_ID,
                'post_title'   => get_the_title( $post_ID ),
                'post_content' => apply_filters( 'the_content', $post->post_content ),
                'post_author'  => $post->post_author,
                'post_type'    => $post->post_type,
                'post_old_title' => $this->post_old_title ?: get_the_title( $post_ID ),
            ];

            // Featured image
            if ( has_post_thumbnail( $post_ID ) ) {
                $args['featured_image'] = get_the_post_thumbnail_url( $post_ID, 'full' );
            }

            // Taxonomias
            $taxes = get_object_taxonomies( $post->post_type );
            if ( $taxes ) {
                $args['taxonomies'] = [];
                foreach ( $taxes as $tax ) {
                    $args['taxonomies'][ $tax ] = wp_get_post_terms( $post_ID, $tax, [ 'fields' => 'all' ] );
                }
            }

            // Metadados (exceto _sps_sites)
            $all_meta = get_post_meta( $post_ID );
            unset( $all_meta['_sps_sites'] );
            $args['meta'] = [];
            foreach ( $all_meta as $m_key => $m_val ) {
                $args['meta'][ $m_key ] = maybe_unserialize( $m_val[0] );
            }

            // Dispara envio
            $this->write_log( 'Enviando payload para sites...', 'debug_log.txt' );
            $this->sps_send_data_to( 'add_update_post', $args, $sps_websites );
        }

        /**
         * Envia os dados para todos os sites selecionados
         */
        private function sps_send_data_to( $action, $args, $sps_websites ) {
            global $sps_settings;

            $all_sites = $sps_settings->get_remote_sites();
            foreach ( $sps_websites as $key ) {
                if ( empty( $all_sites[ $key ] ) ) {
                    $this->write_log( "Chave {$key} não configurada em remote_sites", 'debug_log.txt' );
                    continue;
                }

                $site = $all_sites[ $key ];
                $payload = $args;
                $payload['sps_action'] = $action;
                $payload['sps'] = [
                    'host_name'        => $site['url'],
                    'content_username' => $site['username'],
                    'content_password' => $site['app_password'],
                ];

                $endpoint = untrailingslashit( $site['url'] ) . '/wp-json/sps/v1/data';
                $this->write_log( "POST para {$endpoint}", 'debug_log.txt' );

                $response = wp_remote_post( $endpoint, [
                    'headers' => [ 'Content-Type' => 'application/json' ],
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 20,
                ] );

                if ( is_wp_error( $response ) ) {
                    $this->write_log( "WP_Error: " . $response->get_error_message(), 'debug_log.txt' );
                } else {
                    $code = wp_remote_retrieve_response_code( $response );
                    $body = wp_remote_retrieve_body( $response );
                    $this->write_log( "Resposta {$code}: {$body}", 'debug_log.txt' );
                }
            }
        }

        /**
         * Recebe requisições REST vindas de outros sites
         */
        public function sps_get_request( WP_REST_Request $request ) {
            $data   = $request->get_json_params();
            $action = isset( $data['sps_action'] ) ? $data['sps_action'] : '';

            // Autentica usuário remoto
            $user = wp_authenticate( $data['sps']['content_username'], $data['sps']['content_password'] );
            if ( is_wp_error( $user ) || ! isset( $user->ID ) ) {
                return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'Auth failed' ], 200 );
            }

            // Evita loop de envio
            $this->is_website_post = false;

            // Remove campos de autenticação antes de processar
            unset( $data['sps'], $data['sps_action'] );

            // Rota a ação para o método interno
            $method = 'sps_' . $action;
            if ( method_exists( $this, $method ) ) {
                return call_user_func( [ $this, $method ], $user, $data );
            }

            return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'Unknown action' ], 200 );
        }

        /**
         * Exemplo de método para add/update remoto (pode ser extendido)
         */
        public function sps_add_update_post( $author, $data ) {
            // Permissões...
            // ...Implementação como no exemplo anterior...
            return new WP_REST_Response( [ 'status' => 'success', 'post_id' => 0 ], 200 );
        }

        /**
         * Grava mensagem em /log/$file dentro do plugin.
         */
        private function write_log( $message, $file = 'sps_log.txt' ) {
            $dir = __DIR__ . '/log/';
            if ( ! file_exists( $dir ) ) {
                wp_mkdir_p( $dir );
            }
            $line = date( 'Y-m-d H:i:s' ) . " - {$message}\n";
            file_put_contents( $dir . $file, $line, FILE_APPEND | LOCK_EX );
        }

        /**
         * Extrai imagens de conteúdo e importa (simplificado).
         */
        public function spsp_grab_content_images( $post_id, $data ) {
            if ( empty( $data['post_content'] ) ) {
                return;
            }
            preg_match_all( '/<img[^>]+src=["\']([^"\']+)["\']/i', stripslashes( $data['post_content'] ), $matches );
            if ( empty( $matches[1] ) ) {
                return;
            }
            foreach ( $matches[1] as $src ) {
                $this->import_featured_image( $src, $post_id );
            }
        }

        /**
         * Importa imagem externa como featured image.
         */
        private function import_featured_image( $url, $post_id ) {
            $tmp = download_url( $url );
            if ( is_wp_error( $tmp ) ) {
                return;
            }
            $file_array = [ 'name' => basename( $url ), 'tmp_name' => $tmp ];
            $id = media_handle_sideload( $file_array, $post_id );
            if ( is_wp_error( $id ) ) {
                @unlink( $tmp );
                return;
            }
            set_post_thumbnail( $post_id, $id );
        }
    }

    // Inicializa
    global $sps_sync;
    $sps_sync = new SPS_Sync();
}
