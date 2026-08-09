/*!
 * ArixLog - integracion con el menu del area de administracion
 *
 * Anade la entrada "ArixLog" al menu lateral sin tocar
 * resources/views/layouts/admin.blade.php, que es justo el archivo que el tema
 * Arix reemplaza cada vez que se instala o se actualiza. Al hacerlo desde
 * JavaScript, la entrada sobrevive a las actualizaciones del tema y del panel.
 *
 * El enlace se construye clonando un elemento del propio menu, asi hereda
 * las clases y el aspecto del tema activo sea cual sea.
 */
(function () {
    'use strict';

    var LABEL = 'ArixLog';
    var URL = '/admin/arixlog';

    var ICON =
        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' +
        'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true" style="vertical-align:-2px;margin-right:6px;">' +
        '<path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z"/>' +
        '<path d="M14 2v4a2 2 0 0 0 2 2h4"/><path d="M10 9H8"/><path d="M16 13H8"/><path d="M16 17H8"/>' +
        '</svg>';

    function alreadyThere(menu) {
        return !!menu.querySelector('a[href="' + URL + '"]');
    }

    function buildItem(menu) {
        var item = document.createElement('li');
        var reference = menu.querySelector('li:not(.header) a');

        // Se copian las clases del <li> de referencia para heredar el estilo
        // del tema (Arix pinta sus <li> de una forma, AdminLTE de otra).
        if (reference && reference.parentElement && reference.parentElement.className) {
            item.className = reference.parentElement.className.replace(/\bactive\b/g, '').trim();
        }

        if (window.location.pathname.indexOf(URL) === 0) {
            item.className = (item.className + ' active').trim();
        }

        var link = document.createElement('a');
        link.setAttribute('href', URL);
        link.innerHTML = ICON + '<span>' + LABEL + '</span>';

        if (reference && reference.className) {
            link.className = reference.className;
        }

        item.appendChild(link);

        return item;
    }

    function inject() {
        var menu = document.querySelector('ul.sidebar-menu, .sidebar-menu, aside .sidebar ul');

        if (!menu || alreadyThere(menu)) {
            return !!menu;
        }

        var header = document.createElement('li');
        header.className = 'header';
        header.textContent = 'HERRAMIENTAS';

        menu.appendChild(header);
        menu.appendChild(buildItem(menu));

        return true;
    }

    function start() {
        if (inject()) {
            return;
        }

        // Algunos temas dibujan el menu despues de cargar la pagina.
        var attempts = 0;
        var timer = window.setInterval(function () {
            attempts++;

            if (inject() || attempts > 20) {
                window.clearInterval(timer);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
