<?php
if (!class_exists('SPSv2_Settings')) {

    class SPSv2_Settings {

        function __construct(){
            add_action("spsv2_save_settings", array($this, "spsv2_save_settings"), 10, 1);
            add_action('admin_init', array($this, 'spsv2_register_settings'));
        }

        // ============ [ NOVOS RECURSOS ] ============ //
        
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

        // ============ [ MÉTODOS PRINCIPAIS ] ============ //

        public function spsv2_save_settings($params) {
            if (!current_user_can('manage_options')) {
                SPSv2_Logger::log("Tentativa de acesso não autorizado às configurações", 'security');
                wp_die(__('Acesso negado!', 'spsv2_text_domain'));
            }

            if (wp_verify_nonce($_POST['_wpnonce'], 'spsv2_settings_group-options')) {
                $settings = $this->spsv2_sanitize_settings($_POST['spsv2_settings']);
                update_option('spsv2_settings', $settings);
                
                $_SESSION['spsv2_msg'] = __('Configurações salvas com sucesso!', 'spsv2_text_domain');
                $_SESSION['spsv2_msg_type'] = 'success';
                
                SPSv2_Logger::log("Configurações atualizadas", 'info');
            } else {
                $_SESSION['spsv2_msg'] = __('Falha na verificação de segurança!', 'spsv2_text_domain');
                $_SESSION['spsv2_msg_type'] = 'error';
            }
        }

        private function spsv2_sanitize_settings($input) {
            $output = array();
            
            if(isset($input['hosts'])) {
                foreach($input['hosts'] as $index => $host) {
                    $output['hosts'][$index] = array(
                        'url' => esc_url_raw($host['url']),
                        'username' => sanitize_user($host['username']),
                        'password' => sanitize_text_field($host['password']),
                        'strict_mode' => isset($host['strict_mode']) ? 1 : 0,
                        'match_mode' => sanitize_text_field($host['match_mode']),
                        'excluded_categories' => isset($host['excluded_categories']) 
                            ? array_map('intval', $host['excluded_categories']) 
                            : array(),
                        'roles' => isset($host['roles']) 
                            ? array_map('sanitize_text_field', $host['roles']) 
                            : array()
                    );
                }
            }
            
            return $output;
        }

        // ============ [ MÉTODOS DE RENDERIZAÇÃO ] ============ //

        private function spsv2_render_host_fields($index, $host) {
            ?>
            <div class="spsv2-host-section">
                <h3><?php _e('Configurações do Site', 'spsv2_text_domain'); ?></h3>
                
                <table class="form-table">
                    <!-- Campos existentes -->
                    <tr>
                        <th><?php _e('URL do Site', 'spsv2_text_domain'); ?></th>
                        <td>
                            <input type="url" name="spsv2_settings[hosts][<?php echo $index; ?>][url]" 
                                   value="<?php echo esc_url($host['url']); ?>" 
                                   class="regular-text" required>
                        </td>
                    </tr>
                    
                    <!-- Novo Campo: Categorias Excluídas -->
                    <tr>
                        <th><?php _e('Categorias Excluídas', 'spsv2_text_domain'); ?></th>
                        <td>
                            <?php $this->spsv2_render_category_checkboxes($index, $host); ?>
                        </td>
                    </tr>
                    
                    <!-- Demais campos (usuário, senha, modos) -->
                    <?php $this->spsv2_render_common_fields($index, $host); ?>
                </table>
                
                <button type="button" class="button spsv2-remove-host">
                    <?php _e('Remover Site', 'spsv2_text_domain'); ?>
                </button>
                <hr>
            </div>
            <?php
        }

        private function spsv2_render_category_checkboxes($index, $host) {
            $categories = get_categories(array('hide_empty' => false));
            $excluded = $host['excluded_categories'] ?? array();
            
            foreach($categories as $cat) {
                $checked = in_array($cat->term_id, $excluded) ? 'checked' : '';
                echo '<label>';
                echo '<input type="checkbox" name="spsv2_settings[hosts]['.$index.'][excluded_categories][]" 
                      value="'.esc_attr($cat->term_id).'" '.$checked.'>';
                echo esc_html($cat->name);
                echo '</label><br>';
            }
        }

        // ============ [ MÉTODOS UTILITÁRIOS ] ============ //

        public function spsv2_get_settings() {
            $defaults = array(
                'hosts' => array(
                    array(
                        'url' => '',
                        'username' => '',
                        'password' => '',
                        'strict_mode' => 1,
                        'match_mode' => 'title',
                        'excluded_categories' => array(),
                        'roles' => array('administrator')
                    )
                )
            );
            
            return wp_parse_args(get_option('spsv2_settings'), $defaults);
        }

        public function spsv2_get_hosts() {
            $settings = $this->spsv2_get_settings();
            return $settings['hosts'] ?? array();
        }
    }

    // Dentro da classe SPSv2_Settings
public function spsv2_get_post_types() {
    $builtin_exclude = ['attachment', 'revision', 'nav_menu_item', 'custom_css', 'customize_changeset'];
    $post_types = get_post_types(['public' => true], 'names');
    
    return array_diff($post_types, $builtin_exclude);
}

    global $spsv2_settings;
    $spsv2_settings = new SPSv2_Settings();
}
