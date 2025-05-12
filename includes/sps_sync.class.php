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
         * Envia os dados para todos os sites selecionados
         */
        private function sps_send_data_to( $action, $args, $sps_websites ) {
            if ( empty( $sps_websites ) || ! is_array( $sps_websites ) ) {
                return;
            }
            global $sps_settings;

            // Para cada site configurado, se estiver selecionado, envia
            $return = [];
            $all_sites = $sps_settings->get_remote_sites();
            foreach ( $sps_websites as $key ) {
                if ( ! isset( $all_sites[ $key ] ) ) {
                    continue;
                }
                $site = $all_sites[ $key ];
                $payload = $args;
                $payload['sps_action'] = $action;
                $payload['sps']       = [
                    'host_name'      => $site['url'],
                    'content_username' => $site['username'],
                    'content_password' => $site['app_password'],
                ];
                $endpoint = untrailingslashit( $site['url'] ) . '/wp-json/sps/v1/data';

                $response = wp_remote_post( $endpoint, [
                    'headers' => [
                        'Content-Type' => 'application/json',
                    ],
                    'body'    => wp_json_encode( $payload ),
                    'timeout' => 20,
                ] );

                // Armazena na resposta geral
                $return[ $key ] = $response;
                // Log de erro caso necessário
                if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
                    $this->write_log(
                        sprintf(
                            "Erro sync [%s]: %s",
                            $site['url'],
                            is_wp_error( $response )
                                ? $response->get_error_message()
                                : wp_remote_retrieve_body( $response )
                        ),
                        'sps_sync_errors.log'
                    );
                }
            }

            return $return;
        }

        /**
         * Hook save_post: monta $args e dispara sync
         */
        public function sps_save_post( $post_ID, $post, $update ) {
            // Ignora autosaves e revisões
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            if ( wp_is_post_revision( $post_ID ) ) {
                return;
            }
            // Quais sites foram selecionados no metabox?
            $sps_websites = isset( $_POST['sps_sites'] ) ? (array) $_POST['sps_sites'] : [];
            if ( empty( $sps_websites ) ) {
                return;
            }

            // Monta payload básico
            $args = [
                'ID'           => $post_ID,
                'post_title'   => get_the_title( $post_ID ),
                'post_content' => apply_filters( 'the_content', $post->post_content ),
                'post_author'  => $post->post_author,
                'post_type'    => $post->post_type,
            ];

            // Antigo título para matching
            $args['post_old_title'] = $this->post_old_title ?: $args['post_title'];

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
            $this->sps_send_data_to( 'add_update_post', $args, $sps_websites );
        }

        /**
         * Grava no diretório `log/` dentro do plugin
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
         * Recebe requisições REST vindas de outros sites
         */
        public function sps_get_request( WP_REST_Request $request ) {
            $data = $request->get_json_params();
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

            // Rota a ação para o método interno `sps_add_update_post`
            if ( method_exists( $this, 'sps_' . $action ) ) {
                return call_user_func( [ $this, 'sps_' . $action ], $user, $data );
            }

            return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'Unknown action' ], 200 );
        }

        /**
         * Adiciona ou edita post recebido remotamente
         */
        public function sps_add_update_post( $author, $data ) {
            // Permissões
            if ( ! $author->has_cap( 'edit_posts' ) && ( $data['post_type'] === 'page' && ! $author->has_cap( 'edit_pages' ) ) ) {
                return new WP_REST_Response( [ 'status' => 'failed', 'msg' => 'No permission' ], 200 );
            }

            // Matching por slug ou título
            $match = isset( $data['content_match'] ) ? $data['content_match'] : 'title';
            $existing_id = $this->find_existing_post( $data, $match );

            // Insere ou atualiza
            if ( $existing_id ) {
                $data['ID'] = $existing_id;
                $new_id = wp_update_post( $data );
                $action = 'edit';
            } else {
                $new_id = wp_insert_post( $data );
                $action = 'add';
            }

            // Processa taxonomias, metas, imagem destacada, etc.
            $this->process_post_details( $new_id, $data );

            return new WP_REST_Response( [
                'status'    => 'success',
                'post_id'   => $new_id,
                'post_action' => $action
            ], 200 );
        }

        /**
         * Busca post existente por slug/título
         */
        private function find_existing_post( $data, $match ) {
            $args = [
                'post_type'  => $data['post_type'],
                'post_status'=> 'any',
                'numberposts'=> 1,
            ];
            if ( $match === 'slug' ) {
                $args['name'] = $data['post_name'] ?? '';
            } else {
                $args['title'] = $data['post_old_title'] ?? $data['post_title'];
            }
            $found = get_posts( $args );
            return $found ? $found[0]->ID : 0;
        }

        /**
         * Processa taxonomias, metas e featured image para o post sincronizado
         */
        private function process_post_details( $post_id, $data ) {
            // Taxonomias
            if ( ! empty( $data['taxonomies'] ) ) {
                foreach ( $data['taxonomies'] as $tax => $terms ) {
                    if ( is_taxonomy_hierarchical( $tax ) ) {
                        $ids = [];
                        foreach ( $terms as $t ) {
                            $term_obj = term_exists( $t->name, $tax );
                            if ( ! $term_obj ) {
                                $term_obj = wp_insert_term( $t->name, $tax );
                            }
                            $ids[] = is_array( $term_obj ) ? $term_obj['term_id'] : $term_obj;
                        }
                        wp_set_post_terms( $post_id, $ids, $tax );
                    } else {
                        $names = wp_list_pluck( $terms, 'name' );
                        wp_set_post_terms( $post_id, $names, $tax );
                    }
                }
            }

            // Metadados
            if ( ! empty( $data['meta'] ) ) {
                foreach ( $data['meta'] as $m_key => $m_val ) {
                    update_post_meta( $post_id, $m_key, maybe_unserialize( $m_val ) );
                }
            }

            // Featured image
            if ( ! empty( $data['featured_image'] ) ) {
                $this->import_featured_image( $data['featured_image'], $post_id );
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
            $file_array = [
                'name'     => basename( $url ),
                'tmp_name' => $tmp,
            ];
            $id = media_handle_sideload( $file_array, $post_id );
            if ( is_wp_error( $id ) ) {
                @unlink( $tmp );
                return;
            }
            set_post_thumbnail( $post_id, $id );
        }

        /**
         * Extrai todas as <img> de conteúdo e faz sideload
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
    }

    // Inicializa
    global $sps_sync;
    $sps_sync = new SPS_Sync();
}
