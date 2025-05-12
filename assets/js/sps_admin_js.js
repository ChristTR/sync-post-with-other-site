jQuery(function($){
    // ===== Adicionar nova linha na tabela de Remote Sites =====
    $('#add-site').on('click', function(){
        var $tbody = $('#sps-sites-body');
        var index  = $tbody.find('tr').length;
        var row = '<tr>' +
            '<td><input type="url" name="remote_site_url[]" class="regular-text" required></td>' +
            '<td><input type="text" name="remote_site_label[]" class="regular-text"></td>' +
            '<td><input type="text" name="remote_site_user[]" class="regular-text"></td>' +
            '<td><input type="password" name="remote_site_pass[]" class="regular-text"></td>' +
            '<td style="text-align:center;"><input type="checkbox" name="remote_site_default[]" value="'+index+'"></td>' +
            '<td><button type="button" class="remove-site">' + sps_admin_js_params.remove_text + '</button></td>' +
            '</tr>';
        $tbody.append(row);
    });

    // ===== Remover linha de Remote Sites =====
    $('#sps-sites-body').on('click', '.remove-site', function(){
        $(this).closest('tr').remove();
    });

    // ===== Mostrar/ocultar senha (no metabox de posts) =====
    $(document).on('click', '.sps_show_pass', function() {
        var $box = $(this).closest('.sps_password_box');
        $box.find('input').attr('type', 'text');
        $(this).hide().siblings('.sps_hide_pass').show();
    });
    $(document).on('click', '.sps_hide_pass', function() {
        var $box = $(this).closest('.sps_password_box');
        $box.find('input').attr('type', 'password');
        $(this).hide().siblings('.sps_show_pass').show();
    });

    // ===== Atualizar labels de URL abaixo de username/password =====
    $(document).on('keyup', '.sps_url', function(){
        var url = $(this).val();
        var $tbl = $(this).closest('table');
        $tbl.find('span.sps_username, span.sps_password').text(url);
    });
});
