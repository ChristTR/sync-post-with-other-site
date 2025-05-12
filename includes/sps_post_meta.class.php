<?php
if ( ! class_exists( 'SPS_Post_Meta' ) ) {

    class SPS_Post_Meta {

        public function __construct() {
            // Registra o metabox
            add_action( 'add_meta_boxes',     [ $this, 'register_sites_metabox' ] );
            // Salva os sites selecionados (prioridade 10)
            add_action( 'save_post',          [ $this, 'save_selected_sites' ], 10, 2 );
            // Dispara a sincronização após salvar metadados (prioridade 20)
            add_action( 'save_post',          [ $this, 'maybe_sync_post' ], 20, 2 );
        }

        /**
         * Registra o metabox “Select Websites” na tela de edição de post.
         */
        public function register_sites_metabox() {
            global $sps_settings;
            $post_types = method_exists( $sps_settings, 'sps_get_post_types' )
                ? $sps_settings->sps_get_post_types()
                : [ 'post' ];

            add_meta_box(
                'sps_websites',
                __( 'Select Websites', SPS_txt_domain ),
                [ $this, 'print_meta_fields' ],
                $post_types,
                'side',
                'default'
            );
        }

        /**
         * Imprime os checkboxes com os sites disponíveis.
         */
        public function print_meta_fields( WP_Post $post ) {
            global $sps_settings;
            // Obtém lista de sites configurados no plugin (ex.: option ou método próprio)
            $sites = method_exists( $sps_settings, 'get_remote_sites' )
                ? $sps_settings->get_remote_sites()
                : get_option( 'sps_remote_sites', [] );

            // Sites selecionados neste post
            $selected = get_post_meta( $post->ID, '_sps_sites', true );
            if ( ! is_array( $selected ) ) {
                $selected = [];
            }

            // Nonce para segurança
            wp_nonce_field( 'sps_save_sites', 'sps_sites_nonce' );

            echo '<div class="sps-sites-list">';
            if ( empty( $sites ) ) {
                echo '<p>' . esc_html__( 'No remote sites configured.', SPS_txt_domain ) . '</p>';
            } else {
                foreach ( $sites as $key => $site ) {
                    $label   = isset( $site['label'] ) ? $site['label'] : $site['url'];
                    $checked = in_array( $key, $selected, true ) ? 'checked' : '';
                    printf(
                        '<p><label><input type="checkbox" name="sps_sites[]" value="%s" %s> %s</label></p>',
                        esc_attr( $key ),
                        $checked,
                        esc_html( $label )
                    );
                }
            }
            echo '</div>';
        }

        /**
         * Salva os sites selecionados no post meta.
         */
        public function save_selected_sites( $post_id, $post ) {
            // Verifica nonce
            if ( empty( $_POST['sps_sites_nonce'] ) ||
                 ! wp_verify_nonce( $_POST['sps_sites_nonce'], 'sps_save_sites' )
            ) {
                return;
            }
            // Evita autosave/revisão
            if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
                return;
            }
            // Permissão de edição
            if ( ! current_user_can( 'edit_post', $post_id ) ) {
                return;
            }
            // Leia e sanitize
            $sites = isset( $_POST['sps_sites'] ) && is_array( $_POST['sps_sites'] )
                ? array_map( 'sanitize_text_field', wp_unslash( $_POST['sps_sites'] ) )
                : [];
            update_post_meta( $post_id, '_sps_sites', $sites );
        }

        /**
         * Após salvar o post, verifica sites selecionados e sincroniza.
         */
        public function maybe_sync_post( $post_id, $post ) {
            // Não para revisões
            if ( wp_is_post_revision( $post_id ) ) {
                return;
            }
            // Obtém os sites escolhidos
            $sites = get_post_meta( $post_id, '_sps_sites', true );
            if ( empty( $sites ) || ! is_array( $sites ) ) {
                return;
            }
            // Garante que seu plugin principal está carregado
            if ( ! class_exists( 'SyncPostWithOtherSite' ) ) {
                return;
            }
            $main = SyncPostWithOtherSite::instance(); // supondo método singleton
            // Monta payload
            $payload = [
                'title'   => get_the_title( $post_id ),
                'content' => apply_filters( 'the_content', $post->post_content ),
                'author'  => intval( $post->post_author ),
                'id'      => $post_id,
            ];
            // Envia para cada site selecionado
            foreach ( $sites as $key ) {
                $remote = $main->get_remote_site_by_key( $key ); 
                if ( empty( $remote['url'] ) || empty( $remote['username'] ) || empty( $remote['app_password'] ) ) {
                    continue;
                }
                $main->send_post_to_remote(
                    $payload,
                    $remote['url'],
                    $remote['username'],
                    $remote['app_password']
                );
            }
        }
    }

    // Inicializa a classe
    new SPS_Post_Meta();
}
