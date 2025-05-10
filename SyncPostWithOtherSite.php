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

// --------------------- LOG ---------------------
if ( ! defined('SPMV2_LOG_FILE') ) {
    define('SPMV2_LOG_FILE', WP_CONTENT_DIR . '/syncmasterlog.txt');
}
function spmv2_log($msg) {
    if (!is_string($msg)) $msg = print_r($msg, true);
    @file_put_contents(
        SPMV2_LOG_FILE,
        '['.date('Y-m-d H:i:s').'] '.$msg.PHP_EOL,
        FILE_APPEND|LOCK_EX
    );
}

// ---------------- TRADUÇÕES ----------------
$spmv2_translations = [
    'pt_BR' => [
        'Sync Settings'   => 'Configurações de Sincronização',
        'Sync Master'     => 'Gerenciador de Sincronia',
        'General Settings'=> 'Configurações Gerais',
        'Auto Sync'       => 'Sincronização Automática',
        'Enable auto sync'=> 'Ativar sincronização automática',
        'Remote Sites'    => 'Sites Remotos',
        'Security'        => 'Segurança',
        'JWT Secret'      => 'Chave Secreta JWT',
        'Generate New'    => 'Gerar Nova Chave',
        'Force HTTPS'     => 'Forçar HTTPS',
        'Sync Control'    => 'Controle de Sincronia',
        'Last Sync'       => 'Última Sincronização',
        'Sync Now'        => 'Sincronizar Agora',
        'Access Denied'   => 'Acesso Negado',
        'Sync Started'    => 'Sincronização Iniciada!',
        'Loop detected'   => 'Loop detectado',
        'Creation Failed' => 'Falha na criação',
    ],
];
function spmv2_translate($t) {
    global $spmv2_translations;
    $loc = get_locale();
    return $spmv2_translations[$loc][$t] ?? $t;
}
function spmv2__($t){ return spmv2_translate($t); }
function spmv2_e($t){ echo spmv2_translate($t); }

// ------------- ATIVAÇÃO / DESATIVAÇÃO -------------
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

    spmv2_log('Plugin ativado.');
}

function spm_v2_deactivate() {
    wp_clear_scheduled_hook('spm_v2_process_queue');
    spmv2_log('Plugin desativado.');
}

// ------------- CRON & SETTINGS -------------
add_filter('cron_schedules', function($s){
    $s['five_minutes'] = ['interval'=>5*60,'display'=>__('A cada 5 minutos')];
    return $s;
});
add_action('admin_init', function(){
    register_setting(
        'spm_v2_settings_group',
        'spm_v2_settings',
        ['type'=>'array','sanitize_callback'=>'spm_v2_sanitize_settings']
    );
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

// ------------- CLASSE PRINCIPAL -------------
class SPM_v2_Core {
    private $settings, $queue_table;
    private $sync_meta  = '_spm_v2_sync_data';
    private $base_delay = 300;

    public function __construct(){
        global $wpdb;
        $this->queue_table = $wpdb->prefix . 'spm_v2_queue';
        $this->settings    = get_option('spm_v2_settings', [
            'auto_sync'=>false,'hosts'=>[],'security'=>[]
        ]);
        $this->init_hooks();
    }

    private function init_hooks(){
        add_action('save_post',                  [$this,'handle_post_save'],10,3);
        add_action('spm_v2_process_queue',       [$this,'process_queue']);
        add_action('admin_menu',                 [$this,'add_admin_menu']);
        add_action('add_meta_boxes',             [$this,'add_meta_box']);
        add_action('wp_ajax_spm_v2_manual_sync', [$this,'handle_manual_sync']);
        add_action('rest_api_init',              [$this,'register_endpoints']);
        if (!wp_next_scheduled('spm_v2_process_queue')) {
            wp_schedule_event(time(),'five_minutes','spm_v2_process_queue');
        }
    }

    // --- Admin Menu ---
    public function add_admin_menu(){
        add_menu_page(
            spmv2__('Sync Settings'), spmv2__('Sync Master'),
            'manage_options','spm-v2-settings',
            [$this,'render_settings_page'],'dashicons-update',80
        );
    }
    public function render_settings_page(){
        $s = $this->settings; ?>
        <div class="wrap">
          <h1><?php spmv2_e('Sync Settings'); ?></h1>
          <form method="post" action="options.php">
            <?php settings_fields('spm_v2_settings_group'); ?>
            <h2><?php spmv2_e('General Settings'); ?></h2>
            <table class="form-table"><tr>
              <th><?php spmv2_e('Auto Sync'); ?></th>
              <td>
                <label>
                  <input type="checkbox" name="spm_v2_settings[auto_sync]" <?php checked($s['auto_sync'],true);?>>
                  <?php spmv2_e('Enable auto sync'); ?>
                </label>
              </td>
            </tr></table>
            <h2><?php spmv2_e('Remote Sites'); ?></h2>
            <div id="spm-hosts-container">
              <?php foreach($s['hosts'] as $i=>$h): ?>
              <div class="spm-host-entry">
                <input type="url" name="spm_v2_settings[hosts][<?=$i?>][url]" value="<?=esc_url($h['url'])?>" required>
                <input type="text" name="spm_v2_settings[hosts][<?=$i?>][secret]" value="<?=esc_attr($h['secret'])?>" required>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="button" id="spm-add-host" class="button"><?php spmv2_e('Add Host'); ?></button>
            <h2><?php spmv2_e('Security'); ?></h2>
            <table class="form-table"><tr>
              <th><?php spmv2_e('JWT Secret'); ?></th>
              <td>
                <input readonly class="regular-text"
                  name="spm_v2_settings[security][jwt_secret]"
                  value="<?=esc_attr($s['security']['jwt_secret'])?>">
                <button type="button" class="button button-small" onclick="genNew()">
                  <?php spmv2_e('Generate New'); ?>
                </button>
              </td>
            </tr><tr>
              <th><?php spmv2_e('Force HTTPS'); ?></th>
              <td>
                <label>
                  <input type="checkbox" name="spm_v2_settings[security][force_ssl]" <?php checked($s['security']['force_ssl'],true);?>>
                  <?php spmv2_e('Force HTTPS'); ?>
                </label>
              </td>
            </tr></table>
            <?php submit_button(); ?>
          </form>
        </div>
        <script>
        document.getElementById('spm-add-host').onclick = function(){
          var c=document.getElementById('spm-hosts-container'),i=c.children.length;
          var d=document.createElement('div');d.className='spm-host-entry';
          d.innerHTML='<input type="url" name="spm_v2_settings[hosts]['+i+'][url]" required>'+
                      '<input type="text" name="spm_v2_settings[hosts]['+i+'][secret]" required>';
          c.appendChild(d);
        };
        function genNew(){
          var f=document.querySelector('[name="spm_v2_settings[security][jwt_secret]"]');
          var r=Array.from(crypto.getRandomValues(new Uint8Array(32)))
                   .map(b=>b.toString(16).padStart(2,'0')).join('');
          f.value=r;
        }
        </script>
    <?php }

    // --- Meta Box ---
    public function add_meta_box(){
        add_meta_box('spm_v2_sync_control', spmv2__('Sync Control'),
            [$this,'render_meta_box'],'post','side','high');
    }
    public function render_meta_box($post){
        try {
            $data = get_post_meta($post->ID,$this->sync_meta,true);
            $nonce=wp_create_nonce('spm_v2_manual_sync');
            ?>
            <div class="spm-sync-control">
              <p><label>
                <input type="checkbox" id="spm_v2_auto_sync" <?=empty($data['disabled'])?'checked':''?>>
                <?php spmv2_e('Enable auto sync'); ?>
              </label></p>
              <?php if(!empty($data['hosts'])): ?>
              <div class="sync-status"><h4><?php spmv2_e('Last Sync');?></h4><ul>
                <?php foreach($data['hosts'] as $h=>$s): ?>
                  <li><strong><?=esc_html($h)?>:</strong>
                    <?=$s['success']?'✅':'❌'?> <small>(<?=date_i18n('d/m/Y H:i',$s['time'])?>)</small>
                  </li>
                <?php endforeach; ?></ul></div>
              <?php endif; ?>
              <p><button id="spm_manual_sync_btn" class="button button-primary"><?php spmv2_e('Sync Now');?></button></p>
            </div>
            <script>
            document.getElementById('spm_manual_sync_btn').onclick = function(){
              var d=new FormData();
              d.append('action','spm_v2_manual_sync');
              d.append('post_id',<?=$post->ID?>);
              d.append('security','<?=$nonce?>');
              d.append('spm_v2_auto_sync',
                document.getElementById('spm_v2_auto_sync').checked?1:0
              );
              fetch(ajaxurl,{method:'POST',body:d})
                .then(function(){ location.reload(); });
            };
            </script>
        <?php
        } catch (\Throwable $e) {
            spmv2_log('meta_box error: '.$e->getMessage());
        }
    }

    // --- Manual Sync AJAX ---
    public function handle_manual_sync(){
        check_ajax_referer('spm_v2_manual_sync','security');
        $pid = intval($_POST['post_id']);
        if (!current_user_can('edit_post',$pid)) {
            wp_send_json_error(['message'=>spmv2__('Access Denied')]);
        }
        $disabled = empty($_POST['spm_v2_auto_sync']);
        update_post_meta($pid,$this->sync_meta,['disabled'=>$disabled,'hosts'=>[]]);
        spmv2_log("manual_sync: post {$pid}, auto_sync=" . ($disabled?'0':'1'));
        if (!$disabled) {
            $this->add_post_to_queue($pid);
        }
        wp_send_json_success(['message'=>spmv2__('Sync Started')]);
    }

    // --- Save & Queue ---
    public function handle_post_save($id,$post,$u){
        spmv2_log("save_post: {$id}, status={$post->post_status}, upd=".($u?'1':'0'));
        if (wp_is_post_revision($id) || wp_is_post_autosave($id)
            || $post->post_status!=='publish'
            || ! $this->settings['auto_sync']
        ) {
            return;
        }
        $m = get_post_meta($id,$this->sync_meta,true);
        if (!empty($m['disabled'])) return;
        $this->add_post_to_queue($id);
    }
    private function add_post_to_queue($id){
        global $wpdb;
        $c = count($this->settings['hosts']);
        spmv2_log("enqueue: post {$id} → {$c} hosts");
        foreach($this->settings['hosts'] as $h){
            $wpdb->insert($this->queue_table,[
                'post_id'=>$id,
                'host'=>$h['url'],
                'next_attempt'=>current_time('mysql')
            ]);
        }
        update_post_meta($id,$this->sync_meta,['disabled'=>false,'hosts'=>[]]);
    }

    // --- Process Queue ---
    public function process_queue(){
        spmv2_log('process_queue start');
        global $wpdb;
        $jobs = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->queue_table}
             WHERE next_attempt<=%s
             ORDER BY next_attempt ASC
             LIMIT 10",
            current_time('mysql')
        ), ARRAY_A);
        foreach($jobs as $j){
            $this->process_job($j);
        }
    }
    private function process_job($job){
        spmv2_log("process_job: job #{$job['id']} post {$job['post_id']}");
        global $wpdb;
        try {
            $post = get_post($job['post_id']);
            $cfg  = $this->get_host_config($job['host']);
            if ($post && $cfg) {
                $this->sync_post($post,$cfg);
                $this->record_sync_success($job['post_id'],$cfg['url']);
                $wpdb->delete($this->queue_table,['id'=>$job['id']]);
            }
        } catch (\Exception $e) {
            $this->handle_sync_error($job,$e->getMessage());
        }
    }

    // --- Sync Post & Response Validation ---
    private function sync_post($post,$host){
        $jwt     = $this->generate_jwt($host['secret']);
        $payload = [
            'post'=>$this->prepare_post_data($post),
            'meta'=>$this->prepare_post_meta($post->ID),
        ];
        spmv2_log("sync_post → {$host['url']}: ".wp_json_encode($payload));
        $resp = wp_remote_post(rtrim($host['url'],'/').'/wp-json/spm/v2/sync',[
            'headers'=>[
                'Authorization'=>'Bearer '.$jwt,
                'X-SPM-Signature'=>$this->generate_signature($post,$host['secret']),
                'Content-Type'=>'application/json',
            ],
            'body'=>wp_json_encode($payload),
            'timeout'=>30,
        ]);
        spmv2_log("sync_post resp: ".wp_remote_retrieve_response_code($resp));
        $this->validate_response($resp);
    }
    private function validate_response($response) {
        if (is_wp_error($response)) {
            throw new Exception('Erro de rede: '.$response->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response),true);
        if ($code !== 201 || empty($body['success'])) {
            throw new Exception('Falha na sincronização. Status: '.$code);
        }
    }
    private function record_sync_success($id,$host){
        spmv2_log("record_sync_success: post {$id} em {$host}");
        $d = get_post_meta($id,$this->sync_meta,true);
        $d['hosts'][$host] = ['success'=>true,'time'=>time()];
        update_post_meta($id,$this->sync_meta,$d);
    }
    private function handle_sync_error($job,$err){
        spmv2_log("handle_sync_error: job #{$job['id']} post {$job['post_id']} — {$err}");
        global $wpdb;
        $a    = $job['attempts'] + 1;
        $next = time() + ($this->base_delay * pow(2,$a));
        $wpdb->update($this->queue_table,[
            'attempts'=>$a,
            'last_attempt'=>current_time('mysql'),
            'next_attempt'=>date('Y-m-d H:i:s',$next),
        ],['id'=>$job['id']]);
    }

    // --- REST API ---
    public function register_endpoints(){
        register_rest_route('spm/v2','/sync',[
            'methods'=>'POST',
            'callback'=>[$this,'handle_sync_request'],
            'permission_callback'=>[$this,'validate_request'],
        ]);
    }
    public function validate_request($req){
        $tok = str_replace('Bearer ','',$req->get_header('Authorization'));
        $sig = $req->get_header('X-SPM-Signature');
        foreach($this->settings['hosts'] as $h){
            if($this->validate_jwt($tok,$h['secret'])
               && $this->validate_signature($sig,$req->get_body(),$h['secret'])){
                return true;
            }
        }
        return false;
    }
    public function handle_sync_request($req){
        $d = $req->get_json_params();
        if (!empty($d['meta'][$this->sync_meta])) {
            return new WP_REST_Response(['error'=>spmv2__('Loop detected')],400);
        }
        $pid = wp_insert_post([
            'post_title'=>sanitize_text_field($d['post']['post_title']),
            'post_content'=>wp_kses_post($d['post']['post_content']),
            'post_status'=>'publish',
            'post_type'=>$d['post']['post_type'] ?? 'post',
        ]);
        if (is_wp_error($pid) || !$pid) {
            return new WP_REST_Response(['error'=>spmv2__('Creation Failed')],500);
        }
        $this->update_post_meta($pid,$d['meta']);
        return new WP_REST_Response(['success'=>true,'id'=>$pid],201);
    }

    // --- Helpers & Data Prep ---
    private function generate_jwt($sec){
        $h   = base64Url(json_encode(['alg'=>'HS256','typ'=>'JWT']));
        $p   = base64Url(json_encode(['iss'=>get_site_url(),'iat'=>time(),'exp'=>time()+300]));
        $sig = hash_hmac('sha256',"$h.$p",$sec,true);
        return "$h.$p.".base64Url($sig);
    }
    private function generate_signature($post,$sec){
        return hash_hmac('sha256',$post->ID.'|'.$post->post_modified,$sec);
    }
    private function validate_jwt($tok,$sec){
        list($h,$p,$sig) = explode('.',$tok);
        $v = base64Url(hash_hmac('sha256',"$h.$p",$sec,true));
        $pl= json_decode(base64_decode(strtr($p,'-_','+/')),true);
        return hash_equals($sig,$v) && !empty($pl['exp']) && $pl['exp']>time();
    }
    private function validate_signature($sig,$data,$sec){
        return hash_equals(hash_hmac('sha256',$data,$sec),$sig);
    }
    private function get_host_config($url){
        foreach($this->settings['hosts'] as $h){
            if($h['url']===$url) return $h;
        }
        return null;
    }
    private function prepare_post_data($post){
        return [ 
            'ID'=>$post->ID,
            'post_title'=>$post->post_title,
            'post_content'=>$post->post_content,
            'post_excerpt'=>$post->post_excerpt,
            'post_status'=>'publish',
            'post_type'=>$post->post_type,
            'post_date'=>$post->post_date,
            'post_modified'=>$post->post_modified,
            'post_author'=>$this->get_author_data($post->post_author),
        ];
    }
    private function prepare_post_meta($id){
        $out=[];foreach(get_post_meta($id)as$k=>$v){
            if(strpos($k,'_spm_v2_')===0) continue;
            $out[$k]=array_map('maybe_unserialize',$v);
        }return $out;
    }
    private function update_post_meta($id,$meta){
        foreach($meta as $k=>$vals){
            if(strpos($k,'_spm_v2_')===0) continue;
            delete_post_meta($id,$k);
            foreach((array)$vals as $v) add_post_meta($id,$k,$v);
        }
    }
    private function get_author_data($aid){
        $u = get_userdata($aid);
        return $u ? [
            'display_name'=>$u->display_name,
            'user_email'=>$u->user_email,
            'user_login'=>$u->user_login
        ] : [];
    }
}

// --- base64url helper ---
function base64Url($data){
    return rtrim(strtr(base64_encode($data),'+/','-_'),'=');
}

// --------------- INICIALIZAÇÃO ---------------
add_action('plugins_loaded', function(){
    try {
        new SPM_v2_Core();
    } catch (\Throwable $e) {
        spmv2_log('INIT ERROR: '.$e->getMessage());
    }
});
