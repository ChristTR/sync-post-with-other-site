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

/** ================= TRANSLAÇÕES ================= **/
$spmv2_translations = [
    'pt_BR' => [
        // Admin
        'Sync Settings'         => 'Configurações de Sincronização',
        'Sync Master'           => 'Gerenciador de Sincronia',
        'General Settings'      => 'Configurações Gerais',
        'Auto Sync'             => 'Sincronização Automática',
        'Enable auto sync'      => 'Ativar sincronização automática',
        'Remote Sites'          => 'Sites Remotos',
        'Add Host'              => 'Adicionar Site',
        'Security'              => 'Segurança',
        'JWT Secret'            => 'Chave Secreta JWT',
        'Generate New'          => 'Gerar Nova Chave',
        'Force HTTPS'           => 'Forçar HTTPS',
        // Metabox
        'Sync Control'          => 'Controle de Sincronia',
        'Last Sync'             => 'Última Sincronização',
        'Sync Now'              => 'Sincronizar Agora',
        // Mensagens
        'Access Denied'         => 'Acesso Negado',
        'Sync Started'          => 'Sincronização Iniciada!',
        'Network Error'         => 'Erro de Rede',
        'Sync Failed'           => 'Falha na Sincronização',
        'Loop detected'         => 'Loop detectado',
        'Creation Failed'       => 'Falha na criação',
    ],
];

function spmv2_translate($text) {
    global $spmv2_translations;
    $locale = get_locale();
    return $spmv2_translations[$locale][$text] ?? $text;
}
function spmv2__($text) { return spmv2_translate($text); }
function spmv2_e($text) { echo spmv2_translate($text); }

/** ================ ATIVAÇÃO / DESATIVAÇÃO ================ **/
register_activation_hook(__FILE__, 'spm_v2_activate');
register_deactivation_hook(__FILE__, 'spm_v2_deactivate');

function spm_v2_activate() {
    global $wpdb;

    // Criar tabela de fila
    $queue_table = $wpdb->prefix . 'spm_v2_queue';
    $charset    = $wpdb->get_charset_collate();
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
        'hosts'     => [],
        'security'  => [
            'jwt_secret' => bin2hex(random_bytes(32)),
            'force_ssl'  => true,
        ],
    ]);
}

function spm_v2_deactivate() {
    wp_clear_scheduled_hook('spm_v2_process_queue');
}

/** ================ CRON INTERVALS E SETTINGS ================ **/
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

function spm_v2_sanitize_settings($input) {
    $input['auto_sync'] = !empty($input['auto_sync']);
    if (!empty($input['hosts']) && is_array($input['hosts'])) {
        foreach ($input['hosts'] as &$h) {
            $h['url']    = esc_url_raw($h['url']);
            $h['secret'] = sanitize_text_field($h['secret']);
        }
    } else {
        $input['hosts'] = [];
    }
    $input['security']['jwt_secret'] = sanitize_text_field($input['security']['jwt_secret']);
    $input['security']['force_ssl']  = !empty($input['security']['force_ssl']);
    return $input;
}

/** ================= CLASSE PRINCIPAL ================= **/
class SPM_v2_Core {
    private $settings;
    private $queue_table;
    private $sync_meta   = '_spm_v2_sync_data';
    private $max_retries = 3;
    private $base_delay  = 300;

    public function __construct() {
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings    = get_option('spm_v2_settings');
        $this->init_hooks();
    }

    private function init_hooks() {
        // Salvar post
        add_action('save_post',                     [$this, 'handle_post_save'], 10, 3);
        add_action('spm_v2_process_queue',          [$this, 'process_queue']);
        // Admin
        add_action('admin_menu',                    [$this, 'add_admin_menu']);
        add_action('add_meta_boxes',                [$this, 'add_meta_box']);
        add_action('admin_enqueue_scripts',         [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_spm_v2_manual_sync',    [$this, 'handle_manual_sync']);
        // REST
        add_action('rest_api_init',                 [$this, 'register_endpoints']);
        // Cron
        if (!wp_next_scheduled('spm_v2_process_queue')) {
            wp_schedule_event(time(), 'five_minutes', 'spm_v2_process_queue');
        }
    }

    /** ============== ADMIN MENU ============== **/
    public function add_admin_menu() {
        add_menu_page(
            spmv2__('Sync Settings'),
            spmv2__('Sync Master'),
            'manage_options',
            'spm-v2-settings',
            [$this, 'render_settings_page'],
            'dashicons-update',
            80
        );
    }

    public function render_settings_page() {
        $s = $this->settings;
        ?>
        <div class="wrap">
            <h1><?php spmv2_e('Sync Settings'); ?></h1>
            <form method="post" action="options.php">
                <?php
                settings_fields('spm_v2_settings_group');
                do_settings_sections('spm_v2_settings_group');
                ?>
                
                <h2><?php spmv2_e('General Settings'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php spmv2_e('Auto Sync'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spm_v2_settings[auto_sync]"
                                    <?php checked($s['auto_sync'], true); ?>>
                                <?php spmv2_e('Enable auto sync'); ?>
                            </label>
                        </td>
                    </tr>
                </table>
                
                <h2><?php spmv2_e('Remote Sites'); ?></h2>
                <div id="spm-hosts-container">
                    <?php foreach ($s['hosts'] as $i => $h): ?>
                    <div class="spm-host-entry">
                        <input type="url" name="spm_v2_settings[hosts][<?= $i ?>][url]"
                            value="<?= esc_url($h['url']) ?>"
                            placeholder="https://site-remoto.com" required>
                        <input type="text" name="spm_v2_settings[hosts][<?= $i ?>][secret]"
                            value="<?= esc_attr($h['secret']) ?>"
                            placeholder="<?php esc_attr_e('Chave Secreta', 'spmv2'); ?>" required>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="spm-add-host" class="button">
                    <?php spmv2_e('Add Host'); ?>
                </button>

                <h2><?php spmv2_e('Security'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php spmv2_e('JWT Secret'); ?></th>
                        <td>
                            <input type="text" name="spm_v2_settings[security][jwt_secret]"
                                value="<?= esc_attr($s['security']['jwt_secret']) ?>"
                                class="regular-text" readonly>
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
                                <input type="checkbox" name="spm_v2_settings[security][force_ssl]"
                                    <?php checked($s['security']['force_ssl'], true); ?>>
                                <?php spmv2_e('Force HTTPS'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <script>
            document.getElementById('spm-add-host').addEventListener('click', () => {
                const c = document.getElementById('spm-hosts-container');
                const i = c.children.length;
                const d = document.createElement('div');
                d.className = 'spm-host-entry';
                d.innerHTML = `
                    <input type="url" name="spm_v2_settings[hosts][${i}][url]" placeholder="https://site-remoto.com" required>
                    <input type="text" name="spm_v2_settings[hosts][${i}][secret]" placeholder="<?php esc_attr_e('Chave Secreta','spmv2')?>" required>
                `;
                c.appendChild(d);
            });
            function generateNewSecret() {
                const f = document.querySelector('[name="spm_v2_settings[security][jwt_secret]"]');
                const r = Array.from(crypto.getRandomValues(new Uint8Array(32)))
                            .map(b=>b.toString(16).padStart(2,'0')).join('');
                f.value = r;
            }
            </script>
        </div>
        <?php
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_spm-v2-settings') return;
        // aqui poderia enfileirar JS/CSS se fosse externo
    }

    /** ============== META BOX ============== **/
    public function add_meta_box() {
        add_meta_box(
            'spm_v2_sync_control',
            spmv2__('Sync Control'),
            [$this, 'render_meta_box'],
            'post',
            'side',
            'high'
        );
    }

    public function render_meta_box($post) {
        $sync_data = get_post_meta($post->ID, $this->sync_meta, true);
        $nonce     = wp_create_nonce('spm_v2_manual_sync');
        ?>
        <div class="spm-sync-control">
            <p>
                <label>
                    <?php $chk = empty($sync_data['disabled']); ?>
                    <input type="checkbox" id="spm_v2_auto_sync" <?= checked($chk, true, false) ?>>
                    <?php spmv2_e('Enable auto sync'); ?>
                </label>
            </p>
            <?php if (!empty($sync_data['hosts'])): ?>
            <div class="sync-status">
                <h4><?php spmv2_e('Last Sync'); ?></h4>
                <ul>
                <?php foreach ($sync_data['hosts'] as $h => $st): ?>
                    <li>
                        <strong><?= esc_html($h) ?>:</strong>
                        <?= $st['success'] ? '✅' : '❌' ?>
                        <small>(<?= date_i18n('d/m/Y H:i', $st['time']) ?>)</small>
                    </li>
                <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
            <p>
                <button type="button" class="button button-primary" 
                    id="spm_manual_sync_btn">
                    <?php spmv2_e('Sync Now'); ?>
                </button>
            </p>
        </div>
        <script>
        document.getElementById('spm_manual_sync_btn').addEventListener('click', () => {
            const data = new FormData();
            data.append('action', 'spm_v2_manual_sync');
            data.append('post_id', <?= $post->ID ?>);
            data.append('security', '<?= $nonce ?>');
            data.append('spm_v2_auto_sync', document.getElementById('spm_v2_auto_sync').checked ? '1' : '0');
            fetch(ajaxurl, { method:'POST', body: data })
                .then(r=>r.json())
                .then(json=>{
                    alert(json.data.message);
                    location.reload();
                });
        });
        </script>
        <?php
    }

    public function handle_manual_sync() {
        check_ajax_referer('spm_v2_manual_sync', 'security');
        $post_id = intval($_POST['post_id']);
        $post    = get_post($post_id);
        if (!$post || !current_user_can('edit_post', $post_id)) {
            wp_send_json_error(['message'=>spmv2__('Access Denied')]);
        }
        // Desabilitar sync se checkbox for falso
        $disabled = empty($_POST['spm_v2_auto_sync']);
        update_post_meta($post_id, $this->sync_meta, ['disabled'=>$disabled, 'hosts'=>[]]);
        if (!$disabled) {
            $this->add_post_to_queue($post_id);
        }
        wp_send_json_success(['message'=>spmv2__('Sync Started')]);
    }

    /** ============== SALVAR E FILA ============== **/
    public function handle_post_save($post_id, $post, $update) {
        if (
            wp_is_post_revision($post_id) ||
            wp_is_post_autosave($post_id) ||
            $post->post_status !== 'publish' ||
            !$this->settings['auto_sync']
        ) return;
        $meta = get_post_meta($post_id, $this->sync_meta, true);
        if (!empty($meta['disabled'])) return;
        $this->add_post_to_queue($post_id);
    }

    private function add_post_to_queue($post_id) {
        global $wpdb;
        foreach ($this->settings['hosts'] as $h) {
            $wpdb->insert($this->queue_table, [
                'post_id'      => $post_id,
                'host'         => $h['url'],
                'next_attempt' => current_time('mysql'),
            ]);
        }
        update_post_meta($post_id, $this->sync_meta, ['disabled'=>false,'hosts'=>[]]);
    }

    public function process_queue() {
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $this->queue_table WHERE next_attempt<=%s ORDER BY next_attempt ASC LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);
        foreach ($jobs as $j) {
            $this->process_job($j);
        }
    }

    private function process_job($job) {
        global $wpdb;
        $post = get_post($job['post_id']);
        $cfg  = $this->get_host_config($job['host']);
        try {
            if ($post && $cfg) {
                $this->sync_post($post, $cfg);
                $this->record_sync_success($job['post_id'], $cfg['url']);
                $wpdb->delete($this->queue_table,['id'=>$job['id']]);
            }
        } catch (Exception $e) {
            $this->handle_sync_error($job, $e->getMessage());
        }
    }

    private function sync_post($post, $host) {
        $jwt = $this->generate_jwt($host['secret']);
        $response = wp_remote_post(trailingslashit($host['url']) . 'wp-json/spm/v2/sync', [
            'headers'=>[
                'Authorization'  => 'Bearer ' . $jwt,
                'X-SPM-Signature'=> $this->generate_signature($post, $host['secret']),
                'Content-Type'   => 'application/json',
            ],
            'body'   => wp_json_encode([
                'post' => $this->prepare_post_data($post),
                'meta' => $this->prepare_post_meta($post->ID),
            ]),
            'timeout'=>30,
        ]);
        $this->validate_response($response);
    }

    /** ============== REST ENDPOINTS ============== **/
    public function register_endpoints() {
        register_rest_route('spm/v2','/sync',[
            'methods'=>'POST',
            'callback'=>[$this,'handle_sync_request'],
            'permission_callback'=>[$this,'validate_request'],
        ]);
        register_rest_route('spm/v2','/media',[
            'methods'=>'POST',
            'callback'=>[$this,'handle_media_request'],
            'permission_callback'=>[$this,'validate_request'],
        ]);
    }

    public function validate_request($request) {
        $token     = str_replace('Bearer ','',$request->get_header('Authorization'));
        $signature = $request->get_header('X-SPM-Signature');
        foreach ($this->settings['hosts'] as $h) {
            if ($this->validate_jwt($token,$h['secret'])
             && $this->validate_signature($signature,$request->get_body(),$h['secret'])) {
                return true;
            }
        }
        return false;
    }

    public function handle_sync_request($request) {
        $data = $request->get_json_params();
        if (!empty($data['meta']['_spm_v2_sync_data'])) {
            return new WP_REST_Response(['error'=>spmv2__('Loop detected')],400);
        }
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($data['post']['post_title']),
            'post_content' => wp_kses_post($data['post']['post_content']),
            'post_status'  => 'publish',
            'post_type'    => $data['post']['post_type'] ?? 'post',
        ]);
        if (is_wp_error($post_id) || !$post_id) {
            return new WP_REST_Response(['error'=>spmv2__('Creation Failed')],500);
        }
        $this->update_post_meta($post_id,$data['meta']);
        return new WP_REST_Response(['success'=>true,'id'=>$post_id],201);
    }

    public function handle_media_request($request) {
        $raw       = $request->get_body();
        $hdr       = $request->get_header('Content-Disposition');
        // extrair filename de 'attachment; filename="foo.jpg"'
        if (preg_match('/filename="([^"]+)"/',$hdr,$m)) {
            $filename = sanitize_file_name($m[1]);
        } else {
            $filename = 'upload-'.time();
        }
        $upload    = wp_upload_bits($filename,null,$raw);
        if ($upload['error']) {
            return new WP_REST_Response(['error'=>spmv2__('Network Error').' '.$upload['error']],500);
        }
        $wp_filetype = wp_check_filetype($filename,null);
        $attachment  = [
            'guid'           => $upload['url'],
            'post_mime_type' => $wp_filetype['type'],
            'post_title'     => sanitize_file_name(pathinfo($filename,PATHINFO_FILENAME)),
            'post_content'   => '',
            'post_status'    => 'inherit'
        ];
        $attach_id = wp_insert_attachment($attachment,$upload['file']);
        require_once(ABSPATH.'wp-admin/includes/image.php');
        wp_update_attachment_metadata($attach_id, wp_generate_attachment_metadata($attach_id,$upload['file']));
        return new WP_REST_Response(['success'=>true,'id'=>$attach_id],201);
    }

    /** ============== UTILITÁRIOS ============== **/
    private function generate_jwt($secret) {
        $h = base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $p = base64Url(json_encode(['iss'=>get_site_url(),'iat'=>time(),'exp'=>time()+300]));
        $sig = hash_hmac('sha256',"$h.$p",$secret,true);
        return "$h.$p.".base64Url($sig);
    }

    private function generate_signature($post, $secret) {
        return hash_hmac('sha256',$post->ID.'|'.$post->post_modified,$secret);
    }

    private function validate_jwt($token, $secret) {
        list($h,$p,$sig) = explode('.',$token);
        $valid = hash_hmac('sha256',"$h.$p",$secret,true);
        $valid = base64Url($valid);
        $payload = json_decode(base64_decode(strtr($p,'-_','+/')),true);
        return hash_equals($sig,$valid) && !empty($payload['exp']) && $payload['exp']>time();
    }

    private function validate_signature($sig,$data,$secret) {
        return hash_equals(hash_hmac('sha256',$data,$secret),$sig);
    }

    private function get_host_config($url) {
        foreach ($this->settings['hosts'] as $h) {
            if ($h['url'] === $url) return $h;
        }
        return null;
    }

    private function record_sync_success($post_id,$host) {
        $d = get_post_meta($post_id,$this->sync_meta,true);
        $d['hosts'][$host] = ['success'=>true,'time'=>time()];
        update_post_meta($post_id,$this->sync_meta,$d);
    }

    private function handle_sync_error($job,$err) {
        global $wpdb;
        $n = $job['attempts']+1;
        $next = time() + ($this->base_delay * pow(2,$n));
        $wpdb->update($this->queue_table,[
            'attempts'=>$n,
            'last_attempt'=>current_time('mysql'),
            'next_attempt'=>date('Y-m-d H:i:s',$next),
        ],['id'=>$job['id']]);
        error_log("SPM v2 Sync Error (Post {$job['post_id']}): $err");
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
        $out = [];
        $all = get_post_meta($post_id);
        foreach ($all as $k=>$v) {
            if (strpos($k,'_spm_v2_')===0) continue;
            $out[$k] = array_map('maybe_unserialize',$v);
        }
        return $out;
    }

    private function update_post_meta($post_id,$meta) {
        foreach ($meta as $k=>$vals) {
            if (strpos($k,'_spm_v2_')===0) continue;
            delete_post_meta($post_id,$k);
            foreach ((array)$vals as $v) add_post_meta($post_id,$k,$v);
        }
    }

    private function get_author_data($aid) {
        $u = get_userdata($aid);
        return $u ? [
            'display_name'=>$u->display_name,
            'user_email'  =>$u->user_email,
            'user_login'  =>$u->user_login,
        ] : [];
    }
}

// helper
function base64Url($str){
    $enc = base64_encode($str);
    return rtrim(strtr($enc,'+/','-_'),'=');
}

/** ============== INICIALIZAÇÃO ============== **/
add_action('plugins_loaded', function(){
    new SPM_v2_Core();
});
