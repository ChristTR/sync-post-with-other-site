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

// ================ LOG INTERNO SYNC MASTER ================
if ( ! defined( 'SPMV2_LOG_FILE' ) ) {
    define( 'SPMV2_LOG_FILE', WP_CONTENT_DIR . '/syncmasterlog.txt' );
}
function spmv2_log( $message ) {
    if ( ! is_string( $message ) ) {
        $message = print_r( $message, true );
    }
    $time = date( 'Y-m-d H:i:s' );
    $line = "[$time] $message" . PHP_EOL;
    @file_put_contents( SPMV2_LOG_FILE, $line, FILE_APPEND | LOCK_EX );
}

// ================= TRANSLAÇÕES =================
$spmv2_translations = [
    'pt_BR' => [
        'Sync Settings'   => 'Configurações de Sincronização',
        'Sync Master'     => 'Gerenciador de Sincronia',
        'General Settings'=> 'Configurações Gerais',
        'Auto Sync'       => 'Sincronização Automática',
        'Enable auto sync'=> 'Ativar sincronização automática',
        'Remote Sites'    => 'Sites Remotos',
        'Add Host'        => 'Adicionar Site',
        'Security'        => 'Segurança',
        'JWT Secret'      => 'Chave Secreta JWT',
        'Generate New'    => 'Gerar Nova Chave',
        'Force HTTPS'     => 'Forçar HTTPS',
        'Sync Control'    => 'Controle de Sincronia',
        'Last Sync'       => 'Última Sincronização',
        'Sync Now'        => 'Sincronizar Agora',
        'Access Denied'   => 'Acesso Negado',
        'Sync Started'    => 'Sincronização Iniciada!',
        'Network Error'   => 'Erro de Rede',
        'Sync Failed'     => 'Falha na Sincronização',
        'Loop detected'   => 'Loop detectado',
        'Creation Failed' => 'Falha na criação',
    ],
];
function spmv2_translate($text) {
    global $spmv2_translations;
    $locale = get_locale();
    return $spmv2_translations[$locale][$text] ?? $text;
}
function spmv2__($t){ return spmv2_translate($t); }
function spmv2_e($t){ echo spmv2_translate($t); }

// ================ ATIVAÇÃO / DESATIVAÇÃO ================
register_activation_hook(__FILE__, 'spm_v2_activate');
register_deactivation_hook(__FILE__, 'spm_v2_deactivate');

function spm_v2_activate() {
    global $wpdb;
    $tbl = $wpdb->prefix . 'spm_v2_queue';
    $cs  = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $tbl (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        post_id BIGINT UNSIGNED NOT NULL,
        host VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt DATETIME,
        next_attempt DATETIME NOT NULL,
        PRIMARY KEY (id),
        INDEX post_idx (post_id),
        INDEX host_idx (host)
    ) $cs;";
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    add_option('spm_v2_settings', [
        'auto_sync' => true,
        'hosts'     => [],
        'security'  => [
            'jwt_secret'=> bin2hex(random_bytes(32)),
            'force_ssl' => true,
        ],
    ]);

    spmv2_log('Plugin ativado e tabela criada/verificada.');
}

function spm_v2_deactivate() {
    wp_clear_scheduled_hook('spm_v2_process_queue');
    spmv2_log('Plugin desativado e cron limpo.');
}

// ================ CRON INTERVALS & SETTINGS ================
add_filter('cron_schedules', function($s){
    $s['five_minutes'] = [
        'interval' => 5 * 60,
        'display'  => __('A cada 5 minutos'),
    ];
    return $s;
});

add_action('admin_init', function(){
    register_setting(
        'spm_v2_settings_group',
        'spm_v2_settings',
        ['type'=>'array','sanitize_callback'=>'spm_v2_sanitize_settings']
    );
});

function spm_v2_sanitize_settings($in) {
    $in['auto_sync'] = !empty($in['auto_sync']);
    if (!empty($in['hosts']) && is_array($in['hosts'])) {
        foreach ($in['hosts'] as &$h) {
            $h['url']    = esc_url_raw($h['url']);
            $h['secret'] = sanitize_text_field($h['secret']);
        }
    } else {
        $in['hosts'] = [];
    }
    $in['security']['jwt_secret'] = sanitize_text_field($in['security']['jwt_secret']);
    $in['security']['force_ssl']  = !empty($in['security']['force_ssl']);
    return $in;
}

// ================ CLASSE PRINCIPAL ================
class SPM_v2_Core {
    private $settings, $queue_table;
    private $sync_meta  = '_spm_v2_sync_data';
    private $base_delay = 300;

    public function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings    = get_option('spm_v2_settings');
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('save_post',                  [$this,'handle_post_save'], 10, 3);
        add_action('spm_v2_process_queue',       [$this,'process_queue']);
        add_action('admin_menu',                 [$this,'add_admin_menu']);
        add_action('add_meta_boxes',             [$this,'add_meta_box']);
        add_action('wp_ajax_spm_v2_manual_sync', [$this,'handle_manual_sync']);
        add_action('rest_api_init',              [$this,'register_endpoints']);
        if (!wp_next_scheduled('spm_v2_process_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'spm_v2_process_queue');
        }
    }

    // ----- ADMIN MENU -----
    public function add_admin_menu() {
        add_menu_page(
            spmv2__('Sync Settings'),
            spmv2__('Sync Master'),
            'manage_options',
            'spm-v2-settings',
            [$this,'render_settings_page'],
            'dashicons-update',
            80
        );
    }

    public function render_settings_page() {
        $s = $this->settings; ?>
        <div class="wrap">
            <h1><?php spmv2_e('Sync Settings'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('spm_v2_settings_group'); ?>
                <h2><?php spmv2_e('General Settings'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php spmv2_e('Auto Sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spm_v2_settings[auto_sync]" <?php checked($s['auto_sync'], true); ?>>
                                <?php spmv2_e('Enable auto sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <h2><?php spmv2_e('Remote Sites'); ?></h2>
                <div id="spm-hosts-container">
                    <?php foreach($s['hosts'] as $i=>$h): ?>
                        <div class="spm-host-entry">
                            <input type="url" name="spm_v2_settings[hosts][<?=$i?>][url]" value="<?=esc_url($h['url'])?>" placeholder="https://site-remoto.com" required>
                            <input type="text" name="spm_v2_settings[hosts][<?=$i?>][secret]" value="<?=esc_attr($h['secret'])?>" placeholder="<?php esc_attr_e('Chave Secreta','spmv2')?>" required>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="spm-add-host" class="button"><?php spmv2_e('Add Host');?></button>
                <h2><?php spmv2_e('Security'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php spmv2_e('JWT Secret'); ?></th>
                        <td>
                            <input type="text" name="spm_v2_settings[security][jwt_secret]" value="<?=esc_attr($s['security']['jwt_secret'])?>" readonly class="regular-text">
                            <p class="description">
                                <button type="button" class="button button-small" onclick="generateNewSecret()">
                                    <?php spmv2_e('Generate New'); ?>
                                </button>
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th><?php spmv2_e('Force HTTPS'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spm_v2_settings[security][force_ssl]" <?php checked($s['security']['force_ssl'], true); ?>>
                                <?php spmv2_e('Force HTTPS'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <script>
        document.getElementById('spm-add-host').onclick = ()=>{
            let c=document.getElementById('spm-hosts-container'), i=c.children.length;
            let d=document.createElement('div'); d.className='spm-host-entry';
            d.innerHTML=`
                <input type="url" name="spm_v2_settings[hosts][${i}][url]" placeholder="https://site-remoto.com" required>
                <input type="text" name="spm_v2_settings[hosts][${i}][secret]" placeholder="<?php esc_attr_e('Chave Secreta','spmv2')?>" required>`;
            c.appendChild(d);
        };
        function generateNewSecret(){
            let f=document.querySelector('[name="spm_v2_settings[security][jwt_secret]"]'),
                r=Array.from(crypto.getRandomValues(new Uint8Array(32)))
                   .map(b=>b.toString(16).padStart(2,'0')).join('');
            f.value=r;
        }
        </script>
    <?php }

    // ----- META BOX -----
    public function add_meta_box() {
        add_meta_box('spm_v2_sync_control', spmv2__('Sync Control'), [$this,'render_meta_box'], 'post', 'side', 'high');
    }

    public function render_meta_box($post) {
        $data  = get_post_meta($post->ID, $this->sync_meta, true);
        $nonce = wp_create_nonce('spm_v2_manual_sync'); ?>
        <div class="spm-sync-control">
            <p>
                <label>
                    <input type="checkbox" id="spm_v2_auto_sync" <?= empty($data['disabled']) ? 'checked' : '' ?>>
                    <?php spmv2_e('Enable auto sync'); ?>
                </label>
            </p>
            <?php if (!empty($data['hosts'])): ?>
                <div class="sync-status">
                    <h4><?php spmv2_e('Last Sync'); ?></h4>
                    <ul>
                        <?php foreach ($data['hosts'] as $h => $s): ?>
                            <li>
                                <strong><?= esc_html($h) ?>:</strong>
                                <?= $s['success'] ? '✅' : '❌' ?>
                                <small>(<?= date_i18n('d/m/Y H:i', $s['time']) ?>)</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <p>
                <button id="spm_manual_sync_btn" class="button button-primary">
                    <?php spmv2_e('Sync Now'); ?>
                </button>
            </p>
        </div>
        <script>
        document.getElementById('spm_manual_sync_btn').onclick = ()=>{
            let data=new FormData();
            data.append('action','spm_v2_manual_sync');
            data.append('post_id',<?= $post->ID ?>);
            data.append('security','<?= $nonce ?>');
            data.append('spm_v2_auto_sync', document.getElementById('spm_v2_auto_sync').checked?1:0);
            fetch(ajaxurl,{method:'POST',body:data})
                .then(r=>r.json()).then(js=>{alert(js.data.message);location.reload();});
        };
        </script>
    <?php }

    public function handle_manual_sync() {
        check_ajax_referer('spm_v2_manual_sync','security');
        $pid = intval($_POST['post_id']);
        if (!current_user_can('edit_post', $pid)) {
            wp_send_json_error(['message' => spmv2__('Access Denied')]);
        }
        $disabled = empty($_POST['spm_v2_auto_sync']);
        update_post_meta($pid, $this->sync_meta, ['disabled' => $disabled, 'hosts' => []]);
        spmv2_log("handle_manual_sync: post {$pid}, auto_sync=" . ($disabled ? '0' : '1'));
        if (!$disabled) {
            $this->add_post_to_queue($pid);
        }
        wp_send_json_success(['message' => spmv2__('Sync Started')]);
    }

    // ----- SALVAR & FILA -----
    public function handle_post_save($post_id, $post, $update) {
        spmv2_log("handle_post_save: post {$post_id}, status={$post->post_status}, update=" . ($update? '1':'0'));
        if ( wp_is_post_revision($post_id) || wp_is_post_autosave($post_id) || $post->post_status !== 'publish' || ! $this->settings['auto_sync'] ) {
            return;
        }
        $meta = get_post_meta($post_id, $this->sync_meta, true);
        if (! empty($meta['disabled'])) {
            return;
        }
        $this->add_post_to_queue($post_id);
    }

    private function add_post_to_queue($post_id) {
        global $wpdb;
        $count = count($this->settings['hosts']);
        spmv2_log("add_post_to_queue: adicionando post {$post_id} à fila para {$count} hosts");
        foreach ($this->settings['hosts'] as $h) {
            $wpdb->insert($this->queue_table, [
                'post_id'      => $post_id,
                'host'         => $h['url'],
                'next_attempt' => current_time('mysql'),
            ]);
        }
        update_post_meta($post_id, $this->sync_meta, ['disabled' => false, 'hosts' => []]);
    }

    public function process_queue() {
        spmv2_log("process_queue: iniciado em " . current_time('mysql'));
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} WHERE next_attempt<=%s ORDER BY next_attempt ASC LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);
        foreach ($jobs as $j) {
            $this->process_job($j);
        }
    }

    private function process_job($job) {
        spmv2_log("process_job: job #{$job['id']} post {$job['post_id']} host {$job['host']}");
        global $wpdb;
        $post = get_post($job['post_id']);
        $cfg  = $this->get_host_config($job['host']);
        try {
            if ($post && $cfg) {
                $this->sync_post($post, $cfg);
                $this->record_sync_success($job['post_id'], $cfg['url']);
                $wpdb->delete($this->queue_table, ['id' => $job['id']]);
            }
        } catch (Exception $e) {
            $this->handle_sync_error($job, $e->getMessage());
        }
    }

    private function sync_post($post, $host_config) {
        $jwt     = $this->generate_jwt($host_config['secret']);
        $payload = [
            'post' => $this->prepare_post_data($post),
            'meta' => $this->prepare_post_meta($post->ID),
        ];
        spmv2_log("sync_post: enviando payload para {$host_config['url']}: " . wp_json_encode($payload));
        $response = wp_remote_post(trailingslashit($host_config['url']) . 'wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization'   => 'Bearer ' . $jwt,
                'X-SPM-Signature' => $this->generate_signature($post, $host_config['secret']),
                'Content-Type'    => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);
        spmv2_log("sync_post: resposta status " . wp_remote_retrieve_response_code($response));
        $this->validate_response($response);
    }

    private function record_sync_success($post_id, $host) {
        spmv2_log("record_sync_success: post {$post_id} em {$host}");
        $data = get_post_meta($post_id, $this->sync_meta, true);
        $data['hosts'][$host] = ['success' => true, 'time' => time()];
        update_post_meta($post_id, $this->sync_meta, $data);
    }

    private function handle_sync_error($job, $error) {
        spmv2_log("handle_sync_error: job #{$job['id']} post {$job['post_id']} host {$job['host']} — {$error}");
        global $wpdb;
        $attempts    = $job['attempts'] + 1;
        $next_time   = time() + ($this->base_delay * pow(2, $attempts));
        $wpdb->update(
            $this->queue_table,
            [
                'attempts'     => $attempts,
                'last_attempt' => current_time('mysql'),
                'next_attempt' => date('Y-m-d H:i:s', $next_time),
            ],
            ['id' => $job['id']]
        );
    }

    private function register_endpoints() {
        register_rest_route('spm/v2', '/sync', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_sync_request'],
            'permission_callback' => [$this, 'validate_request'],
        ]);
        register_rest_route('spm/v2', '/media', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle_media_request'],
            'permission_callback' => [$this, 'validate_request'],
        ]);
    }

    public function validate_request($request) {
        $token     = str_replace('Bearer ', '', $request->get_header('Authorization'));
        $signature = $request->get_header('X-SPM-Signature');
        foreach ($this->settings['hosts'] as $h) {
            if ($this->validate_jwt($token, $h['secret']) &&
                $this->validate_signature($signature, $request->get_body(), $h['secret'])
            ) {
                return true;
            }
        }
        return false;
    }

    public function handle_sync_request($request) {
        $data = $request->get_json_params();
        if (!empty($data['meta']['_spm_v2_sync_data'])) {
            return new WP_REST_Response(['error' => spmv2__('Loop detected')], 400);
        }
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($data['post']['post_title']),
            'post_content' => wp_kses_post($data['post']['post_content']),
            'post_status'  => 'publish',
            'post_type'    => $data['post']['post_type'] ?? 'post',
        ]);
        if (is_wp_error($post_id) || !$post_id) {
            return new WP_REST_Response(['error' => spmv2__('Creation Failed')], 500);
        }
        $this->update_post_meta($post_id, $data['meta']);
        return new WP_REST_Response(['success' => true, 'id' => $post_id], 201);
    }

    public function handle_media_request($request) {
        $raw = $request->get_body();
        $hdr = $request->get_header('Content-Disposition');
        if (preg_match('/filename="([^"]+)"/', $hdr, $m)) {
            $filename = sanitize_file_name($m[1]);
        } else {
            $filename = 'upload-' . time();
        }
        $upload = wp_upload_bits($filename, null, $raw);
        if ($upload['error']) {
            return new WP_REST_Response(['error' => spmv2__('Network Error') . ' ' . $upload['error']], 500);
        }
        $filetype   = wp_check_filetype($filename, null);
        $attachment = [
            'guid'           => $upload['url'],
            'post_mime_type' => $filetype['type'],
            'post_title'     => sanitize_file_name(pathinfo($filename, PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit',
        ];
        $attach_id = wp_insert_attachment($attachment, $upload['file']);
        require_once ABSPATH . 'wp-admin/includes/image.php';
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id, $upload['file']));
        return new WP_REST_Response(['success' => true, 'id' => $attach_id], 201);
    }

    // ======== UTILITÁRIOS ========
    private function generate_jwt($secret) {
        $h   = base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $p   = base64Url(json_encode(['iss'=>get_site_url(),'iat'=>time(),'exp'=>time()+300]));
        $sig = hash_hmac('sha256', "$h.$p", $secret, true);
        return "$h.$p." . base64Url($sig);
    }

    private function generate_signature($post, $secret) {
        return hash_hmac('sha256', $post->ID . '|' . $post->post_modified, $secret);
    }

    private function validate_jwt($token, $secret) {
        list($h,$p,$sig) = explode('.', $token);
        $valid = hash_hmac('sha256', "$h.$p", $secret, true);
        $valid = base64Url($valid);
        $pl    = json_decode(base64_decode(strtr($p,'-_','+/')), true);
        return hash_equals($sig, $valid) && !empty($pl['exp']) && $pl['exp'] > time();
    }

    private function validate_signature($signature, $data, $secret) {
        return hash_equals(hash_hmac('sha256', $data, $secret), $signature);
    }

    private function get_host_config($url) {
        foreach ($this->settings['hosts'] as $h) {
            if ($h['url'] === $url) {
                return $h;
            }
        }
        return null;
    }

    private function prepare_post_data($post) {
        return [
            'ID'            => $post->ID,
            'post_title'    => $post->post_title,
            'post_content'  => $post->post_content,
            'post_excerpt'  => $post->post_excerpt,
            'post_status'   => 'publish',
            'post_type'     => $post->post_type,
            'post_date'     => $post->post_date,
            'post_modified' => $post->post_modified,
            'post_author'   => $this->get_author_data($post->post_author),
        ];
    }

    private function prepare_post_meta($post_id) {
        $out  = [];
        $all  = get_post_meta($post_id);
        foreach ($all as $key => $vals) {
            if (strpos($key, '_spm_v2_') === 0) {
                continue;
            }
            $out[$key] = array_map('maybe_unserialize', $vals);
        }
        return $out;
    }

    private function update_post_meta($post_id, $meta) {
        foreach ($meta as $key => $vals) {
            if (strpos($key, '_spm_v2_') === 0) {
                continue;
            }
            delete_post_meta($post_id, $key);
            foreach ((array)$vals as $v) {
                add_post_meta($post_id, $key, $v);
            }
        }
    }

    private function get_author_data($author_id) {
        $user = get_userdata($author_id);
        return $user ? [
            'display_name' => $user->display_name,
            'user_email'   => $user->user_email,
            'user_login'   => $user->user_login,
        ] : [];
    }
}

// helper base64url
function base64Url($data) {
    $b = base64_encode($data);
    return rtrim(strtr($b, '+/', '-_'), '=');
}

// ================ INICIALIZAÇÃO ================
add_action('plugins_loaded', function() {
    new SPM_v2_Core();
});
