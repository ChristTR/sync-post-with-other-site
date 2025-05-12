<?php
/**
 * View de configurações do Sync Post With Other Site
 */

defined( 'ABSPATH' ) || exit;

global $sps_settings;

// Carrega opções atuais
$options = get_option( SPS_Settings::OPTION_KEY, $sps_settings->sps_default_setting_option() );
$sites   = $options['remote_sites'];
?>

<div class="wrap sps_content">
    <h1><?php esc_html_e( 'Sync Post With Other Site Settings', SPS_txt_domain ); ?></h1>

    <?php if ( isset( $_GET['updated'] ) && $_GET['updated'] === 'true' ) : ?>
        <div id="message" class="updated notice notice-success is-dismissible">
            <p><?php esc_html_e( 'Settings saved.', SPS_txt_domain ); ?></p>
        </div>
    <?php endif; ?>

    <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>?action=sps_save_settings" method="post">
        <?php wp_nonce_field( 'sps_save_settings', 'sps_nonce' ); ?>

        <h2><?php esc_html_e( 'Remote Sites', SPS_txt_domain ); ?></h2>
        <table class="widefat fixed" cellspacing="0">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'URL', SPS_txt_domain ); ?></th>
                    <th><?php esc_html_e( 'Label', SPS_txt_domain ); ?></th>
                    <th><?php esc_html_e( 'Username', SPS_txt_domain ); ?></th>
                    <th><?php esc_html_e( 'App Password', SPS_txt_domain ); ?></th>
                    <th><?php esc_html_e( 'Default Selected', SPS_txt_domain ); ?></th>
                    <th><?php esc_html_e( 'Actions', SPS_txt_domain ); ?></th>
                </tr>
            </thead>
            <tbody id="sps-sites-body">
                <?php foreach ( $sites as $i => $site ) : ?>
                    <tr>
                        <td>
                            <input type="url" name="remote_site_url[]" value="<?php echo esc_url( $site['url'] ); ?>" class="regular-text" required>
                        </td>
                        <td>
                            <input type="text" name="remote_site_label[]" value="<?php echo esc_attr( $site['label'] ); ?>" class="regular-text">
                        </td>
                        <td>
                            <input type="text" name="remote_site_user[]" value="<?php echo esc_attr( $site['username'] ); ?>" class="regular-text">
                        </td>
                        <td>
                            <input type="password" name="remote_site_pass[]" value="<?php echo esc_attr( $site['app_password'] ); ?>" class="regular-text">
                        </td>
                        <td style="text-align: center;">
                            <input type="checkbox" name="remote_site_default[]" value="<?php echo esc_attr( $i ); ?>" <?php checked( in_array( $i, array_keys( array_filter( $sites, function( $s ) { return ! empty( $s['default'] ); } ) ) ) ); ?>>
                        </td>
                        <td>
                            <button type="button" class="button remove-site"><?php esc_html_e( 'Remove', SPS_txt_domain ); ?></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p>
            <button type="button" class="button" id="add-site"><?php esc_html_e( 'Add New Site', SPS_txt_domain ); ?></button>
        </p>

        <h2><?php esc_html_e( 'Other Settings', SPS_txt_domain ); ?></h2>
        <table class="form-table">
            <tr>
                <th scope="row"><?php esc_html_e( 'Strict Mode', SPS_txt_domain ); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="sps_strict_mode" value="1" <?php checked( $options['strict_mode'], '1' ); ?>>
                        <?php esc_html_e( 'Require WP and plugin versions to match on source and target', SPS_txt_domain ); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Content Match Mode', SPS_txt_domain ); ?></th>
                <td>
                    <select name="sps_content_match">
                        <option value="slug" <?php selected( $options['content_match'], 'slug' ); ?>><?php esc_html_e( 'Post Slug', SPS_txt_domain ); ?></option>
                        <option value="title" <?php selected( $options['content_match'], 'title' ); ?>><?php esc_html_e( 'Post Title', SPS_txt_domain ); ?></option>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e( 'Roles Allowed', SPS_txt_domain ); ?></th>
                <td>
                    <?php
                    $all_roles = [ 'administrator', 'editor', 'author', 'contributor' ];
                    foreach ( $all_roles as $role ) :
                        ?>
                        <label style="margin-right:1em;">
                            <input type="checkbox" name="sps_roles_allowed[]" value="<?php echo esc_attr( $role ); ?>" <?php checked( in_array( $role, $options['roles_allowed'], true ) ); ?>>
                            <?php echo esc_html( ucfirst( $role ) ); ?>
                        </label>
                    <?php endforeach; ?>
                </td>
            </tr>
        </table>

        <p class="submit">
            <button type="submit" class="button-primary"><?php esc_html_e( 'Save Changes', SPS_txt_domain ); ?></button>
        </p>
    </form>
</div>

<script>
jQuery(function($){
    // Função para adicionar nova linha
    $('#add-site').on('click', function(){
        var $tbody = $('#sps-sites-body');
        var index = $tbody.find('tr').length;
        var row = '<tr>' +
            '<td><input type="url" name="remote_site_url[]" class="regular-text" required></td>' +
            '<td><input type="text" name="remote_site_label[]" class="regular-text"></td>' +
            '<td><input type="text" name="remote_site_user[]" class="regular-text"></td>' +
            '<td><input type="password" name="remote_site_pass[]" class="regular-text"></td>' +
            '<td style="text-align:center;"><input type="checkbox" name="remote_site_default[]" value="'+index+'"></td>' +
            '<td><button type="button" class="button remove-site"><?php esc_html_e( 'Remove', SPS_txt_domain ); ?></button></td>' +
            '</tr>';
        $tbody.append(row);
    });

    // Remover linha
    $('#sps-sites-body').on('click', '.remove-site', function(){
        $(this).closest('tr').remove();
    });
});
</script>
