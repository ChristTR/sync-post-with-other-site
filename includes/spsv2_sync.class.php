<?php
if (!class_exists('SPSv2_Sync')) {

    class SPSv2_Sync {
        var $is_website_post = true;
        var $post_old_title = '';

        function __construct(){
            add_filter('wp_insert_post_data', array($this, 'spsv2_filter_post_data'), 99, 2);
            add_action("rest_insert_post", array($this, "spsv2_rest_insert_post"), 10, 3);
            add_action("save_post", array($this, "spsv2_save_post"), 10, 3);
            add_action("spsv2_after_save_data", array($this, "spsv2_grab_content_images"), 10, 2);
            add_action('rest_api_init', array($this, 'spsv2_rest_api_init'));
        }

        // ============ [ NOVOS RECURSOS DA VERSÃO 2 ] ============ //
        
        private function get_yoast_meta($post_id) {
            return [
                '_yoast_wpseo_title' => get_post_meta($post_id, '_yoast_wpseo_title', true),
                '_yoast_wpseo_metadesc' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true),
                '_yoast_wpseo_focuskw' => get_post_meta($post_id, '_yoast_wpseo_focuskw', true)
            ];
        }

        private function check_excluded_categories($post_id, $website_key) {
            $excluded = get_option('spsv2_excluded_categories_' . $website_key, []);
            $post_cats = wp_get_post_categories($post_id, ['fields' => 'ids']);
            
            if (array_intersect($excluded, $post_cats)) {
                SPSv2_Logger::log("Post $post_id ignorado - Categorias excluídas detectadas", 'warning');
                return true;
            }
            return false;
        }

        // ============ [ MÉTODOS PRINCIPAIS ATUALIZADOS ] ============ //

        function spsv2_save_post($post_ID, $post, $update) {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

            $spsv2_websites = isset($_REQUEST['spsv2_website']) ? $_REQUEST['spsv2_website'] : [];
            $status_not = ['auto-draft', 'trash', 'inherit', 'draft'];
            
            if ($this->is_website_post && !in_array($post->post_status, $status_not) && !empty($spsv2_websites)) {
                
                // Verificar categorias excluídas
                foreach ($spsv2_websites as $key => $url) {
                    if ($this->check_excluded_categories($post_ID, $key)) {
                        unset($spsv2_websites[$key]);
                    }
                }

                if (empty($spsv2_websites)) return;

                $args = (array) $post;
                $args['meta'] = array_merge(
                    $args['meta'] ?? [], 
                    $this->get_yoast_meta($post_ID)
                );

                // ... (restante da lógica de sincronização)
                
                $response = $this->spsv2_send_data('add_update_post', $args, $spsv2_websites);
                SPSv2_Logger::log("Resposta sincronização: " . print_r($response, true), 'info');
            }
        }

        function spsv2_send_data($action, $args = [], $spsv2_websites = []) {
            $general_option = get_option('spsv2_settings', []);
            $responses = [];

            foreach ($spsv2_websites as $key => $url) {
                // Verificação de segurança adicional
                if (!isset($general_option['spsv2_host_name'][$key])) {
                    SPSv2_Logger::log("Configuração inválida para o site $url", 'error');
                    continue;
                }

                $args['spsv2'] = [
                    'host_name' => sanitize_url($general_option['spsv2_host_name'][$key]),
                    'content_username' => sanitize_user($general_option['spsv2_content_username'][$key]),
                    'content_password' => $general_option['spsv2_content_password'][$key]
                ];

                $response = $this->spsv2_remote_post($action, $args);
                
                if (is_wp_error($response)) {
                    SPSv2_Logger::log("Erro na sincronização: " . $response->get_error_message(), 'error');
                } else {
                    $responses[$key] = $response;
                }
            }
            
            return $responses;
        }

        function spsv2_remote_post($action, $args) {
            $url = $args['spsv2']['host_name'] . "/wp-json/spsv2/v1/data";
            
            SPSv2_Logger::log("Enviando para: $url", 'info');
            
            return wp_remote_post($url, [
                'timeout' => 45,
                'body' => json_encode($args),
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-WP-Nonce' => wp_create_nonce('spsv2_api_nonce')
                ]
            ]);
        }

        // ============ [ MÉTODOS HERDADOS (AJUSTADOS) ] ============ //

        function spsv2_rest_api_init() {
            register_rest_route('spsv2/v1', '/data', [
                'methods' => 'POST',
                'callback' => [$this, 'spsv2_handle_request'],
                'permission_callback' => [$this, 'spsv2_api_permission_check']
            ]);
        }

        function spsv2_api_permission_check($request) {
            return current_user_can('edit_posts') || 
                   current_user_can('manage_options');
        }

        function spsv2_handle_request($request) {
            $params = $request->get_params();
            
            if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'spsv2_api_nonce')) {
                SPSv2_Logger::log("Tentativa de acesso não autorizado", 'security');
                return new WP_REST_Response(['error' => 'Nonce inválido'], 403);
            }

            // ... (restante da lógica do request handler)
        }

        // ... (demais métodos necessários mantendo a lógica original com prefixo spsv2_)
    }

    global $spsv2_sync;
    $spsv2_sync = new SPSv2_Sync();
}
