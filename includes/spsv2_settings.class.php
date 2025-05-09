<?php
if (!class_exists('SPSv2_Settings')) {

    class SPSv2_Settings {

        private $settings_group = 'spsv2_settings_group';
        private $settings_page = 'spsv2-settings';
        private $settings_name = 'spsv2_settings';

        public function __construct() {
            add_action('admin_init', [$this, 'register_settings']);
            add_action('admin_notices', [$this, 'display_admin_notices']);
        }

        public function register_settings() {
            register_setting(
                $this->settings_group,
                $this->settings_name,
                ['sanitize_callback' => [$this, 'sanitize_settings']]
            );

            // Seção principal
            add_settings_section(
                'spsv2_hosts_section',
                __('Configuração de Sites Remotos', 'spsv2'),
                [$this, 'render_hosts_section'],
                $this->settings_page
            );

            // Seção de segurança
            add_settings_section(
                'spsv2_security_section',
                __('Configurações de Segurança', 'spsv2'),
                [$this, 'render_security_section'],
                $this->settings_page
            );

            // Campos de segurança
            add_settings_field(
                'force_ssl',
                __('Forçar HTTPS', 'spsv2'),
                [$this, 'render_force_ssl_field'],
                $this->settings_page,
                'spsv2_security_section'
            );

            add_settings_field(
                'min_tls',
                __('Versão TLS Mínima', 'spsv2'),
                [$this, 'render_min_tls_field'],
                $this->settings_page,
                'spsv2_security_section'
            );
        }

        public function render_settings_page() {
            if (!current_user_can('manage_options')) {
                wp_die(__('Acesso negado.', 'spsv2'));
            }
            ?>
            <div class="wrap">
                <h1><?php _e('Configurações Sync Post v2', 'spsv2'); ?></h1>
                
                <form method="post" action="options.php">
                    <?php
                    settings_fields($this->settings_group);
                    do_settings_sections($this->settings_page);
                    submit_button(__('Salvar Configurações', 'spsv2'));
                    ?>
                </form>
                
                <div class="spsv2-settings-sidebar">
                    <h3><?php _e('Requisitos de Segurança', 'spsv2'); ?></h3>
                    <ul>
                        <li><?php _e('HTTPS obrigatório em todos os sites', 'spsv2'); ?></li>
                        <li><?php _e('TLS 1.2 ou superior', 'spsv2'); ?></li>
                        <li><?php _e('Senhas de Aplicativo válidas', 'spsv2'); ?></li>
                    </ul>
                </div>
            </div>
            <?php
        }

        public function render_hosts_section() {
            echo '<p>' . __('Adicione e configure os sites WordPress para sincronização.', 'spsv2') . '</p>';
            $this->render_hosts_table();
        }

        private function render_hosts_table() {
            $settings = $this->get_settings();
            ?>
            <table class="wp-list-table widefat fixed striped" id="spsv2-hosts-table">
                <thead>
                    <tr>
                        <th><?php _e('URL do Site', 'spsv2'); ?></th>
                        <th><?php _e('Usuário', 'spsv2'); ?></th>
                        <th><?php _e('Senha de Aplicativo', 'spsv2'); ?></th>
                        <th><?php _e('Ações', 'spsv2'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($settings['hosts'] as $index => $host) : ?>
                    <tr>
                        <td>
                            <input type="url" 
                                   name="<?php echo $this->settings_name; ?>[hosts][<?php echo $index; ?>][url]" 
                                   value="<?php echo esc_url($host['url']); ?>" 
                                   class="regular-text"
                                   required>
                        </td>
                        <td>
                            <input type="text" 
                                   name="<?php echo $this->settings_name; ?>[hosts][<?php echo $index; ?>][username]" 
                                   value="<?php echo esc_attr($host['username']); ?>" 
                                   required>
                        </td>
                        <td>
                            <input type="text" 
                                   name="<?php echo $this->settings_name; ?>[hosts][<?php echo $index; ?>][app_password]" 
                                   value="<?php echo esc_attr($host['app_password']); ?>"
                                   pattern="[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}"
                                   title="<?php esc_attr_e('Formato: XXXX XXXX XXXX XXXX XXXX', 'spsv2'); ?>"
                                   required>
                        </td>
                        <td>
                            <button type="button" class="button button-secondary spsv2-remove-host">
                                <?php _e('Remover', 'spsv2'); ?>
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="spsv2-host-actions">
                <button type="button" id="spsv2-add-host" class="button button-primary">
                    <?php _e('+ Adicionar Site', 'spsv2'); ?>
                </button>
            </div>
            <?php
        }

        public function render_security_section() {
            echo '<p>' . __('Configurações avançadas de segurança para a comunicação entre sites.', 'spsv2') . '</p>';
        }

        public function render_force_ssl_field() {
            $settings = $this->get_settings();
            ?>
            <label>
                <input type="checkbox" 
                       name="<?php echo $this->settings_name; ?>[security][force_ssl]" 
                       value="1" <?php checked($settings['security']['force_ssl'], 1); ?>>
                <?php _e('Exigir conexão HTTPS em todas as comunicações', 'spsv2'); ?>
            </label>
            <?php
        }

        public function render_min_tls_field() {
            $settings = $this->get_settings();
            ?>
            <select name="<?php echo $this->settings_name; ?>[security][min_tls]" required>
                <option value="1.2" <?php selected($settings['security']['min_tls'], '1.2'); ?>>TLS 1.2</option>
                <option value="1.3" <?php selected($settings['security']['min_tls'], '1.3'); ?>>TLS 1.3</option>
            </select>
            <?php
        }

        public function sanitize_settings($input) {
            $output = [
                'hosts' => [],
                'security' => [
                    'force_ssl' => 0,
                    'min_tls' => '1.2'
                ]
            ];

            // Sanitizar configurações de segurança
            if (isset($input['security'])) {
                $output['security']['force_ssl'] = !empty($input['security']['force_ssl']) ? 1 : 0;
                $output['security']['min_tls'] = in_array($input['security']['min_tls'], ['1.2', '1.3']) 
                    ? $input['security']['min_tls'] 
                    : '1.2';
            }

            // Sanitizar hosts
            if (!empty($input['hosts'])) {
                foreach ($input['hosts'] as $index => $host) {
                    $sanitized_host = $this->sanitize_host($host);
                    if ($sanitized_host) {
                        $output['hosts'][] = $sanitized_host;
                    }
                }
            }

            return $output;
        }

        private function sanitize_host($host) {
            $errors = [];

            // Validar URL
            $url = esc_url_raw($host['url'] ?? '');
            if (!wp_http_validate_url($url) || !preg_match('/^https:\/\//i', $url)) {
                $errors[] = __('URL inválida ou não usa HTTPS', 'spsv2');
            }

            // Validar usuário
            $username = sanitize_user($host['username'] ?? '');
            if (empty($username)) {
                $errors[] = __('Nome de usuário é obrigatório', 'spsv2');
            }

            // Validar senha de aplicativo
            $app_password = sanitize_text_field($host['app_password'] ?? '');
            if (!preg_match('/^[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}$/', $app_password)) {
                $errors[] = __('Formato inválido para Senha de Aplicativo', 'spsv2');
            }

            // Mostrar erros
            if (!empty($errors)) {
                foreach ($errors as $error) {
                    add_settings_error(
                        $this->settings_name,
                        'invalid_host',
                        $error
                    );
                }
                return false;
            }

            return [
                'url' => trailingslashit($url),
                'username' => $username,
                'app_password' => $app_password
            ];
        }

        public function display_admin_notices() {
            settings_errors($this->settings_name);
        }

        public function get_settings() {
            $defaults = [
                'hosts' => [],
                'security' => [
                    'force_ssl' => 1,
                    'min_tls' => '1.2'
                ]
            ];
            
            return wp_parse_args(
                get_option($this->settings_name, []),
                $defaults
            );
        }
    }
}
