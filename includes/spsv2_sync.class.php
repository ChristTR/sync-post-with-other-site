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

        // ============ [ MÉTODOS PRINCIPAIS ] ============ //

        function spsv2_filter_post_data($data, $postarr) {
            // Lógica existente de filtragem de dados
            return $data;
        }

        function spsv2_rest_insert_post($post, $request, $creating) {
            // Lógica para REST API
        }

        function spsv2_save_post($post_ID, $post, $update) {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;

            $spsv2_websites = isset($_REQUEST['spsv2_website']) ? $_REQUEST['spsv2_website'] : [];
            $status_not = ['auto-draft', 'trash', 'inherit', 'draft'];
            
            if($this->is_website_post && !in_array($post->post_status, $status_not) && !empty($spsv2_websites)) {
                
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

                // ... restante da lógica de sincronização
            }
        }

        function spsv2_rest_api_init() {
            register_rest_route('spsv2/v1', '/data', [
                'methods' => 'POST',
                'callback' => array($this, 'spsv2_handle_request'),
                'permission_callback' => array($this, 'spsv2_api_permission_check')
            ]);
        }

        function spsv2_api_permission_check($request) {
            return current_user_can('edit_posts');
        }

        function spsv2_handle_request($request) {
            $params = $request->get_params();
            // Lógica de processamento do request
            return new WP_REST_Response(['status' => 'success'], 200);
        }

        function spsv2_grab_content_images($post_id, $spsv2_sync_data) {
            // Lógica para processar imagens
        }

        // ============ [ MÉTODOS ADICIONAIS ] ============ //
        
        function spsv2_remote_post($action, $args) {
            // Lógica para envio remoto
        }

        function spsv2_send_data_to($action, $args, $spsv2_websites) {
            // Lógica de envio para múltiplos sites
        }
    }
}
