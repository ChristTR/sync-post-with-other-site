jQuery(document).ready(function($) {
    // ============== ADICIONAR NOVO SITE ============== //
    let siteCounter = parseInt($('#spsv2-site-counter').val()) || 0;
    
    $('#spsv2-add-site').click(function() {
        siteCounter++;
        const template = `
            <div class="spsv2-site-group">
                <table class="form-table">
                    <tr>
                        <th><label>URL do Site</label></th>
                        <td>
                            <input type="url" 
                                   name="spsv2_settings[hosts][${siteCounter}][url]" 
                                   class="spsv2-input" 
                                   required>
                            <a href="#" class="spsv2-remove-site" data-site-id="${siteCounter}">Remover</a>
                        </td>
                    </tr>
                    <!-- Adicione outros campos aqui -->
                </table>
            </div>
        `;
        $('#spsv2-sites-container').append(template);
        $('#spsv2-site-counter').val(siteCounter);
    });

    // ============== REMOVER SITE ============== //
    $(document).on('click', '.spsv2-remove-site', function(e) {
        e.preventDefault();
        const siteId = $(this).data('site-id');
        $(`.spsv2-site-group input[name*="[${siteId}]"]`).closest('.spsv2-site-group').remove();
    });

    // ============== TOGGLE SENHA ============== //
    $(document).on('click', '.spsv2-show-pass, .spsv2-hide-pass', function() {
        const container = $(this).closest('.spsv2-password-box');
        const input = container.find('input');
        const isPassword = input.attr('type') === 'password';
        
        input.attr('type', isPassword ? 'text' : 'password');
        container.find('.spsv2-show-pass').toggle(!isPassword);
        container.find('.spsv2-hide-pass').toggle(isPassword);
    });

    // ============== ATUALIZAR URL ============== //
    $(document).on('input', '.spsv2-url', function() {
        const url = $(this).val();
        const table = $(this).closest('table');
        table.find('.spsv2-url-display').text(url || '[insira a URL]');
    });
});
