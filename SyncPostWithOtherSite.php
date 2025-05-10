<?php
/*
Plugin Name: Sync Post Master v2
Description: Sincronização completa com controle manual/automático e segurança reforçada
Version: 3.0.0
Author: Seu Nome
Text Domain: spmv2
*/

defined('ABSPATH') || exit;

// ------------------ LOG ------------------
if (!defined('SPMV2_LOG_FILE')) {
    define('SPMV2_LOG_FILE', WP_CONTENT_DIR . '/syncmasterlog.txt');
}
function spmv2_log($msg) {
    if (!is_string($msg)) $msg = print_r($msg, true);
    @file_put_contents(
        SPMV2_LOG_FILE,
        '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// -------------- ATIVAÇÃO / DESATIVAÇÃO --------------
register_activation_hook(__FILE__, function(){
    global $wpdb;
    $tbl = $wpdb->prefix . 'spm_v2_queue';
    $cs  = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $tbl (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        post_id BIGINT UNSIGNED NOT NULL,
        host VARCHAR(255) NOT NULL,
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        last_attempt DATETIME,
        next_attempt DATETIME NOT NULL,
        INDEX(post_id),
        INDEX(host)
    ) $cs;";
    require_once ABSPATH.'wp-admin/includes/upgrade.php';
    dbDelta($sql);

    add_option('spm_v2_settings', [
        'auto_sync' => true,
        'hosts'     => [],
        'security'  => [
            'jwt_secret'=> bin2hex(random_bytes(32)),
            'force_ssl' => true,
        ],
    ]);

    spmv2_log('Plugin ativado.');
});
register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('spm_v2_process_queue');
    spmv2_log('Plugin desativado.');
});

// ------------------ CRON INTERVAL ------------------
add_filter('cron_schedules', function($s){
    $s['five_minutes'] = ['interval'=>300,'display'=>'A cada 5 minutos'];
    return $s;
});

// ---------------- PLUGIN CORE ----------------
class SPM_v2_Core {
    private $settings, $queue_table;
    private $meta_key  = '_spm_v2_sync_data';
    private $delay     = 300;

    public function __construct(){
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings    = get_option('spm_v2_settings');
        $this->init_hooks();
    }

    private function init_hooks(){
        add_action('save_post',                  [$this,'on_save'],10,3);
        add_action('spm_v2_process_queue',       [$this,'process_queue']);
        add_action('add_meta_boxes',             [$this,'add_meta_box']);
        add_action('wp_ajax_spm_v2_manual_sync', [$this,'manual_sync']);
        add_action('rest_api_init',              [$this,'register_api']);
        if (!wp_next_scheduled('spm_v2_process_queue')) {
            wp_schedule_event(time(),'five_minutes','spm_v2_process_queue');
        }
    }

    // --- Meta Box ---
    public function add_meta_box(){
        add_meta_box('spm_sync_control','Sync Now',[$this,'render_box'],'post','side','high');
    }
    public function render_box($post){
        $nonce = wp_create_nonce('spm_v2_manual_sync');
        echo '<button id="spm_sync_btn" class="button button-primary">Sync Now</button>';
        ?>
        <script>
        document.getElementById('spm_sync_btn').onclick = function(){
            var f = new FormData();
            f.append('action','spm_v2_manual_sync');
            f.append('post_id',<?= $post->ID ?>);
            f.append('security','<?= $nonce ?>');
            fetch(ajaxurl,{method:'POST',body:f})
              .then(function(){ location.reload(); });
        };
        </script>
        <?php
    }

    // --- Manual Sync AJAX ---
    public function manual_sync(){
        check_ajax_referer('spm_v2_manual_sync','security');
        $pid = intval($_POST['post_id']);
        if (!current_user_can('edit_post',$pid)) wp_send_json_error();
        $this->enqueue($pid);
        wp_send_json_success();
    }

    // --- on save_post ---
    public function on_save($post_id, $post, $update){
        if (!$this->settings['auto_sync']) return;
        if ($post->post_status !== 'publish' || wp_is_post_revision($post_id)) return;
        $this->enqueue($post_id);
    }

    private function enqueue($post_id){
        global $wpdb;
        foreach($this->settings['hosts'] as $h){
            $wpdb->insert($this->queue_table, [
                'post_id'      => $post_id,
                'host'         => $h['url'],
                'next_attempt' => current_time('mysql'),
            ]);
        }
        spmv2_log("Enqueued post {$post_id}");
    }

    // --- process_queue ---
    public function process_queue(){
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table}
             WHERE next_attempt<=%s
             ORDER BY next_attempt ASC
             LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);

        foreach($jobs as $job){
            $this->process_job($job);
        }
    }
    private function process_job($job){
        try {
            $post = get_post($job['post_id']);
            $cfg  = $this->get_host_config($job['host']);
            if ($post && $cfg) {
                $this->sync_post($post, $cfg);
                $this->record_sync_success($job['post_id'], $job['host']);
                global $wpdb;
                $wpdb->delete($this->queue_table, ['id'=>$job['id']]);
            }
        } catch (\Exception $e) {
            spmv2_log("Error job{$job['id']}: ".$e->getMessage());
        }
    }

    // --- sync_post + validate_response ---
    private function sync_post($post, $cfg){
        $jwt = $this->generate_jwt($cfg['secret']);
        $payload = [
            'post' => $this->prepare_post_data($post),
            'meta' => $this->prepare_post_meta($post->ID),
        ];

        $response = wp_remote_post(rtrim($cfg['url'],'/').'/wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization'   => 'Bearer '.$jwt,
                'X-SPM-Signature' => $this->generate_signature($post, $cfg['secret']),
                'Content-Type'    => 'application/json',
            ],
            'body'    => wp_json_encode($payload),
            'timeout' => 30,
        ]);

        $this->validate_response($response);
    }

    private function validate_response($response){
        if (is_wp_error($response)) {
            throw new \Exception('Erro de rede: '.$response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 201 || empty($body['success'])) {
            throw new \Exception('Falha na sincronização. Status: '.$code);
        }
    }

    // --- record_sync_success ---
    private function record_sync_success($post_id, $host){
        $sync_data = get_post_meta($post_id, $this->meta_key, true);
        $sync_data['hosts'][$host] = [
            'success' => true,
            'time'    => time()
        ];
        update_post_meta($post_id, $this->meta_key, $sync_data);
        spmv2_log("Success post {$post_id} @ {$host}");
    }

    // --- REST API endpoint ---
    public function register_api(){
        register_rest_route('spm/v2','/sync',[
            'methods'  => 'POST',
            'callback' => [$this,'handle_sync_request'],
            'permission_callback' => fn()=>true,
        ]);
    }
    public function handle_sync_request($request){
        $data = $request->get_json_params();
        if (!empty($data['meta'][$this->meta_key])) {
            return new WP_REST_Response(['error'=>'Loop detected'], 400);
        }
        $post_id = wp_insert_post([
            'post_title'   => sanitize_text_field($data['post']['post_title']),
            'post_content' => wp_kses_post($data['post']['post_content']),
            'post_status'  => 'publish',
            'post_type'    => $data['post']['post_type'] ?? 'post',
        ]);
        if (is_wp_error($post_id) || !$post_id) {
            return new WP_REST_Response(['error'=>'Creation Failed'], 500);
        }
        $this->update_post_meta($post_id, $data['meta']);
        return new WP_REST_Response(['success'=>true,'id'=>$post_id], 201);
    }

    // --- UTILITÁRIOS COMPLETOS ---
    private function get_host_config($url){
        foreach ($this->settings['hosts'] as $h) {
            if ($h['url'] === $url) return $h;
        }
        return null;
    }

    private function generate_jwt($secret){
        $header  = base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $payload = base64Url(json_encode([
            'iss'=>get_site_url(),
            'iat'=>time(),
            'exp'=>time()+300
        ]));
        $signature = base64Url(
            hash_hmac('sha256', "$header.$payload", $secret, true)
        );
        return "$header.$payload.$signature";
    }

    private function generate_signature($post, $secret){
        return hash_hmac('sha256', $post->ID.'|'.$post->post_modified, $secret);
    }

    private function validate_jwt($token, $secret){
        list($h,$p,$sig) = explode('.', $token);
        $valid = base64Url(hash_hmac('sha256', "$h.$p", $secret, true));
        $pl = json_decode(base64_decode(strtr($p,'-_','+/')), true);
        return hash_equals($sig, $valid) && !empty($pl['exp']) && $pl['exp'] > time();
    }

    private function validate_signature($signature, $data, $secret){
        return hash_equals(hash_hmac('sha256', $data, $secret), $signature);
    }

    private function prepare_post_data($post){
        return [
            'ID'            => $post->ID,
            'post_title'    => sanitize_text_field($post->post_title),
            'post_content'  => wp_kses_post($post->post_content),
            'post_excerpt'  => wp_kses_post($post->post_excerpt),
            'post_status'   => 'publish',
            'post_type'     => $post->post_type,
            'post_date'     => $post->post_date,
            'post_modified' => $post->post_modified,
            'post_author'   => $this->get_author_data($post->post_author),
        ];
    }

    private function prepare_post_meta($post_id){
        $out = [];
        $all = get_post_meta($post_id);
        foreach ($all as $key => $vals) {
            if (strpos($key, '_spm_v2_') === 0) continue;
            $out[$key] = array_map('maybe_unserialize', $vals);
        }
        return $out;
    }

    private function update_post_meta($post_id, $meta){
        foreach ($meta as $key => $vals) {
            if (strpos($key, '_spm_v2_') === 0) continue;
            delete_post_meta($post_id, $key);
            foreach ((array)$vals as $v) {
                add_post_meta($post_id, $key, $v);
            }
        }
    }

    private function get_author_data($author_id){
        $user = get_userdata($author_id);
        return $user ? [
            'display_name' => $user->display_name,
            'user_email'   => $user->user_email,
            'user_login'   => $user->user_login,
        ] : [];
    }
}

// --- base64url helper ---
function base64Url($data){
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// -------------- INITIALIZE --------------
add_action('plugins_loaded', function(){
    try {
        new SPM_v2_Core();
    } catch (\Throwable $e) {
        spmv2_log('INIT ERROR: '.$e->getMessage());
    }
});
