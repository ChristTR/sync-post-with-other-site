<?php
/*
Plugin Name: Sync Post Master v2
Description: Sincronização completa com controle manual/automático e segurança reforçada
Version: 3.0.0
Author: Seu Nome
Text Domain: spmv2
*/

defined('ABSPATH') || exit;

// -----------------------------------------
// LOG (sucesso e erro apenas)
// -----------------------------------------
// Define o arquivo de log
if (!defined('SPMV2_LOG_FILE')) {
    define('SPMV2_LOG_FILE', WP_CONTENT_DIR . '/syncmasterlog.txt');
}

// Função para registrar logs (sucesso/erro)
function spmv2_log($msg) {
    if (!is_string($msg)) $msg = print_r($msg, true);
    
    @file_put_contents(
        SPMV2_LOG_FILE,
        '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

// Função para renderizar a página do log visual
function spm_v2_render_log_page() {
    $log_file = SPMV2_LOG_FILE; // Caminho do arquivo de log
    
    if (file_exists($log_file)) {
        $logs = file_get_contents($log_file);
    } else {
        $logs = 'No logs available.';
    }
    
    // Filtragem de logs por status
    if (isset($_GET['filter_status']) && $_GET['filter_status'] !== '') {
        $status_filter = $_GET['filter_status'];
        $logs = preg_replace("/\[.*\].*\b$status_filter\b.*/", '', $logs);
    }
    
    // Filtragem por data
    if (isset($_GET['filter_date']) && $_GET['filter_date'] !== '') {
        $date_filter = $_GET['filter_date'];
        $logs = preg_replace("/\[.*\].*\b$date_filter\b.*/", '', $logs);
    }

    // Exibe a página de log
    echo '<div class="wrap">';
    echo '<h1>Log Visual</h1>';

    // Formulário de filtragem
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="spm-v2-log">';
    echo '<label for="filter_status">Filter by Status:</label>';
    echo '<select name="filter_status">
            <option value="">All</option>
            <option value="success">Success</option>
            <option value="error">Error</option>
          </select>';
    echo '<label for="filter_date">Filter by Date:</label>';
    echo '<input type="date" name="filter_date">';
    echo '<input type="submit" value="Filter" class="button">';
    echo '</form>';

    // Exibe os logs filtrados
    echo '<textarea rows="20" cols="100" readonly>' . esc_textarea($logs) . '</textarea>';
    echo '</div>';
}

// -----------------------------------------
// ATIVAÇÃO / DESATIVAÇÃO
// -----------------------------------------
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
    spmv2_log('Plugin ativado.');
});

register_deactivation_hook(__FILE__, function(){
    wp_clear_scheduled_hook('spm_v2_process_queue');
    spmv2_log('Plugin desativado.');
});

// -----------------------------------------
// MENU DE CONFIGURAÇÕES
// -----------------------------------------
add_action('admin_menu', function() {
    add_menu_page(
        'Sync Master',
        'Sync Master',
        'manage_options',
        'spm-v2-settings',
        'spm_v2_render_settings_page',
        'dashicons-update',
        80
    );
    
    add_submenu_page(
        'spm-v2-settings',
        'Log Visual',
        'Log Visual',
        'manage_options',
        'spm-v2-log',
        'spm_v2_render_log_page'
    );
});

add_action('admin_init', function(){
    register_setting('spm_v2_group','spm_v2_settings','spm_v2_sanitize_settings');
});
function spm_v2_sanitize_settings($in){
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
function spm_v2_render_settings_page(){
    $s = get_option('spm_v2_settings');
    ?>
    <div class="wrap">
      <h1>Sync Master Settings</h1>
      <form method="post" action="options.php">
        <?php settings_fields('spm_v2_group'); ?>
        <h2>General</h2>
        <table class="form-table">
          <tr>
            <th>Auto Sync</th>
            <td>
              <label>
                <input type="checkbox" name="spm_v2_settings[auto_sync]" <?php checked($s['auto_sync'],true); ?>>
                Enable automatic sync on post save
              </label>
            </td>
          </tr>
        </table>
        <h2>Remote Hosts</h2>
        <div id="spm-hosts">
          <?php foreach($s['hosts'] as $i=>$h): ?>
          <div class="host-entry">
            <input type="url" name="spm_v2_settings[hosts][<?= $i ?>][url]" value="<?= esc_url($h['url']) ?>" placeholder="https://remote.com" required>
            <input type="text" name="spm_v2_settings[hosts][<?= $i ?>][secret]" value="<?= esc_attr($h['secret']) ?>" placeholder="Secret" required>
          </div>
          <?php endforeach; ?>
        </div>
        <button type="button" id="spm-add-host" class="button">Add Host</button>
        <h2>Security</h2>
        <table class="form-table">
          <tr>
            <th>JWT Secret</th>
            <td>
              <input type="text" name="spm_v2_settings[security][jwt_secret]" value="<?= esc_attr($s['security']['jwt_secret']) ?>" readonly class="regular-text">
              <button type="button" class="button button-small" onclick="spm_v2_new_secret()">Generate New</button>
            </td>
          </tr>
          <tr>
            <th>Force HTTPS</th>
            <td>
              <label>
                <input type="checkbox" name="spm_v2_settings[security][force_ssl]" <?php checked($s['security']['force_ssl'],true); ?>>
                Enable force SSL on REST requests
              </label>
            </td>
          </tr>
        </table>
        <?php submit_button(); ?>
      </form>
    </div>
    <script>
    document.getElementById('spm-add-host').onclick = function(){
      var c = document.getElementById('spm-hosts'), i = c.children.length;
      var div = document.createElement('div');
      div.className='host-entry';
      div.innerHTML = '<input type="url" name="spm_v2_settings[hosts]['+i+'][url]" placeholder="https://remote.com" required> ' +
                      '<input type="text" name="spm_v2_settings[hosts]['+i+'][secret]" placeholder="Secret" required>';
      c.appendChild(div);
    };
    function spm_v2_new_secret(){
      var f = document.querySelector('[name="spm_v2_settings[security][jwt_secret]"]');
      var r = Array.from(crypto.getRandomValues(new Uint8Array(32)))
                   .map(b=>b.toString(16).padStart(2,'0')).join('');
      f.value = r;
    }
    </script>
    <?php
}

// -----------------------------------------
// CLASSE PRINCIPAL
// -----------------------------------------
class SPM_v2_Core {
    private $settings, $queue_table, $meta_key = '_spm_v2_sync_data';

    public function __construct(){
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings    = get_option('spm_v2_settings');
        $this->hooks();
    }

    private function hooks(){
        add_action('save_post',                  [$this,'on_save'],10,3);
        add_action('spm_v2_process_queue',       [$this,'process_queue']);
        add_action('add_meta_boxes',             [$this,'add_meta_box']);
        add_action('wp_ajax_spm_v2_manual_sync', [$this,'manual_sync']);
        add_action('rest_api_init',              [$this,'register_rest']);
    }

    // Save post
public function on_save($post_id, $post, $update){
    if (!$this->settings['auto_sync']) return;
    if ($post->post_status!=='publish' || wp_is_post_revision($post_id)) return;
    $this->enqueue($post_id);
    $this->process_queue(); // <--- Adiciona esta linha para processar imediatamente
}

    // Enqueue
private function enqueue($post_id){
    global $wpdb;
    foreach($this->settings['hosts'] as $h){
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->queue_table} WHERE post_id = %d AND host = %s",
            $post_id, $h['url']
        ));
        if (!$exists) {
            $wpdb->insert($this->queue_table, [
                'post_id'      => $post_id,
                'host'         => $h['url'],
                'next_attempt' => current_time('mysql'),
            ]);
            spmv2_log("Enqueued post {$post_id} for host {$h['url']}");
        }
    }
}

    // Process queue
    public function process_queue(){
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table} WHERE next_attempt<=%s ORDER BY next_attempt ASC LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);
        foreach($jobs as $job){
            $this->process_job($job);
        }
    }

private function process_job($job){
    try {
        $post = get_post($job['post_id']);
        $cfg  = $this->get_host($job['host']);
        if ($post && $cfg) {
            $this->sync_post($post, $cfg);
            $this->record_success($job['post_id'], $job['host']);
            global $wpdb;
            $wpdb->delete($this->queue_table, ['id' => $job['id']]);
        }
    } catch (\Exception $e) {
        spmv2_log("Error processing job {$job['id']}: ".$e->getMessage());
        // Log adicional ou ação caso necessário
    } catch (\Throwable $t) {
        spmv2_log("Unexpected error processing job {$job['id']}: ".$t->getMessage());
    }
}

    // Manual sync via AJAX
    public function add_meta_box(){
        add_meta_box('spm_sync','Sync Now',[$this,'render_meta_box'],'post','side','high');
    }
    public function render_meta_box($post){
        $nonce = wp_create_nonce('spm_v2_manual_sync');
        echo '<button id="spm_sync_btn" class="button button-primary">Sync Now</button>';
        ?>
        <script>
        document.getElementById('spm_sync_btn').onclick = function(){
            var f=new FormData();
            f.append('action','spm_v2_manual_sync');
            f.append('post_id',<?=$post->ID?>);
            f.append('security','<?= $nonce ?>');
            fetch(ajaxurl,{method:'POST',body:f})
              .then(function(){ location.reload(); });
        };
        </script>
        <?php
    }
    public function manual_sync(){
        check_ajax_referer('spm_v2_manual_sync','security');
        $pid = intval($_POST['post_id']);
        if (!current_user_can('edit_post',$pid)) wp_send_json_error();
        $this->enqueue($pid);
        wp_send_json_success();
    }

    // REST endpoint
    public function register_rest(){
        register_rest_route('spm/v2','/sync',[
            'methods'             => 'POST',
            'callback'            => [$this,'rest_sync'],
            'permission_callback' => fn()=>true,
        ]);
    }
    public function rest_sync($request){
        $data = $request->get_json_params();
        if (!empty($data['meta'][$this->meta_key])) {
            return new WP_REST_Response(['error'=>'Loop'],400);
        }
        $pid = wp_insert_post([
            'post_title'   => sanitize_text_field($data['post']['post_title']),
            'post_content' => wp_kses_post($data['post']['post_content']),
            'post_status'  => 'publish',
            'post_type'    => $data['post']['post_type'] ?? 'post',
        ]);
        if (is_wp_error($pid) || !$pid) {
            return new WP_REST_Response(['error'=>'Creation Failed'],500);
        }
        $this->update_post_meta($pid,$data['meta']);
        return new WP_REST_Response(['success'=>true,'id'=>$pid],201);
    }

    // Actual sync
private function sync_post($post, $h){
    $jwt = $this->generate_jwt($h['secret']);
    $payload = [
        'post' => $this->prepare_post_data($post),
        'meta' => $this->prepare_post_meta($post->ID),
    ];
    $response = wp_remote_post(rtrim($h['url'],'/').'/wp-json/spm/v2/sync',[
        'headers'=>[
            'Authorization'=>'Bearer '.$jwt,
            'X-SPM-Signature'=>$this->generate_signature($post,$h['secret']),
            'Content-Type'=>'application/json',
        ],
        'body'=>wp_json_encode($payload),
        'timeout'=>30,
    ]);
    $code = wp_remote_retrieve_response_code($response);
    if (is_wp_error($response) || !in_array($code, [200, 201], true)) {
        spmv2_log("Error syncing post {$post->ID} to {$h['url']}. Status: $code");
        throw new \Exception('Sync failed. Status: '.$code);
    }
}

    private function record_success($post_id,$host){
        $d = get_post_meta($post_id,$this->meta_key,true);
        $d['hosts'][$host] = ['time'=>time()];
        update_post_meta($post_id,$this->meta_key,$d);
        spmv2_log("Success post {$post_id} @ {$host}");
    }

    // UTILITÁRIOS
    private function get_host($url){
        foreach($this->settings['hosts'] as $h){
            if ($h['url'] === $url) return $h;
        }
        return null;
    }
    private function generate_jwt($secret){
        $h = base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $p = base64Url(json_encode(['iss'=>get_site_url(),'iat'=>time(),'exp'=>time()+300]));
        $sig = base64Url(hash_hmac('sha256',"$h.$p",$secret,true));
        return "$h.$p.$sig";
    }
    private function generate_signature($post,$secret){
        return hash_hmac('sha256',$post->ID.'|'.$post->post_modified,$secret);
    }
    private function prepare_post_data($post){
        return [
            'ID'=>$post->ID,
            'post_title'=>$post->post_title,
            'post_content'=>$post->post_content,
            'post_excerpt'=>$post->post_excerpt,
            'post_type'=>$post->post_type,
            'post_date'=>$post->post_date,
            'post_modified'=>$post->post_modified,
            'post_author'=>$this->get_author_data($post->post_author),
        ];
    }
    private function prepare_post_meta($id){
        $out=[];foreach(get_post_meta($id) as $k=>$v){
            if (strpos($k,$this->meta_key)===0) continue;
            $out[$k] = array_map('maybe_unserialize',$v);
        }
        return $out;
    }
    private function update_post_meta($id,$meta){
        foreach($meta as $k=>$vals){
            if (strpos($k,$this->meta_key)===0) continue;
            delete_post_meta($id,$k);
            foreach((array)$vals as $v) add_post_meta($id,$k,$v);
        }
    }
    private function get_author_data($aid){
        $u = get_userdata($aid);
        return $u?[
            'display_name'=>$u->display_name,
            'user_email'=>$u->user_email,
            'user_login'=>$u->user_login
        ]:[];
    }
}

// base64url helper
function base64Url($d){
    return rtrim(strtr(base64_encode($d), '+/','-_'),'=');
}

// INICIALIZAÇÃO
add_action('plugins_loaded', function(){
    new SPM_v2_Core();
});
