<?php
/*
Plugin Name: Sync Master Pro
Description: Plugin de sincronização avançada entre sites WordPress
Version: 2.0
Author: Seu Nome
*/

// Segurança básica
defined('ABSPATH') || exit;

// -----------------------------------------
// ATIVAÇÃO/DESATIVAÇÃO
// -----------------------------------------
register_activation_hook(__FILE__, function() {
    if (!wp_next_scheduled('spm_v2_process_queue')) {
        wp_schedule_event(time(), 'hourly', 'spm_v2_process_queue');
    }
    flush_rewrite_rules();
});

register_deactivation_hook(__FILE__, function() {
    wp_clear_scheduled_hook('spm_v2_process_queue');
    flush_rewrite_rules();
    spmv2_log('Plugin desativado.');
});

// -----------------------------------------
// FUNÇÃO DE LOG
// -----------------------------------------
function spmv2_log($message, $data = null) {
    $log_entry = "[" . date('Y-m-d H:i:s') . "] " . $message . PHP_EOL;
    
    if ($data) {
        $log_entry .= "Detalhes: " . print_r($data, true) . PHP_EOL;
    }

    // Adicione isto APENAS se tiver a variável $final_author_id
    if (strpos($message, 'author') !== false && isset($data['final_author_id'])) {
        $log_entry .= "Autor Fallback Debug:\n";
        $log_entry .= "Usuário Original: " . print_r($data['author'], true) . "\n";
        $log_entry .= "Usuário Selecionado: " . get_user_by('ID', $data['final_author_id'])->display_name . "\n";
    }

    file_put_contents(WP_CONTENT_DIR . '/spm-v2.log', $log_entry, FILE_APPEND);
}


// -----------------------------------------
// CONFIGURAÇÕES DO PLUGIN
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
        'Logs',
        'Logs',
        'manage_options',
        'spm-v2-log',
        'spm_v2_render_log_page'
    );
});

add_action('admin_init', function() {
    register_setting('spm_v2_group', 'spm_v2_settings', 'spm_v2_sanitize_settings');

    // Seção: Configurações Gerais
    add_settings_section(
        'spm_v2_general',
        'Configurações Gerais',
        function() { echo '<p>Configurações principais da sincronização</p>'; },
        'spm-v2-settings'
    );

    add_settings_field(
        'auto_sync',
        'Sincronização Automática',
        'spm_v2_auto_sync_field',
        'spm-v2-settings',
        'spm_v2_general'
    );

    add_settings_field(
        'default_author',
        'Autor Padrão',
        'spm_v2_default_author_field',
        'spm-v2-settings',
        'spm_v2_general'
    );

    // Seção: Segurança
    add_settings_section(
        'spm_v2_security',
        'Configurações de Segurança',
        function() { echo '<p>Configurações de autenticação e conexão</p>'; },
        'spm-v2-settings'
    );

    add_settings_field(
        'jwt_secret',
        'Segredo JWT',
        'spm_v2_jwt_secret_field',
        'spm-v2-settings',
        'spm_v2_security'
    );

    add_settings_field(
        'force_ssl',
        'Forçar SSL',
        'spm_v2_force_ssl_field',
        'spm-v2-settings',
        'spm_v2_security'
    );
});

// Adicione esta nova função para o campo Force SSL
function spm_v2_force_ssl_field() {
    $settings = get_option('spm_v2_settings');
    ?>
    <label>
        <input type="checkbox" 
               name="spm_v2_settings[security][force_ssl]" 
               <?php checked($settings['security']['force_ssl'] ?? false, true); ?>>
        Exigir conexões HTTPS
    </label>
    <?php
}
// Campos de configuração
function spm_v2_auto_sync_field() {
    $settings = get_option('spm_v2_settings');
    ?>
    <label>
        <input type="checkbox" name="spm_v2_settings[auto_sync]" <?php checked($settings['auto_sync'] ?? false, true); ?>>
        Ativar sincronização automática ao salvar posts
    </label>
    <?php
}

function spm_v2_jwt_secret_field() {
    $settings = get_option('spm_v2_settings');
    ?>
    <input type="text" name="spm_v2_settings[security][jwt_secret]" 
           value="<?= esc_attr($settings['security']['jwt_secret'] ?? '') ?>" 
           class="regular-text" required>
    <p class="description">Chave secreta para autenticação JWT</p>
    <?php
}

function spm_v2_default_author_field() {
    $settings = get_option('spm_v2_settings');
    $selected = $settings['default_author'] ?? get_current_user_id();
    
    wp_dropdown_users([
        'name' => 'spm_v2_settings[default_author]',
        'selected' => $selected,
        'show_option_none' => 'Selecione um autor',
        'option_none_value' => ''
    ]);
    
    if (!get_user_by('ID', $selected)) {
        echo '<p class="error">⚠️ Usuário não existe! Usando admin atual.</p>';
    }
}

// Sanitização
function spm_v2_sanitize_settings($input) {
    // Sincronização automática
    $input['auto_sync'] = isset($input['auto_sync']);

    // Hosts remotos
    $input['hosts'] = array_values(array_filter($input['hosts'] ?? [], function($host) {
        if (!empty($host['url']) && !empty($host['secret'])) {
            return wp_http_validate_url($host['url']) !== false;
        }
        return false;
    }));

    // Segurança
    $input['security']['jwt_secret'] = sanitize_text_field($input['security']['jwt_secret'] ?? '');
    $input['security']['force_ssl'] = isset($input['security']['force_ssl']);

    // Autor padrão
    $input['default_author'] = absint($input['default_author'] ?? get_current_user_id());
    if (!get_user_by('ID', $input['default_author'])) {
        $input['default_author'] = get_current_user_id();
        add_settings_error('spm_v2_settings', 'invalid-author', 'Autor inválido, usando usuário atual');
    }

    // Fallback para admin
    $input['use_admin_fallback'] = isset($input['use_admin_fallback']);

    // Verificação de capacidade
    if ($user = get_user_by('ID', $input['default_author'])) {
        if (!user_can($user, 'publish_posts')) {
            add_settings_error(
                'spm_v2_settings', 
                'invalid-author-capability', 
                'O autor padrão não tem permissão para publicar posts'
            );
            $input['default_author'] = get_current_user_id();
        }
    }

    return $input;
}

// Página de configurações
function spm_v2_render_settings_page() {
    $settings = wp_parse_args(
        get_option('spm_v2_settings', []),
        [
            'hosts' => [],
            'security' => [
                'jwt_secret' => '',
                'force_ssl' => false
            ],
            'default_author' => get_current_user_id(),
            'auto_sync' => false
        ]
    );
    ?>
    <div class="wrap">
        <h1>Configurações Sync Master</h1>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('spm_v2_group');
            do_settings_sections('spm-v2-settings'); // Isso renderiza as seções registradas
            ?>
            
            <h2>Hosts Remotos</h2>
            <div id="spm-hosts">
                <?php foreach ($settings['hosts'] as $i => $host) : ?>
                    <div class="host-entry">
                        <input type="url" 
                               name="spm_v2_settings[hosts][<?= esc_attr($i) ?>][url]" 
                               value="<?= esc_url($host['url']) ?>" 
                               placeholder="https://exemplo.com" 
                               required>
                        <input type="text" 
                               name="spm_v2_settings[hosts][<?= esc_attr($i) ?>][secret]" 
                               value="<?= esc_attr($host['secret']) ?>" 
                               placeholder="Chave secreta" 
                               required>
                        <button type="button" class="button remove-host">Remover</button>
                    </div>
                <?php endforeach; ?>
            </div>
            <button type="button" id="add-host" class="button">Adicionar Host</button>

            <?php submit_button('Salvar Configurações', 'primary large'); ?>
        </form>

        <script>/* JavaScript mantido */</script>
    </div>
    <?php
}

// -----------------------------------------
// SINCRONIZAÇÃO MANUAL (META BOX)
// -----------------------------------------
add_action('add_meta_boxes', function() {
    add_meta_box(
        'spm_v2_sync_manual',
        'Sincronização Manual',
        'spm_v2_render_manual_sync_box',
        'post',
        'side',
        'high'
    );
});

function spm_v2_render_manual_sync_box($post) {
    $settings = get_option('spm_v2_settings');
    ?>
    <div id="spm-manual-sync">
<select id="spm-target-host" class="widefat">
    <?php foreach ($settings['hosts'] as $index => $host): ?>
        <option value="<?= esc_attr($index) ?>">
            <?= esc_html(parse_url($host['url'], PHP_URL_HOST)) ?>
        </option>
    <?php endforeach; ?>
</select>
        
        <button type="button" class="button button-primary" id="spm-trigger-sync" style="margin-top:10px;">
            <span class="dashicons dashicons-update"></span> Sincronizar Agora
        </button>
        
        <div id="spm-sync-result" style="margin-top:10px; display:none;">
            <p class="success" style="color:green; display:none;">✓ Sincronizado com sucesso!</p>
            <p class="error" style="color:red; display:none;"></p>
        </div>
    </div>

    <script>
    jQuery(document).ready(function($) {
        $('#spm-trigger-sync').click(function() {
            const hostIndex = $('#spm-target-host').val();
            const postId = <?= $post->ID ?>;
            
            $('#spm-sync-result').hide().find('p').hide();
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'spm_manual_sync',
                    post_id: postId,
                    host_index: hostIndex,
                    security: '<?= wp_create_nonce('spm_manual_sync_nonce') ?>'
                },
                success: function(response) {
                    if(response.success) {
                        $('#spm-sync-result .success').show();
                    } else {
                        $('#spm-sync-result .error').text(response.data).show();
                    }
                    $('#spm-sync-result').slideDown();
                },
                error: function(xhr) {
                    $('#spm-sync-result .error').text('Erro na requisição: ' + xhr.statusText).show();
                    $('#spm-sync-result').slideDown();
                }
            });
        });
    });
    </script>
    <?php
}

// -----------------------------------------
// FUNCIONALIDADE DE SINCRONIZAÇÃO
// -----------------------------------------
add_action('save_post', function($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id)) return;

    $settings = get_option('spm_v2_settings');
    if (!$settings['auto_sync']) return;

    $post = get_post($post_id);
    $author_id = $settings['default_author'];

    // Verificar autor
    if (!get_user_by('ID', $author_id)) {
        spmv2_log('Erro: Autor padrão inválido', $author_id);
        return;
    }

    // Preparar dados
    $data = [
        'title' => $post->post_title,
        'content' => $post->post_content,
        'status' => 'publish',
        'author' => $author_id,
        'meta' => get_post_meta($post_id)
    ];

    // Enviar para hosts
   // Modifique a parte de envio para hosts
foreach ($settings['hosts'] as $host) {
    try {
        $response = wp_remote_post($host['url'] . '/wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization' => 'Bearer ' . JWT::encode(['exp' => time() + 300], $host['secret']),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        // Log detalhado
        spmv2_log('Dados enviados para ' . $host['url'], [
            'payload' => $data,
            'response_code' => $response_code,
            'response_body' => $response_body
        ]);

        if ($response_code !== 200) {
            throw new Exception("Código de resposta inválido: $response_code");
        }

        if (!$response_body['success'] ?? false) {
            throw new Exception($response_body['message'] ?? 'Erro não especificado');
        }

    } catch (Exception $e) {
        spmv2_log('Falha na sincronização com ' . $host['url'], [
            'error' => $e->getMessage(),
            'trace' => $e->getTraceAsString()
        ]);
    }
}

// -----------------------------------------
// API REST
// -----------------------------------------
add_action('rest_api_init', function() {
    register_rest_route('spm/v2', '/sync', [
        'methods' => 'POST',
        'callback' => 'spm_v2_handle_sync',
        'permission_callback' => 'spm_v2_verify_request'
    ]);
});

function spm_v2_verify_request($request) {
    $settings = get_option('spm_v2_settings');
    $token = str_replace('Bearer ', '', $request->get_header('Authorization'));
    
    try {
        JWT::decode($token, $settings['security']['jwt_secret']);
        return true;
    } catch (Exception $e) {
        return new WP_Error('auth_error', 'Autenticação inválida', ['status' => 401]);
    }
}

// -----------------------------------------
// API REST
// -----------------------------------------
function spm_v2_handle_sync($request) {
    $settings = get_option('spm_v2_settings');
    $data = $request->get_params();

    // Sistema de fallback de autor (CORREÇÃO AQUI)
    $author_id = spm_v2_determine_author($data['author'], $settings);

    // Criar post
    $post_id = wp_insert_post([
        'post_title'   => sanitize_text_field($data['title']),
        'post_content' => wp_kses_post($data['content']),
        'post_status'  => 'publish',
        'post_author'  => $settings['default_author'],
        'post_type'    => 'post'
    ]);

    if (is_wp_error($post_id)) {
        spmv2_log('Erro ao criar post', $post_id->get_error_messages());
        return ['success' => false, 'message' => $post_id->get_error_message()];
    }

    // Adicionar metadados
    foreach ($data['meta'] as $key => $value) {
        update_post_meta($post_id, sanitize_key($key), sanitize_meta($key, $value, 'post'));
    }

    // Forçar atualização de cache
    clean_post_cache($post_id);
    flush_rewrite_rules(false);

    return ['success' => true, 'post_id' => $post_id];
}

// FUNÇÃO DE DETERMINAÇÃO DE AUTOR (CORREÇÃO)
function spm_v2_determine_author($author_data, $settings) {
    // Tentativa 1: Encontrar pelo ID original
    if ($user = get_user_by('ID', $author_data['id'])) {
        return $user->ID;
    }

    // Tentativa 2: Encontrar pelo username
    if ($user = get_user_by('login', $author_data['username'])) {
        return $user->ID;
    }

    // Tentativa 3: Encontrar pelo email
    if ($user = get_user_by('email', $author_data['email'])) {
        return $user->ID;
    }

    // Fallback 1: Usar autor padrão das configurações
    if ($user = get_user_by('ID', $settings['default_author'])) {
        spmv2_log('Usando autor padrão do plugin', $user);
        return $user->ID;
    }

    // Fallback 2: Primeiro administrador encontrado
    $admins = get_users(['role' => 'administrator', 'number' => 1]);
    if (!empty($admins)) {
        spmv2_log('Fallback para primeiro admin', $admins[0]);
        return $admins[0]->ID;
    }

    // Fallback final: Usuário atual
    spmv2_log('Fallback para usuário atual', get_current_user_id());
    return get_current_user_id();
}


// -----------------------------------------
// PÁGINA DE LOGS
// -----------------------------------------
function spm_v2_render_log_page() {
    $log_file = WP_CONTENT_DIR . '/spm-v2.log';
    
    if (isset($_POST['clear_logs'])) {
        file_put_contents($log_file, '');
        echo '<div class="notice notice-success"><p>Logs limpos com sucesso!</p></div>';
    }

    $logs = file_exists($log_file) ? file_get_contents($log_file) : 'Nenhum log encontrado';
    ?>
    <div class="wrap">
        <h1>Logs de Sincronização</h1>
        <pre style="background: #1e1e1e; color: #fff; padding: 20px; overflow: auto;"><?= esc_html($logs) ?></pre>
        <form method="post">
            <?php submit_button('Limpar Logs', 'delete', 'clear_logs'); ?>
        </form>
    </div>
    <?php
}

// -----------------------------------------
// HANDLER AJAX PARA SINCRONIZAÇÃO MANUAL
// -----------------------------------------
add_action('wp_ajax_spm_manual_sync', function() {
    check_ajax_referer('spm_manual_sync_nonce', 'security');
    
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Permissão negada');
    }

    $post_id = absint($_POST['post_id']);
    $host_index = absint($_POST['host_index']);
    
    $settings = get_option('spm_v2_settings');
    $host = $settings['hosts'][$host_index] ?? null;
    
    if (!$host) {
        wp_send_json_error('Host inválido');
    }

    $post = get_post($post_id);
    $data = [
        'title' => $post->post_title,
        'content' => $post->post_content,
        'status' => 'publish',
        'author' => $settings['default_author'],
        'meta' => get_post_meta($post_id)
    ];

    try {
        $response = wp_remote_post($host['url'] . '/wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization' => 'Bearer ' . JWT::encode(['exp' => time() + 300], $host['secret']),
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode($data),
            'timeout' => 15
        ]);

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = json_decode(wp_remote_retrieve_body($response), true);

        if ($response_code !== 200 || !isset($response_body['success'])) {
            throw new Exception('Resposta inválida do servidor');
        }

        if (!$response_body['success']) {
            wp_send_json_error($response_body['message'] ?? 'Erro desconhecido');
        }

        wp_send_json_success();

    } catch (Exception $e) {
        wp_send_json_error($e->getMessage());
    }
});
    
// Classe JWT simplificada (Recomendo usar uma biblioteca oficial em produção)
class JWT {
    public static function encode($payload, $secret) {
        $header = json_encode(['typ' => 'JWT', 'alg' => 'HS256']);
        $payload = json_encode($payload);
        $base64 = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header) . '.' . base64_encode($payload));
        $signature = hash_hmac('sha256', $base64, $secret, true);
        return $base64 . '.' . str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));
    }

    public static function decode($token, $secret) {
        $parts = explode('.', $token);
        if (count($parts) !== 3) throw new Exception('Token inválido');
        
        $signature = base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[2]));
        $expected = hash_hmac('sha256', $parts[0] . '.' . $parts[1], $secret, true);
        
        if (!hash_equals($signature, $expected)) {
            throw new Exception('Assinatura inválida');
        }
        
        return json_decode(base64_decode(str_replace(['-', '_'], ['+', '/'], $parts[1])), true);
    }
}
