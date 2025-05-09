<?php 
if (!class_exists('SPSv2_Post_Meta')) {

    class SPSv2_Post_Meta {

        function __construct() {
            add_action('admin_init', array( $this, 'spsv2_register_meta_box' ));
            add_action('save_post', array( $this, 'spsv2_save_meta_fields' ), 10, 3);
        }

        // ==================== [ NOVOS RECURSOS ] ==================== //

        private function get_excluded_categories($website_key) {
            $settings = get_option('spsv2_settings', array());
            return $settings['hosts'][$website_key]['excluded_categories'] ?? array();
        }

        private function should_exclude_post($post_id, $website_key) {
            $excluded_cats = $this->get_excluded_categories($website_key);
            $post_cats = wp_get_post_categories($post_id, array('fields' => 'ids'));
            return !empty(array_intersect($excluded_cats, $post_cats));
        }

        private function get_post_types() {
    $excluded = ['attachment', 'revision', 'nav_menu_item'];
    return array_diff(get_post_types(['public' => true]), $excluded);
}

        // ==================== [ MÉTODOS PRINCIPAIS ] ==================== //

        function spsv2_register_meta_box() {
            global $spsv2_settings;
            add_meta_box(
    'spsv2_websites', 
    __('Sites de Sincronização v2', 'spsv2-txt-domain'), 
    array($this, 'spsv2_render_meta_box'), 
    $this->get_post_types(), // Método interno corrigido
    'side', 
    'default'
);
        }

        private function get_post_types() {
    $excluded = ['attachment', 'revision', 'nav_menu_item'];
    return array_diff(get_post_types(['public' => true]), $excluded);
}
        
        public function spsv2_render_meta_box($post) {
            global $spsv2_settings;
            $settings = $spsv2_settings->spsv2_get_settings();
            $saved_websites = get_post_meta($post->ID, 'spsv2_websites', true) ?: array();
            
            echo '<div class="spsv2-meta-container">';
            
            if (!empty($settings['hosts'])) {
                foreach ($settings['hosts'] as $index => $host) {
                    $disabled = $this->should_exclude_post($post->ID, $index) ? 'disabled' : '';
                    $checked = in_array($host['url'], $saved_websites) ? 'checked' : '';
                    
                    echo '<div class="spsv2-website-item">';
                    echo '<label>';
                    echo '<input type="checkbox" 
                                name="spsv2_websites[]" 
                                value="'.esc_attr($host['url']).'" 
                                '.$checked.' 
                                '.$disabled.'>';
                    echo esc_html($host['url']);
                    
                    if($disabled) {
                        echo '<span class="spsv2-warning"> ('.__('Categorias excluídas', SPSV2_txt_domain).')</span>';
                    }
                    
                    echo '</label>';
                    echo '</div>';
                }
            } else {
                echo '<p>'.__('Configure os sites nas configurações do plugin primeiro.', SPSV2_txt_domain).'</p>';
            }
            
            echo '</div>';
            
            // Adicionar estilo
            echo '<style>
                .spsv2-warning { color: #d63638; font-size: 0.9em; margin-left: 8px; }
                .spsv2-website-item { margin: 8px 0; }
                .spsv2-website-item input[disabled] + span { opacity: 0.6; }
            </style>';
        }

        function spsv2_save_meta_fields($post_id, $post, $update) {
            if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
            if (!current_user_can('edit_post', $post_id)) return;

            $websites = isset($_POST['spsv2_websites']) ? 
                array_map('esc_url_raw', $_POST['spsv2_websites']) : 
                array();

            // Verificar exclusão por categoria antes de salvar
            $valid_websites = array();
            foreach ($websites as $url) {
                $host_key = array_search($url, array_column($settings['hosts'] ?? array(), 'url'));
                if ($host_key !== false && !$this->should_exclude_post($post_id, $host_key)) {
                    $valid_websites[] = $url;
                }
            }

            update_post_meta($post_id, 'spsv2_websites', $valid_websites);
            
            // Log da ação
            SPSv2_Logger::log("Meta campos atualizados para o post {$post_id}", 'info', [
                'websites' => $valid_websites,
                'excluded' => array_diff($websites, $valid_websites)
            ]);
        }

        // ==================== [ INTEGRAÇÃO YOAST ] ==================== //
        
        public function spsv2_add_yoast_meta($post_id) {
            $meta = array(
                '_yoast_wpseo_title' => get_post_meta($post_id, '_yoast_wpseo_title', true),
                '_yoast_wpseo_metadesc' => get_post_meta($post_id, '_yoast_wpseo_metadesc', true)
            );
            
            update_post_meta($post_id, 'spsv2_yoast_meta', $meta);

    private function get_supported_post_types() {
        $excluded = ['attachment', 'revision', 'nav_menu_item'];
        return array_diff(get_post_types(['public' => true]), $excluded);
    }
            
        }
    }

    global $spsv2_post_meta;
    $spsv2_post_meta = new SPSv2_Post_Meta();
}
