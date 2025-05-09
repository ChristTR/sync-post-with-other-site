<?php
global $spsv2_settings;

// Verificar se é POST e processar
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['spsv2_submit'])) {
    do_action('spsv2_save_settings', $_POST);
}

echo '<div class="wrap spsv2-settings">';

// Exibir mensagens de status
if (isset($_SESSION['spsv2_notice'])) {
    echo '<div class="notice notice-'.$_SESSION['spsv2_notice_type'].' is-dismissible">';
    echo '<p>'.$_SESSION['spsv2_notice'].'</p>';
    echo '</div>';
    unset($_SESSION['spsv2_notice']);
}

// Formulário principal
echo '<form method="post" id="spsv2-settings-form">';
    
    // Security nonce
    wp_nonce_field('spsv2_settings_nonce', 'spsv2_nonce');

    // Carregar configurações
    $settings = $spsv2_settings->spsv2_get_settings();
    $hosts = $settings['hosts'] ?? array();

    echo '<div id="spsv2-hosts-wrapper">';

    // Loop através de hosts configurados
    foreach ($hosts as $index => $host) {
        echo '<div class="spsv2-host-card">';
        
            // Cabeçalho
            echo '<h3 class="spsv2-host-title">';
                echo __('Site #', 'spsv2_text_domain') . ($index + 1);
                echo '<button type="button" class="spsv2-remove-host button-link">'.__('Remover', 'spsv2_text_domain').'</button>';
            echo '</h3>';

            // Campos do host
            echo '<table class="form-table">';
                
                // Campo: URL
                echo '<tr>';
                    echo '<th><label>'.__('URL do Site', 'spsv2_text_domain').'</label></th>';
                    echo '<td>';
                        echo '<input type="url" name="spsv2_settings[hosts]['.$index.'][url]" 
                                  value="'.esc_url($host['url']).'" 
                                  class="regular-text" required>';
                    echo '</td>';
                echo '</tr>';

                // Campo: Usuário
                echo '<tr>';
                    echo '<th><label>'.__('Usuário', 'spsv2_text_domain').'</label></th>';
                    echo '<td>';
                        echo '<input type="text" name="spsv2_settings[hosts]['.$index.'][username]" 
                                  value="'.esc_attr($host['username']).'" 
                                  class="regular-text">';
                    echo '</td>';
                echo '</tr>';

                // Campo: Senha
                echo '<tr>';
                    echo '<th><label>'.__('Senha', 'spsv2_text_domain').'</label></th>';
                    echo '<td>';
                        echo '<div class="spsv2-password-toggle">';
                            echo '<input type="password" 
                                      name="spsv2_settings[hosts]['.$index.'][password]" 
                                      value="'.esc_attr($host['password']).'" 
                                      class="regular-text">';
                            echo '<button type="button" class="button spsv2-toggle-password">👁️</button>';
                        echo '</div>';
                    echo '</td>';
                echo '</tr>';

                // ========= [ NOVO CAMPO: CATEGORIAS EXCLUÍDAS ] ========= //
                echo '<tr>';
                    echo '<th><label>'.__('Categorias Excluídas', 'spsv2_text_domain').'</label></th>';
                    echo '<td>';
                        echo '<div class="spsv2-category-checkboxes">';
                            $categories = get_categories(['hide_empty' => false]);
                            foreach ($categories as $cat) {
                                $checked = in_array($cat->term_id, $host['excluded_categories'] ?? []) ? 'checked' : '';
                                echo '<label>';
                                    echo '<input type="checkbox" 
                                              name="spsv2_settings[hosts]['.$index.'][excluded_categories][]" 
                                              value="'.esc_attr($cat->term_id).'" 
                                              '.$checked.'>';
                                    echo esc_html($cat->name);
                                echo '</label><br>';
                            }
                        echo '</div>';
                    echo '</td>';
                echo '</tr>';

                // Campo: Modo Estrito
                echo '<tr>';
                    echo '<th><label>'.__('Modo Estrito', 'spsv2_text_domain').'</label></th>';
                    echo '<td>';
                        echo '<label>';
                            echo '<input type="checkbox" 
                                      name="spsv2_settings[hosts]['.$index.'][strict_mode]" 
                                      value="1" '.(!empty($host['strict_mode']) ? 'checked' : '').'>';
                            echo __('Ativar verificação rigorosa de versões', 'spsv2_text_domain');
                        echo '</label>';
                    echo '</td>';
                echo '</tr>';

            echo '</table>';

        echo '</div>'; // .spsv2-host-card
    }

    echo '</div>'; // #spsv2-hosts-wrapper

    // Botão para adicionar novo host
    echo '<button type="button" id="spsv2-add-host" class="button button-primary">';
        echo __('+ Adicionar Novo Site', 'spsv2_text_domain');
    echo '</button>';

    // Botão de submit
    submit_button(__('Salvar Configurações', 'spsv2_text_domain'), 'primary', 'spsv2_submit');

echo '</form>';

// Template para novos hosts (usado pelo JavaScript)
echo '<script type="text/html" id="spsv2-host-template">';
    echo '<div class="spsv2-host-card">';
        echo '<h3 class="spsv2-host-title">';
            echo __('Novo Site', 'spsv2_text_domain');
            echo '<button type="button" class="spsv2-remove-host button-link">'.__('Remover', 'spsv2_text_domain').'</button>';
        echo '</h3>';
        echo '<table class="form-table">';
            // ... (repetir estrutura dos campos com índices dinâmicos)
        echo '</table>';
    echo '</div>';
echo '</script>';

echo '</div>'; // .wrap

// Estilos e scripts específicos
echo '<style>
.spsv2-host-card {
    background: #fff;
    padding: 20px;
    margin-bottom: 20px;
    border: 1px solid #ccd0d4;
}
.spsv2-host-title {
    margin-top: 0;
}
.spsv2-password-toggle {
    display: flex;
    gap: 10px;
}
.spsv2-category-checkboxes {
    max-height: 200px;
    overflow-y: auto;
    border: 1px solid #ddd;
    padding: 10px;
}
</style>';

// Scripts JavaScript
echo '<script>
jQuery(function($) {
    // Adicionar novo host
    $("#spsv2-add-host").click(function() {
        const template = $("#spsv2-host-template").html();
        const index = Date.now(); // Índice único
        const newHost = $(template.replace(/\[0\]/g, `[${index}]`));
        $("#spsv2-hosts-wrapper").append(newHost);
    });

    // Remover host
    $(document).on("click", ".spsv2-remove-host", function() {
        $(this).closest(".spsv2-host-card").remove();
    });

    // Toggle password
    $(document).on("click", ".spsv2-toggle-password", function() {
        const input = $(this).prev("input");
        input.attr("type", input.attr("type") === "password" ? "text" : "password");
    });
});
</script>';
