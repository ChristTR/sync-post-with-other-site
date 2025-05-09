<?php
global $spsv2_settings;
$settings = $spsv2_settings->spsv2_get_settings();
$hosts = $settings['hosts'] ?? array();
$categories = get_categories(array('hide_empty' => false));
$security_settings = $settings['security'] ?? array();
?>

<div class="wrap">
    <h1><?php esc_html_e('Configurações Sync Post v2', 'spsv2'); ?></h1>

    <?php settings_errors('spsv2_settings'); ?>

    <form method="post" action="options.php">
        <?php 
        settings_fields('spsv2_settings_group');
        wp_nonce_field('spsv2_settings_nonce', '_spsv2_nonce');
        ?>
        
        <div id="spsv2-hosts-container">
            <?php foreach ($hosts as $index => $host) : ?>
            <div class="spsv2-host-section postbox">
                <div class="postbox-header">
                    <h3><?php printf(esc_html__('Site #%d', 'spsv2'), ($index + 1)); ?>
                        <button type="button" class="button-link spsv2-remove-host">
                            <?php esc_html_e('Remover', 'spsv2'); ?>
                        </button>
                    </h3>
                </div>
                
                <div class="inside">
                    <table class="form-table">
                        <!-- URL -->
                        <tr>
                            <th><label><?php esc_html_e('URL do Site', 'spsv2'); ?></label></th>
                            <td>
                                <input type="url" 
                                       name="spsv2_settings[hosts][<?php echo absint($index); ?>][url]" 
                                       value="<?php echo esc_url($host['url']); ?>" 
                                       class="regular-text"
                                       pattern="https://.*"
                                       required>
                                <p class="description">
                                    <?php esc_html_e('URL completa com HTTPS (ex: https://seusite.com)', 'spsv2'); ?>
                                </p>
                            </td>
                        </tr>

                        <!-- Usuário -->
                        <tr>
                            <th><label><?php esc_html_e('Usuário', 'spsv2'); ?></label></th>
                            <td>
                                <input type="text" 
                                       name="spsv2_settings[hosts][<?php echo absint($index); ?>][username]" 
                                       value="<?php echo esc_attr($host['username']); ?>" 
                                       class="regular-text"
                                       required>
                            </td>
                        </tr>

                        <!-- Senha de Aplicativo -->
                        <tr>
                            <th><label><?php esc_html_e('Senha de Aplicativo', 'spsv2'); ?></label></th>
                            <td>
                                <div class="spsv2-password-box">
                                    <input type="text" 
                                           name="spsv2_settings[hosts][<?php echo absint($index); ?>][app_password]" 
                                           value="<?php echo esc_attr($host['app_password']); ?>"
                                           class="regular-text"
                                           pattern="[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}"
                                           title="<?php esc_attr_e('Formato: XXXX XXXX XXXX XXXX XXXX', 'spsv2'); ?>"
                                           required>
                                    <span class="dashicons dashicons-info-outline" 
                                          title="<?php esc_attr_e('Gerar em Usuários > Seu Perfil > Senhas de Aplicativo', 'spsv2'); ?>">
                                    </span>
                                </div>
                            </td>
                        </tr>

                        <!-- Categorias Excluídas -->
                        <tr>
                            <th><label><?php esc_html_e('Categorias Excluídas', 'spsv2'); ?></label></th>
                            <td>
                                <div class="spsv2-excluded-categories">
                                    <?php foreach ($categories as $cat) : ?>
                                    <label class="spsv2-category-item">
                                        <input type="checkbox" 
                                               name="spsv2_settings[hosts][<?php echo absint($index); ?>][excluded_categories][]" 
                                               value="<?php echo absint($cat->term_id); ?>" 
                                               <?php checked(in_array($cat->term_id, $host['excluded_categories'] ?? [])); ?>>
                                        <?php echo esc_html($cat->name); ?>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </td>
                        </tr>

                        <!-- Configurações Avançadas -->
                        <tr>
                            <th><label><?php esc_html_e('Opções Avançadas', 'spsv2'); ?></label></th>
                            <td>
                                <label class="spsv2-advanced-option">
                                    <input type="checkbox" 
                                           name="spsv2_settings[hosts][<?php echo absint($index); ?>][sync_images]" 
                                           value="1" <?php checked($host['sync_images'] ?? 1, 1); ?>>
                                    <?php esc_html_e('Sincronizar imagens', 'spsv2'); ?>
                                </label>

                                <label class="spsv2-advanced-option">
                                    <input type="checkbox" 
                                           name="spsv2_settings[hosts][<?php echo absint($index); ?>][sync_yoast]" 
                                           value="1" <?php checked($host['sync_yoast'] ?? 1, 1); ?>>
                                    <?php esc_html_e('Sincronizar SEO', 'spsv2'); ?>
                                </label>
                            </td>
                        </tr>
                    </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="spsv2-actions">
            <button type="button" id="spsv2-add-host" class="button button-primary">
                <?php esc_html_e('+ Adicionar Site', 'spsv2'); ?>
            </button>
            <?php submit_button(__('Salvar Configurações', 'spsv2'), 'primary', 'spsv2_submit', false); ?>
        </div>
    </form>
</div>

<style>
.spsv2-host-section {
    margin-bottom: 20px;
    background: #fff;
    border: 1px solid #ccd0d4;
    box-shadow: 0 1px 1px rgba(0,0,0,0.04);
}

.spsv2-host-section .inside {
    padding: 10px 20px;
}

.spsv2-excluded-categories {
    columns: 3;
    max-height: 300px;
    padding: 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.spsv2-category-item {
    display: block;
    margin-bottom: 5px;
}

.spsv2-password-box {
    position: relative;
    max-width: 500px;
}

.spsv2-password-box .dashicons {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: help;
    color: #646970;
}

.spsv2-advanced-option {
    display: block;
    margin: 5px 0;
}

.spsv2-actions {
    margin-top: 20px;
    padding: 10px;
    background: #f6f7f7;
    border-top: 1px solid #dcdcde;
}
</style>

<script>
jQuery(document).ready(function($) {
    const hostTemplate = `
    <div class="spsv2-host-section postbox">
        <div class="postbox-header">
            <h3><?php esc_html_e('Novo Site', 'spsv2'); ?>
                <button type="button" class="button-link spsv2-remove-host">
                    <?php esc_html_e('Remover', 'spsv2'); ?>
                </button>
            </h3>
        </div>
        <div class="inside">
            <table class="form-table">
                <tr>
                    <th><label><?php esc_html_e('URL do Site', 'spsv2'); ?></label></th>
                    <td>
                        <input type="url" name="spsv2_settings[hosts][__INDEX__][url]" 
                               class="regular-text" pattern="https://.*" required>
                        <p class="description"><?php esc_html_e('URL completa com HTTPS', 'spsv2'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Usuário', 'spsv2'); ?></label></th>
                    <td><input type="text" name="spsv2_settings[hosts][__INDEX__][username]" class="regular-text" required></td>
                </tr>
                <tr>
                    <th><label><?php esc_html_e('Senha de Aplicativo', 'spsv2'); ?></label></th>
                    <td>
                        <div class="spsv2-password-box">
                            <input type="text" 
                                   name="spsv2_settings[hosts][__INDEX__][app_password]"
                                   class="regular-text"
                                   pattern="[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}"
                                   title="<?php esc_attr_e('Formato: XXXX XXXX XXXX XXXX XXXX', 'spsv2'); ?>"
                                   required>
                            <span class="dashicons dashicons-info-outline"></span>
                        </div>
                    </td>
                </tr>
            </table>
        </div>
    </div>`;

    // Adicionar novo host
    $('#spsv2-add-host').on('click', function() {
        const index = Date.now();
        const newHost = hostTemplate.replace(/__INDEX__/g, index);
        $('#spsv2-hosts-container').append(newHost);
    });

    // Remover host
    $(document).on('click', '.spsv2-remove-host', function() {
        $(this).closest('.spsv2-host-section').remove();
    });
});
</script>
