<?php
class SPSv2_Settings_View {
    public static function render($settings) {
        ?>
        <div class="wrap">
            <h1><?php _e('Sync Post v2 Settings', 'spsv2'); ?></h1>
            
            <form method="post" action="options.php">
                <?php settings_fields('spsv2_settings_group'); ?>
                
                <h2><?php _e('Remote Sites', 'spsv2'); ?></h2>
                <div id="spsv2-hosts">
                    <?php foreach ($settings['hosts'] as $index => $host) : ?>
                        <div class="host-entry">
                            <input type="url" name="spsv2_settings[hosts][<?= $index ?>][url]" 
                                value="<?= esc_url($host['url']) ?>" 
                                placeholder="https://example.com" required>
                                
                            <input type="text" name="spsv2_settings[hosts][<?= $index ?>][secret]" 
                                value="<?= esc_attr($host['secret']) ?>" 
                                placeholder="<?php esc_attr_e('Secret Key', 'spsv2'); ?>" required>
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="spsv2-add-host" class="button">
                    <?php _e('Add Host', 'spsv2'); ?>
                </button>

                <h2><?php _e('Security Settings', 'spsv2'); ?></h2>
                <table class="form-table">
                    <tr>
                        <th><?php _e('JWT Secret', 'spsv2'); ?></th>
                        <td>
                            <input type="text" name="spsv2_settings[security][jwt_secret]" 
                                value="<?= esc_attr($settings['security']['jwt_secret']) ?>" 
                                class="regular-text" readonly>
                        </td>
                    </tr>
                    <tr>
                        <th><?php _e('Force SSL', 'spsv2'); ?></th>
                        <td>
                            <label>
                                <input type="checkbox" name="spsv2_settings[security][force_ssl]" 
                                    <?php checked($settings['security']['force_ssl'], true); ?>>
                                <?php _e('Require HTTPS for connections', 'spsv2'); ?>
                            </label>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <script>
                document.getElementById('spsv2-add-host').addEventListener('click', function() {
                    const container = document.getElementById('spsv2-hosts');
                    const index = container.children.length;
                    
                    const div = document.createElement('div');
                    div.className = 'host-entry';
                    div.innerHTML = `
                        <input type="url" name="spsv2_settings[hosts][${index}][url]" 
                            placeholder="https://example.com" required>
                            
                        <input type="text" name="spsv2_settings[hosts][${index}][secret]" 
                            placeholder="<?php esc_attr_e('Secret Key', 'spsv2'); ?>" required>
                    `;
                    
                    container.appendChild(div);
                });
            </script>
        </div>
        <?php
    }
}
