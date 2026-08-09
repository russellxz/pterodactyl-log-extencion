/*!
 * DNS Reverse - entrada en el menu del area de administracion
 *
 * Anade "DNS Reverse" al menu lateral sin tocar
 * resources/views/layouts/admin.blade.php, que es justo el archivo que el tema
 * Arix reemplaza cada vez que se instala o se actualiza. Al hacerlo desde
 * JavaScript, la entrada sobrevive a las actualizaciones del tema y del panel.
 *
 * SOBRE EL ICONO
 * --------------
 * El icono va como SVG escrito aqui dentro, no como <i class="fa fa-...">.
 * Ese es el motivo de que la extension anterior saliera con el hueco vacio:
 * usaba clases de Font Awesome, y el tema Arix no carga Font Awesome (usa
 * Lucide). Un SVG suelto se dibuja igual en AdminLTE y en Arix, y como usa
 * currentColor toma el color del tema activo sin tener que saber cual es.
 */
(function () {
    'use strict';

    var LABEL = 'DNS Reverse';
    var URL = '/admin/dnsreverse';
    var GRUPO = 'HERRAMIENTAS';

    // Globo terraqueo con una nube delante: DNS + Cloudflare.
    var ICON =
        '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' +
        'fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true" focusable="false" ' +
        'style="vertical-align:-3px;margin-right:6px;display:inline-block;">' +
        '<circle cx="12" cy="12" r="10"/>' +
        '<path d="M2 12h20"/>' +
        '<path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"/>' +
        '</svg>';

    function yaEsta(menu) {
        return !!menu.querySelector('a[href="' + URL + '"]');
    }

    /**
     * Se clona el estilo de un elemento que ya este en el menu, asi la entrada
     * hereda el aspecto del tema activo sea cual sea.
     */
    function construir(menu) {
        var item = document.createElement('li');
        var referencia = menu.querySelector('li:not(.header) a');

        if (referencia && referencia.parentElement && referencia.parentElement.className) {
            item.className = referencia.parentElement.className.replace(/\bactive\b/g, '').trim();
        }

        if (window.location.pathname.indexOf(URL) === 0) {
            item.className = (item.className + ' active').trim();
        }

        var enlace = document.createElement('a');
        enlace.setAttribute('href', URL);
        enlace.innerHTML = ICON + '<span>' + LABEL + '</span>';

        if (referencia && referencia.className) {
            enlace.className = referencia.className;
        }

        item.appendChild(enlace);

        return item;
    }

    /**
     * Si otra extension de este repositorio ya creo la cabecera
     * "HERRAMIENTAS", se reutiliza en vez de pintar una segunda igual.
     */
    function cabecera(menu) {
        var cabeceras = menu.querySelectorAll('li.header');

        for (var i = 0; i < cabeceras.length; i++) {
            if ((cabeceras[i].textContent || '').trim().toUpperCase() === GRUPO) {
                return cabeceras[i];
            }
        }

        var nueva = document.createElement('li');
        nueva.className = 'header';
        nueva.textContent = GRUPO;
        menu.appendChild(nueva);

        return nueva;
    }

    /**
     * La otra extension de este repositorio tambien crea su cabecera
     * "HERRAMIENTAS", y como los dos scripts corren por separado pueden acabar
     * saliendo dos iguales seguidas. Se deja solo la primera; las entradas que
     * colgaban de las siguientes se quedan donde estaban, debajo de ella.
     */
    function limpiarCabecerasRepetidas(menu) {
        var vistas = 0;
        var cabeceras = menu.querySelectorAll('li.header');

        for (var i = 0; i < cabeceras.length; i++) {
            if ((cabeceras[i].textContent || '').trim().toUpperCase() !== GRUPO) {
                continue;
            }

            vistas++;

            if (vistas > 1 && cabeceras[i].parentNode) {
                cabeceras[i].parentNode.removeChild(cabeceras[i]);
            }
        }
    }

    function inyectar() {
        var menu = document.querySelector('ul.sidebar-menu, .sidebar-menu, aside .sidebar ul');

        if (!menu) {
            return false;
        }

        if (!yaEsta(menu)) {
            cabecera(menu);
            menu.appendChild(construir(menu));
        }

        limpiarCabecerasRepetidas(menu);

        return true;
    }

    function arrancar() {
        var puesto = inyectar();

        // Algunos temas dibujan el menu despues de cargar la pagina, y la otra
        // extension puede anadir su entrada mas tarde que esta. Se repasa unos
        // segundos para que el resultado sea el mismo caiga como caiga.
        var intentos = 0;
        var temporizador = window.setInterval(function () {
            intentos++;
            puesto = inyectar() || puesto;

            if (intentos > 20) {
                window.clearInterval(temporizador);
            }
        }, 250);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', arrancar);
    } else {
        arrancar();
    }
})();
