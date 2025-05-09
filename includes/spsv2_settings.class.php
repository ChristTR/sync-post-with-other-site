<?php
if (!class_exists('SPSv2_Settings')) {

    class SPSv2_Settings {

        function __construct(){
            add_action("spsv2_save_settings", array($this, "spsv2_save_settings"), 10, 1);
            add_action('admin_init', array($this, 'spsv2_register_settings'));
        }

        public function spsv2_register_settings() {
            register_setting('spsv2_settings_group', 'spsv2_settings', array(
                'sanitize_callback' => array($this, 'spsv2_sanitize_settings')
            ));
        }

        public function spsv2_display_settings() {
            global $spsv2;
            ?>
            <div class="wrap spsv2-settings-wrapper">
                <h1><?php _e('Configurações Sync Post v2', 'spsv2_text_domain'); ?></h1>
                
                <?php if(isset($_SESSION['spsv2_msg'])) : ?>
                    <div class="notice notice-<?php echo $_SESSION['spsv2_msg_type']; ?>">
                        <p><?php echo $_SESSION['spsv2_msg']; ?></p>
                    </div>
                    <?php unset($_SESSION['spsv2_msg']); ?>
                <?php endif; ?>

                <form method="post" action="options.php">
                    <?php 
                    settings_fields('spsv2_settings_group');
                    $settings = $this->spsv2_get_settings();
                    $hosts = $settings['hosts'] ?? array();
                    ?>
                    
                    <div id="spsv2-hosts-container">
                        <?php foreach($hosts as $index => $host) : ?>
                            <div class="spsv2-host-card">
                                <?php $this->spsv2_render_host_fields($index, $host); ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <button type="button" class="button" id="spsv2-add-host">
                        <?php _e('+ Adicionar Novo Site', 'spsv2_text_domain'); ?>
                    </button>

                    <?php submit_button(__('Salvar Configurações', 'spsv2_text_domain')); ?>
                </form>
            </div>
            <?php
        }
        
        public function spsv2_get_post_types() {
            $excluded = ['attachment', 'revision', 'nav_menu_item'];
            return array_diff(get_post_types(['public' => true]), $excluded);
        }

        public function spsv2_get_settings() {
            return get_option('spsv2_settings', array(
                'hosts' => array(),
                'version' => '2.0'
            ));
        }

        public function spsv2_sanitize_settings($input) {
            $output = array();
            
            if(isset($input['hosts'])) {
                foreach($input['hosts'] as $index => $host) {
                    $output['hosts'][$index] = array(
                        'url' => esc_url_raw($host['url']),
                        'username' => sanitize_user($host['username']),
                        'password' => sanitize_text_field($host['password']),
                        'strict_mode' => isset($host['strict_mode']) ? 1 : 0,
                        'excluded_categories' => isset($host['excluded_categories']) 
                            ? array_map('intval', $host['excluded_categories']) 
                            : array()
                    );
                }
            }
            
            return $output;
        }

        private function spsv2_render_host_fields($index, $host) {
            $categories = get_categories(['hide_empty' => false]);
            ?>
            <table class="form-table">
                <tr>    
                    <th><label><?php _e('URL do Site', 'spsv2_text_domain'); ?></label></th>
                    <td>
                        <input type="url" 
                               name="spsv2_settings[hosts][<?php echo $index; ?>][url]" 
                               value="<?php echo esc_url($host['url']); ?>" 
                               class="regular-text" required>
                    </td>
                </tr>
                
                <tr>
                    <th><label><?php _e('Categorias Excluídas', 'spsv2_text_domain'); ?></label></th>
                    <td>
                        <div class="spsv2-category-checklist">
                            <?php foreach($categories as $cat) : ?>
                                <label>
                                    <input type="checkbox" 
                                           name="spsv2_settings[hosts][<?php echo $index; ?>][excluded_categories][]" 
                                           value="<?php echo $cat->term_id; ?>"
                                           <?php checked(in_array($cat->term_id, $host['excluded_categories'] ?? [])); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </label><br>
                            <?php endforeach; ?>
                        </div>
                    </td>
                </tr>
            </table>
            <?php
        }

        public function spsv2_save_settings($params) {
            if (!current_user_can('manage_options')) {
                wp_die(__('Acesso negado!', 'spsv2_text_domain'));
            }

            check_admin_referer('spsv2_settings_group-options');
            
            $settings = $this->spsv2_sanitize_settings($_POST['spsv2_settings']);
            update_option('spsv2_settings', $settings);
            
            $_SESSION['spsv2_msg'] = __('Configurações salvas com sucesso!', 'spsv2_text_domain');
            $_SESSION['spsv2_msg_type'] = 'success';
        }
    }
}
