<?php
global $spsv2_settings;
$settings = $spsv2_settings->spsv2_get_settings();
$hosts = $settings['hosts'] ?? array();
$categories = get_categories(array('hide_empty' => false));
?>

<div class="wrap">
    <h1><?php _e('Configurações Sync Post v2', 'spsv2-txt-domain'); ?></h1>

    <?php if(isset($_SESSION['spsv2_notice'])) : ?>
        <div class="notice notice-<?php echo $_SESSION['spsv2_notice_type']; ?>">
            <p><?php echo $_SESSION['spsv2_notice']; ?></p>
        </div>
        <?php unset($_SESSION['spsv2_notice']); ?>
    <?php endif; ?>

    <form method="post" action="options.php">
        <?php 
        settings_fields('spsv2_settings_group');
        wp_nonce_field('spsv2_settings_nonce', 'spsv2_nonce');
        ?>
        
        <input type="hidden" id="spsv2-site-counter" value="<?php echo count($hosts); ?>">
        
        <div id="spsv2-hosts-container">
            <?php foreach ($hosts as $index => $host) : ?>
            <div class="spsv2-host-section">
                <h3><?php _e('Site #', 'spsv2-txt-domain') . ($index + 1); ?>
                    <button type="button" class="button-link spsv2-remove-host"><?php _e('Remover', 'spsv2-txt-domain'); ?></button>
                </h3>
                
                <table class="form-table">
                    <tr>
                        <th><label><?php _e('URL do Site', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <input type="url" 
                                   name="spsv2_settings[hosts][<?php echo $index; ?>][url]" 
                                   value="<?php echo esc_url($host['url'] ?? ''); ?>" 
                                   class="regular-text spsv2-url" required>
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Usuário', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <input type="text" 
                                   name="spsv2_settings[hosts][<?php echo $index; ?>][username]" 
                                   value="<?php echo esc_attr($host['username'] ?? ''); ?>" 
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Senha', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <div class="spsv2-password-box">
                                <input type="password" 
                                       name="spsv2_settings[hosts][<?php echo $index; ?>][password]" 
                                       value="<?php echo esc_attr($host['password'] ?? ''); ?>" 
                                       class="regular-text">
                                <span class="dashicons dashicons-visibility spsv2-show-pass"></span>
                                <span class="dashicons dashicons-hidden spsv2-hide-pass"></span>
                            </div>
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Modo Estrito', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <label>
                                <input type="checkbox" 
                                       name="spsv2_settings[hosts][<?php echo $index; ?>][strict_mode]" 
                                       value="1" <?php checked($host['strict_mode'] ?? 0, 1); ?>>
                                <?php _e('Ativar verificação de versão', 'spsv2-txt-domain'); ?>
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Correspondência de Conteúdo', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <select name="spsv2_settings[hosts][<?php echo $index; ?>][match_mode]" class="spsv2-select">
                                <option value="slug" <?php selected($host['match_mode'] ?? 'slug', 'slug'); ?>>
                                    <?php _e('Slug do Post', 'spsv2-txt-domain'); ?>
                                </option>
                                <option value="title" <?php selected($host['match_mode'] ?? '', 'title'); ?>>
                                    <?php _e('Título do Post', 'spsv2-txt-domain'); ?>
                                </option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Funções Permitidas', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <?php foreach(['editor', 'author', 'contributor'] as $role) : ?>
                            <label>
                                <input type="checkbox" 
                                       name="spsv2_settings[hosts][<?php echo $index; ?>][roles][]" 
                                       value="<?php echo $role; ?>" 
                                       <?php checked(in_array($role, $host['roles'] ?? [])); ?>>
                                <?php echo ucfirst($role); ?>
                            </label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>

                    <tr>
                        <th><label><?php _e('Categorias Excluídas', 'spsv2-txt-domain'); ?></label></th>
                        <td>
                            <div class="spsv2-excluded-categories">
                                <?php foreach($categories as $cat) : ?>
                                <label>
                                    <input type="checkbox" 
                                           name="spsv2_settings[hosts][<?php echo $index; ?>][excluded_categories][]" 
                                           value="<?php echo $cat->term_id; ?>" 
                                           <?php checked(in_array($cat->term_id, $host['excluded_categories'] ?? [])); ?>>
                                    <?php echo esc_html($cat->name); ?>
                                </label><br>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                </table>
                <hr>
            </div>
            <?php endforeach; ?>
        </div>

        <p>
            <button type="button" id="spsv2-add-host" class="button button-primary">
                <?php _e('+ Adicionar Site', 'spsv2-txt-domain'); ?>
            </button>
        </p>

        <?php submit_button(__('Salvar Configurações', 'spsv2-txt-domain'), 'primary', 'spsv2_submit'); ?>
    </form>
</div>

<style>
.spsv2-host-section {
    background: #fff;
    padding: 20px;
    margin-bottom: 30px;
    border: 1px solid #ccd0d4;
    border-radius: 4px;
}

.spsv2-excluded-categories {
    max-height: 200px;
    overflow-y: auto;
    padding: 10px;
    border: 1px solid #ddd;
}

.spsv2-password-box {
    position: relative;
    max-width: 500px;
}

.spsv2-show-pass,
.spsv2-hide-pass {
    position: absolute;
    right: 10px;
    top: 50%;
    transform: translateY(-50%);
    cursor: pointer;
    color: #2271b1;
}

.spsv2-hide-pass {
    display: none;
}
</style>

<script>
jQuery(document).ready(function($) {
    // Adicionar novo host
    $('#spsv2-add-host').click(function() {
        const index = Date.now();
        const template = `
            <div class="spsv2-host-section">
                <h3><?php _e('Novo Site', 'spsv2-txt-domain'); ?>
                    <button type="button" class="button-link spsv2-remove-host"><?php _e('Remover', 'spsv2-txt-domain'); ?></button>
                </h3>
                <table class="form-table">
                    <?php include(SPSV2_INCLUDES_DIR . 'spsv2_host_template.php'); ?>
                </table>
                <hr>
            </div>
        `;
        $('#spsv2-hosts-container').append(template);
    });

    // Remover host
    $(document).on('click', '.spsv2-remove-host', function() {
        $(this).closest('.spsv2-host-section').remove();
    });

    // Toggle password
    $(document).on('click', '.spsv2-show-pass, .spsv2-hide-pass', function() {
        const box = $(this).closest('.spsv2-password-box');
        const input = box.find('input');
        const isPassword = input.attr('type') === 'password';
        
        input.attr('type', isPassword ? 'text' : 'password');
        box.find('.spsv2-show-pass').toggle(!isPassword);
        box.find('.spsv2-hide-pass').toggle(isPassword);
    });
});
</script>
