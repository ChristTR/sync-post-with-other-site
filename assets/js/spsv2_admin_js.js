jQuery(document).ready(function($) {
    // ============== CONSTANTES E ELEMENTOS ============== //
    const $container = $('#spsv2-hosts-container');
    const $addButton = $('#spsv2-add-host');
    
    // ============== INICIALIZAÇÃO ============== //
    let hostCount = $container.children().length;

    // ============== TEMPLATE DE HOST ============== //
    const getHostTemplate = (index) => `
        <div class="spsv2-host-section">
            <h3>${wp.i18n.__('Novo Site', 'spsv2')}
                <button type="button" class="button-link spsv2-remove-host">
                    ${wp.i18n.__('Remover', 'spsv2')}
                </button>
            </h3>
            <table class="form-table">
                <tr>
                    <th><label>${wp.i18n.__('URL do Site', 'spsv2')}</label></th>
                    <td>
                        <input type="url" 
                               name="spsv2_settings[hosts][${index}][url]"
                               class="spsv2-input spsv2-url" 
                               pattern="https://.*"
                               required>
                        <p class="description">${wp.i18n.__('Exemplo: https://seusite.com', 'spsv2')}</p>
                    </td>
                </tr>
                <tr>
                    <th><label>${wp.i18n.__('Usuário', 'spsv2')}</label></th>
                    <td>
                        <input type="text" 
                               name="spsv2_settings[hosts][${index}][username]"
                               class="spsv2-input" 
                               required>
                    </td>
                </tr>
                <tr>
                    <th><label>${wp.i18n.__('Senha de Aplicativo', 'spsv2')}</label></th>
                    <td>
                        <div class="spsv2-password-box">
                            <input type="text"
                                   name="spsv2_settings[hosts][${index}][app_password]"
                                   class="spsv2-input"
                                   pattern="[A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4} [A-Za-z0-9]{4}"
                                   title="${wp.i18n.__('Formato: XXXX XXXX XXXX XXXX XXXX', 'spsv2')}"
                                   required>
                            <span class="dashicons dashicons-info"></span>
                        </div>
                    </td>
                </tr>
            </table>
            <hr>
        </div>
    `;

    // ============== ADICIONAR NOVO HOST ============== //
    $addButton.on('click', function() {
        const index = Date.now();
        $container.append(getHostTemplate(index));
        hostCount++;
        updateHostIndexes();
    });

    // ============== REMOVER HOST ============== //
    $container.on('click', '.spsv2-remove-host', function() {
        $(this).closest('.spsv2-host-section').remove();
        hostCount--;
        updateHostIndexes();
    });

    // ============== TOGGLE VISUALIZAÇÃO DE SENHA ============== //
    $container.on('click', '.spsv2-show-pass, .spsv2-hide-pass', function() {
        const $box = $(this).closest('.spsv2-password-box');
        const $input = $box.find('input');
        const isPassword = $input.attr('type') === 'password';
        
        $input.attr('type', isPassword ? 'text' : 'password');
        $box.find('.spsv2-show-pass').toggle(!isPassword);
        $box.find('.spsv2-hide-pass').toggle(isPassword);
    });

    // ============== VALIDAÇÃO EM TEMPO REAL ============== //
    $container.on('input', '.spsv2-url', function() {
        const url = $(this).val();
        const isValid = url.startsWith('https://') && isValidUrl(url);
        $(this).toggleClass('invalid', !isValid);
    });

    // ============== FUNÇÕES AUXILIARES ============== //
    function updateHostIndexes() {
        $container.children().each(function(index) {
            $(this).find('h3').text(wp.i18n.__('Site #', 'spsv2') + (index + 1));
        });
    }

    function isValidUrl(url) {
        try {
            new URL(url);
            return true;
        } catch {
            return false;
        }
    }
});
