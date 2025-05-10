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

    add_settings_section(
        'spm_v2_hosts',
        'Hosts Remotos',
        function() { echo '<p>Configure os sites para sincronização</p>'; },
        'spm-v2-settings'
    );

    add_settings_section(
        'spm_v2_security',
        'Segurança',
        function() { echo '<p>Configurações de segurança da API</p>'; },
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
        'default_author',
        'Autor Padrão',
        'spm_v2_default_author_field',
        'spm-v2-settings',
        'spm_v2_general'
    );
});

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
            'security' => [],
            'default_author' => get_current_user_id(),
            'use_admin_fallback' => false
        ]
    );
    ?>
    <div class="wrap">
        <style>
            /* Estilos gerais */
            #spm-hosts {
                margin: 20px 0;
            }

            .host-entry {
                background: #f8f9fa;
                border: 1px solid #dee2e6;
                padding: 15px;
                margin-bottom: 10px;
                border-radius: 4px;
                display: flex;
                gap: 10px;
                align-items: center;
            }

            .host-entry input[type="url"],
            .host-entry input[type="text"] {
                flex: 1;
                min-width: 300px;
                padding: 8px 12px;
                border: 1px solid #ced4da;
                border-radius: 4px;
            }

            .remove-host {
                background: #dc3545;
                color: white;
                border: 1px solid #dc3545;
                padding: 8px 12px;
                border-radius: 4px;
                cursor: pointer;
                transition: all 0.3s ease;
            }

            .remove-host:hover {
                background: #bb2d3b;
                border-color: #b02a37;
            }

            #add-host {
                background: #0d6efd;
                color: white;
                border: none;
                padding: 10px 20px;
                border-radius: 4px;
                cursor: pointer;
                transition: background 0.3s ease;
            }

            #add-host:hover {
                background: #0b5ed7;
            }

            /* Responsividade */
            @media (max-width: 782px) {
                .host-entry {
                    flex-direction: column;
                }
                
                .host-entry input {
                    width: 100% !important;
                    min-width: unset !important;
                }
            }

            /* Mensagens de erro */
            .error {
                color: #dc3545;
                background: #f8d7da;
                border: 1px solid #f5c6cb;
                padding: 8px 12px;
                border-radius: 4px;
                margin: 10px 0;
            }

            /* Ajustes na tabela */
            .form-table th {
                width: 200px;
                padding: 20px 10px;
            }

            .form-table td {
                padding: 15px 10px;
            }

            .description {
                color: #6c757d;
                font-style: italic;
                margin-top: 5px;
                font-size: 0.9em;
            }
        </style>

        <h1>Configurações Sync Master</h1>
        
        <form method="post" action="options.php">
            <?php settings_fields('spm_v2_group'); ?>
            
            <h2 class="title">Configurações de Segurança</h2>
            <table class="form-table">
                <tr>
                    <th>Segredo JWT</th>
                    <td>
                        <input type="text" 
                               name="spm_v2_settings[security][jwt_secret]" 
                               value="<?= esc_attr($settings['security']['jwt_secret'] ?? '') ?>" 
                               class="regular-text" 
                               required>
                        <p class="description">Chave secreta para autenticação JWT (mínimo 32 caracteres)</p>
                    </td>
                </tr>
                <tr>
                    <th>Forçar SSL</th>
                    <td>
                        <label>
                            <input type="checkbox" 
                                   name="spm_v2_settings[security][force_ssl]" 
                                   <?php checked($settings['security']['force_ssl'] ?? false, true); ?>>
                            Exigir conexões seguras (HTTPS)
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button('Salvar Configurações', 'primary large'); ?>
        </form>

        <script>
        jQuery(document).ready(function($) {
            $('#add-host').click(function() {
                const newHost = `
                    <div class="host-entry">
                        <input type="url" 
                               name="spm_v2_settings[hosts][][url]" 
                               placeholder="https://site-exemplo.com" 
                               required>
                        <input type="text" 
                               name="spm_v2_settings[hosts][][secret]" 
                               placeholder="Chave secreta de autenticação" 
                               required>
                        <button type="button" class="button remove-host">Remover</button>
                    </div>`;
                $('#spm-hosts').append(newHost);
            });

            $(document).on('click', '.remove-host', function() {
                $(this).closest('.host-entry').remove();
            });
        });
        </script>
    </div>
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
    foreach ($settings['hosts'] as $host) {
        $response = wp_remote_post($host['url'] . '/wp-json/spm/v2/sync', [
            'headers' => [
                'Authorization' => 'Bearer ' . JWT::encode(['exp' => time() + 300], $host['secret'])
            ],
            'body' => $data
        ]);

        // Verificar resposta
        if (wp_remote_retrieve_response_code($response) === 200) {
            $body = json_decode(wp_remote_retrieve_body($response), true);
            if (!$body['success']) {
                spmv2_log('Erro na sincronização', [
                    'host' => $host['url'],
                    'erro' => $body['message']
                ]);
            }
        } else {
            spmv2_log('Falha na comunicação', [
                'host' => $host['url'],
                'codigo' => wp_remote_retrieve_response_code($response)
            ]);
        }
    }
});

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
