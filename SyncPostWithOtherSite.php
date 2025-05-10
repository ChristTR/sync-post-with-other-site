<?php
/*
Plugin Name: Sync Post Master v2
Plugin URI: https://example.com/
Description: Sincronização completa com controle manual/automático e segurança reforçada
Version: 3.0.0
Author: Seu Nome
Text Domain: spmv2
*/



defined('ABSPATH') || exit;

// =================== ATIVAÇÃO =================== //
register_activation_hook(__FILE__, 'spm_v2_activate');
register_deactivation_hook(__FILE__, 'spm_v2_deactivate');

function spm_v2_activate() {
    global $wpdb;
    
    // Criar tabela de fila
    $queue_table = $wpdb->prefix . 'spm_v2_queue';
    $charset = $wpdb->get_charset_collate();
    
    $sql = "CREATE TABLE $queue_table (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        host VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt DATETIME,
        next_attempt DATETIME NOT NULL,
        PRIMARY KEY (id),
        INDEX post_idx (post_id),
        INDEX host_idx (host)
    ) $charset;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);

    // Configurações padrão
    add_option('spm_v2_settings', [
        'auto_sync' => true,
        'hosts' => [],
        'security' => [
            'jwt_secret' => bin2hex(random_bytes(32)),
            'force_ssl' => true
        ]
    ]);
}

function spm_v2_deactivate() {
    wp_clear_scheduled_hook('spm_v2_process_queue');
}

// =================== CLASSE PRINCIPAL =================== //
class SPM_v2_Core {
    private $settings;
    private $queue_table;
    private $sync_meta = '_spm_v2_sync_data';
    private $max_retries = 3;
    private $base_delay = 300;

    public function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings = get_option('spm_v2_settings');
        
        $this->init_hooks();
    }

    private function init_hooks() {
        // Hooks de sincronização
        add_action('save_post', [$this, 'handle_post_save'], 10, 3);
        add_action('spm_v2_process_queue', [$this, 'process_queue']);
        
        // Hooks administrativos
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('add_meta_boxes', [$this, 'add_meta_box']);
        add_action('wp_ajax_spm_v2_manual_sync', [$this, 'handle_manual_sync']);
        
        // Hooks REST API
        add_action('rest_api_init', [$this, 'register_endpoints']);
        
        // Agendamento
        if (!wp_next_scheduled('spm_v2_process_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'spm_v2_process_queue');
        }
    }

    // =================== INTERFACE ADMINISTRATIVA =================== //
    public function add_admin_menu() {
        add_menu_page(
            __('Sync Settings', 'spmv2'),
            __('Sync Master', 'spmv2'),
            'manage_options',
            'spm-v2-settings',
            [$this, 'render_settings_page'],
            'dashicons-update',
            80
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1><?php _e('Configurações de Sincronização', 'spmv2'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('spm_v2_settings_group'); ?>
                
                <h2><?php _e('Configurações Gerais', 'spmv2'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Sincronização Automática', 'spmv2'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spm_v2_settings[auto_sync]" 
                                    <?php checked($this->settings['auto_sync'], true); ?>>
                                <?php _e('Ativar sincronização automática', 'spmv2'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <h2><?php _e('Sites Remotos', 'spmv2'); ?></h2>
                <div id="spm-hosts-container">
                    <?php foreach ($this->settings['hosts'] as $index => $host) : ?>
                        <div class="spm-host-entry">
                            <input type="url" name="spm_v2_settings[hosts][<?= $index ?>][url]" 
                                value="<?= esc_url($host['url']); ?>" 
                                placeholder="https://site-remoto.com" required>
                                
                            <input type="text" name="spm_v2_settings[hosts][<?= $index ?>][secret]" 
                                value="<?= esc_attr($host['secret']); ?>" 
                                placeholder="<?php esc_attr_e('Chave Secreta', 'spmv2'); ?>" required>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="spm-add-host" class="button">
                    <?php _e('Adicionar Site', 'spmv2'); ?>
                </button>

                <h2><?php _e('Segurança', 'spmv2'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('Chave JWT', 'spmv2'); ?></th>
                        <td>
                            <input type="text" name="spm_v2_settings[security][jwt_secret]" 
                                value="<?= esc_attr($this->settings['security']['jwt_secret']); ?>" 
                                class="regular-text" readonly>
                            <p class="description"><?php _e('Gere nova chave:', 'spmv2'); ?> 
                                <button type="button" class="button button-small" onclick="generateNewSecret()">
                                    <?php _e('Gerar Nova', 'spmv2'); ?>
                                </button>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <script>
                // Adicionar novo host
                document.getElementById('spm-add-host').addEventListener('click', () => {
                    const container = document.getElementById('spm-hosts-container');
                    const index = container.children.length;
                    const div = document.createElement('div');
                    div.className = 'spm-host-entry';
                    div.innerHTML = `
                        <input type="url" name="spm_v2_settings[hosts][${index}][url]" 
                            placeholder="https://site-remoto.com" required>
                        <input type="text" name="spm_v2_settings[hosts][${index}][secret]" 
                            placeholder="<?php esc_attr_e('Chave Secreta', 'spmv2'); ?>" required>
                    `;
                    container.appendChild(div);
                });

                // Gerar nova chave JWT
                function generateNewSecret() {
                    const secretField = document.querySelector('[name="spm_v2_settings[security][jwt_secret]"]');
                    const randomString = Array.from(crypto.getRandomValues(new Uint8Array(32)))
                        .map(b => b.toString(16).padStart(2, '0')).join('');
                    secretField.value = randomString;
                }
            </script>
        </div>
        <?php
    }

    // =================== METABOX NO EDITOR =================== //
    public function add_meta_box() {
        add_meta_box(
            'spm_v2_sync_control',
            __('Controle de Sincronização', 'spmv2'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'high'
        );
    }

    public function render_meta_box($post) {
        $sync_data = get_post_meta($post->ID, $this->sync_meta, true);
        ?>
        <div class="spm-sync-control">
            <p>
                <label>
                    <input type="checkbox" name="spm_v2_auto_sync" 
                        <?php checked(!isset($sync_data['disabled']), true); ?>>
                    <?php _e('Sincronizar automaticamente', 'spmv2'); ?>
                </label>
            </p>
            
            <?php if (!empty($sync_data)) : ?>
                <div class="sync-status">
                    <h4><?php _e('Última Sincronização:', 'spmv2'); ?></h4>
                    <ul>
                        <?php foreach ($sync_data['hosts'] as $host => $status) : ?>
                            <li>
                                <strong><?= esc_html($host); ?>:</strong>
                                <?= $status['success'] ? '✅' : '❌'; ?>
                                <small>(<?= date_i18n('d/m/Y H:i', $status['time']); ?>)</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <p>
                <button type="button" class="button button-primary" 
                    onclick="spmTriggerManualSync(<?= $post->ID; ?>)">
                    <?php _e('Sincronizar Agora', 'spmv2'); ?>
                </button>
            </p>
        </div>
        
        <script>
            function spmTriggerManualSync(postId) {
                jQuery.post(ajaxurl, {
                    action: 'spm_v2_manual_sync',
                    post_id: postId,
                    security: '<?= wp_create_nonce('spm_v2_manual_sync'); ?>'
                }, function(response) {
                    alert(response.data.message);
                    location.reload();
                });
            }
        </script>
        <?php
    }

    // =================== SINCRONIZAÇÃO MANUAL =================== //
    public function handle_manual_sync() {
        check_ajax_referer('spm_v2_manual_sync', 'security');
        
        $post_id = intval($_POST['post_id']);
        $post = get_post($post_id);
        
        if (!$post || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message' => __('Acesso negado', 'spmv2')]);
        }
        
        $this->add_post_to_queue($post_id);
        
        wp_send_json_success(['message' => __('Sincronização iniciada!', 'spmv2')]);
    }

    // =================== LÓGICA DE SINCRONIZAÇÃO =================== //
    public function handle_post_save($post_id, $post, $update) {
        if (
            wp_is_post_revision($post_id) ||
            $post->post_status !== 'publish' ||
            !$this->settings['auto_sync'] ||
            isset($_POST['spm_v2_auto_sync']) && $_POST['spm_v2_auto_sync'] === '0'
        ) return;
        
        $this->add_post_to_queue($post_id);
    }

    private function add_post_to_queue($post_id) {
        global $wpdb;
        
        foreach ($this->settings['hosts'] as $host) {
            $wpdb->insert($this->queue_table, [
                'post_id' => $post_id,
                'host' => $host['url'],
                'next_attempt' => current_time('mysql')
            ]);
        }
        
        update_post_meta($post_id, $this->sync_meta, [
            'disabled' => false,
            'hosts' => []
        ]);
    }

    public function process_queue() {
        global $wpdb;
        
        $jobs = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM $this->queue_table 
                 WHERE next_attempt <= %s 
                 ORDER BY next_attempt ASC 
                 LIMIT 10",
                current_time('mysql')
            ),
            ARRAY_A
        );
        
        foreach ($jobs as $job) {
            $this->process_job($job);
        }
    }

    private function process_job($job) {
        global $wpdb;
        
        $post = get_post($job['post_id']);
        $host_config = $this->get_host_config($job['host']);
        
        try {
            if ($post && $host_config) {
                $this->sync_post($post, $host_config);
                $this->record_sync_success($job['post_id'], $host_config['url']);
                $wpdb->delete($this->queue_table, ['id' => $job['id']]);
            }
        } catch (Exception $e) {
            $this->handle_sync_error($job, $e->getMessage());
        }
    }

    private function sync_post($post, $host_config) {
        $jwt = $this->generate_jwt($host_config['secret']);
        
        $response = wp_remote_post($host_config['url'] . '/wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
                'X-SPM-Signature' => $this->generate_signature($post, $host_config['secret'])
            ],
            'body' => json_encode([
                'post' => $this->prepare_post_data($post),
                'meta' => $this->prepare_post_meta($post->ID)
            ]),
            'timeout' => 30
        ]);
        
        $this->validate_response($response);
    }

    // =================== SEGURANÇA =================== //
    private function generate_jwt($secret) {
        $header = json_encode(['alg' => 'HS256', 'typ' => 'JWT']);
        $payload = json_encode([
            'iss' => get_site_url(),
            'iat' => time(),
            'exp' => time() + 300
        ]);
        
        $base64UrlHeader = strtr(base64_encode($header), '+/', '-_');
        $base64UrlPayload = strtr(base64_encode($payload), '+/', '-_');
        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $secret, true);
        
        return "$base64UrlHeader.$base64UrlPayload." . strtr(base64_encode($signature), '+/', '-_');
    }

    private function generate_signature($post, $secret) {
        return hash_hmac('sha256', $post->ID . '|' . $post->post_modified, $secret);
    }

    // =================== REST API =================== //
    public function register_endpoints() {
        register_rest_route('spm/v2', '/sync', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_sync_request'],
            'permission_callback' => [$this, 'validate_request']
        ]);
    }

    public function validate_request($request) {
        $token = str_replace('Bearer ', '', $request->get_header('Authorization'));
        $signature = $request->get_header('X-SPM-Signature');
        
        foreach ($this->settings['hosts'] as $host) {
            if ($this->validate_jwt($token, $host['secret']) && 
               $this->validate_signature($signature, $request->get_body(), $host['secret'])) {
                return true;
            }
        }
        return false;
    }

    public function handle_sync_request($request) {
        $data = $request->get_json_params();
        
        // Prevenir loops
        if (!empty($data['meta']['_spm_v2_sync_data'])) {
            return new WP_REST_Response(['error' => 'Loop detectado'], 400);
        }
        
        $post_id = wp_insert_post([
            'post_title' => sanitize_text_field($data['post']['title']),
            'post_content' => wp_kses_post($data['post']['content']),
            'post_status' => 'publish'
        ]);
        
        if ($post_id && !is_wp_error($post_id)) {
            $this->update_post_meta($post_id, $data['meta']);
            return new WP_REST_Response(['success' => true, 'id' => $post_id], 201);
        }
        
        return new WP_REST_Response(['error' => 'Falha na criação'], 500);
    }

    // =================== UTILITÁRIOS =================== //
    private function validate_jwt($token, $secret) {
        list($header, $payload, $signature) = explode('.', $token);
        
        $valid_signature = hash_hmac('sha256', "$header.$payload", $secret, true);
        $valid_signature = strtr(base64_encode($valid_signature), '+/', '-_');
        
        return hash_equals($signature, $valid_signature) && 
               json_decode(base64_decode($payload))->exp > time();
    }

    private function record_sync_success($post_id, $host) {
        $sync_data = get_post_meta($post_id, $this->sync_meta, true);
        $sync_data['hosts'][$host] = [
            'success' => true,
            'time' => time()
        ];
        update_post_meta($post_id, $this->sync_meta, $sync_data);
    }

    private function handle_sync_error($job, $error) {
        global $wpdb;
        
        $new_attempts = $job['attempts'] + 1;
        $next_attempt = time() + ($this->base_delay * pow(2, $new_attempts));
        
        $wpdb->update(
            $this->queue_table,
            [
                'attempts' => $new_attempts,
                'last_attempt' => current_time('mysql'),
                'next_attempt' => date('Y-m-d H:i:s', $next_attempt)
            ],
            ['id' => $job['id']]
        );
        
        error_log("SPM v2 Sync Error (Post {$job['post_id']}): " . $error);
    }

    // ... (Métodos auxiliares restantes)

    // =================== MÉTODOS AUXILIARES =================== //

    /**
     * Prepara os dados principais do post para sincronização
     */
    private function prepare_post_data($post) {
        return [
            'ID' => $post->ID,
            'post_title' => sanitize_text_field($post->post_title),
            'post_content' => wp_kses_post($post->post_content),
            'post_excerpt' => wp_kses_post($post->post_excerpt),
            'post_status' => 'publish',
            'post_type' => $post->post_type,
            'post_date' => $post->post_date,
            'post_modified' => $post->post_modified,
            'post_author' => $this->get_author_data($post->post_author)
        ];
    }

    /**
     * Prepara os metadados do post para sincronização
     */
    private function prepare_post_meta($post_id) {
        $meta = [];
        $all_meta = get_post_meta($post_id);
        
        foreach ($all_meta as $key => $values) {
            // Ignora metadados internos do plugin
            if (strpos($key, '_spm_v2_') === 0) continue;
            
            $meta[$key] = array_map(function($value) {
                return maybe_unserialize($value);
            }, $values);
        }
        
        return $meta;
    }

    /**
     * Valida a resposta da requisição de sincronização
     */
    private function validate_response($response) {
        if (is_wp_error($response)) {
            throw new Exception(__('Erro de rede: ', 'spmv2') . $response->get_error_message());
        }
        
        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 201 || empty($body['success'])) {
            throw new Exception(__('Falha na sincronização. Código: ', 'spmv2') . $status);
        }
    }

    /**
     * Obtém a configuração de um host específico
     */
    private function get_host_config($host_url) {
        foreach ($this->settings['hosts'] as $host) {
            if ($host['url'] === $host_url) {
                return $host;
            }
        }
        return null;
    }

    /**
     * Atualiza os metadados do post com dados de sincronização
     */
    private function update_post_meta($post_id, $meta) {
        foreach ($meta as $key => $values) {
            // Ignora metadados especiais do plugin
            if (strpos($key, '_spm_v2_') === 0) continue;
            
            delete_post_meta($post_id, $key);
            foreach ($values as $value) {
                add_post_meta($post_id, $key, $value);
            }
        }
    }

    /**
     * Valida a assinatura HMAC da requisição
     */
    private function validate_signature($signature, $data, $secret) {
        $expected = hash_hmac('sha256', $data, $secret);
        return hash_equals($expected, $signature);
    }

    /**
     * Obtém dados do autor para sincronização
     */
    private function get_author_data($author_id) {
        $author = get_userdata($author_id);
        return $author ? [
            'display_name' => $author->display_name,
            'user_email' => $author->user_email,
            'user_login' => $author->user_login
        ] : [];
    }

    /**
     * Verifica se deve pular a sincronização para um post
     */
    private function should_skip_sync($post_id, $post) {
        // Post revisions e autosaves
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return true;
        }
        
        // Post não publicado
        if ($post->post_status !== 'publish') {
            return true;
        }
        
        // Sincronização desabilitada globalmente
        if (!$this->settings['auto_sync']) {
            return true;
        }
        
        // Sincronização desabilitada via metabox
        $sync_data = get_post_meta($post_id, $this->sync_meta, true);
        return !empty($sync_data['disabled']);
    }

    /**
     * Obtém taxonomias do post para sincronização
     */
    private function get_post_taxonomies($post_id) {
        $taxonomies = [];
        $post_type = get_post_type($post_id);
        
        foreach (get_object_taxonomies($post_type) as $taxonomy) {
            $terms = wp_get_post_terms($post_id, $taxonomy, [
                'fields' => 'slugs'
            ]);
            
            if (!is_wp_error($terms)) {
                $taxonomies[$taxonomy] = $terms;
            }
        }
        
        return $taxonomies;
    }

    /**
     * Processa e sincroniza mídias anexadas
     */
    private function sync_attached_media($post_id, $host_config) {
        $media_ids = get_posts([
            'post_parent' => $post_id,
            'post_type' => 'attachment',
            'fields' => 'ids',
            'posts_per_page' => -1
        ]);
        
        foreach ($media_ids as $media_id) {
            $this->sync_single_media($media_id, $host_config);
        }
    }

    /**
     * Sincroniza uma única mídia
     */
    private function sync_single_media($media_id, $host_config) {
        $file_path = get_attached_file($media_id);
        if (!file_exists($file_path)) return;
        
        $jwt = $this->generate_jwt($host_config['secret']);
        $api_url = $host_config['url'] . '/wp-json/spm/v2/media';
        
        $response = wp_remote_post($api_url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $jwt,
                'Content-Disposition' => 'attachment; filename="' . basename($file_path) . '"',
                'Content-Type' => mime_content_type($file_path)
            ],
            'body' => file_get_contents($file_path)
        ]);
        
        $this->validate_response($response);
    }
	
}

// =================== INICIALIZAÇÃO =================== //
add_action('plugins_loaded', function() {
    new SPM_v2_Core();
});

// =================== REGISTRO DE TRADUÇÕES =================== //
add_action('init', function() {
    load_plugin_textdomain(
        'spmv2',
        false,
        dirname(plugin_basename(__FILE__)) . '/languages/'
    );
});
