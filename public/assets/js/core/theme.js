/* ==========================================================================
   AppMenu · tema claro / oscuro
   Guarda la preferencia, aplica el tema antes del primer pintado y
   mantiene sincronizados todos los botones [data-theme-toggle].
   ========================================================================== */

(function () {
    'use strict';

    var KEY = 'appmenu.theme';
    var DARK = 'dark';
    var LIGHT = 'light';
    var META_COLOR = {
        dark: '#0b0a09',
        light: '#f7f3ec'
    };
    var LABELS = {
        es: {
            toLight: 'Activar tema claro',
            toDark: 'Activar tema oscuro'
        },
        en: {
            toLight: 'Switch to light theme',
            toDark: 'Switch to dark theme'
        }
    };

    function readPreference() {
        try {
            var value = localStorage.getItem(KEY);
            return value === LIGHT || value === DARK ? value : null;
        } catch (error) {
            return null;
        }
    }

    function normalize(theme) {
        return theme === LIGHT ? LIGHT : DARK;
    }

    function current() {
        return normalize(document.documentElement.getAttribute('data-theme'));
    }

    function language() {
        var lang = String(document.documentElement.lang || 'es').toLowerCase();
        return lang.indexOf('en') === 0 ? 'en' : 'es';
    }

    function refreshLabels() {
        var next = current() === LIGHT ? DARK : LIGHT;
        var labels = LABELS[language()];
        var label = next === LIGHT ? labels.toLight : labels.toDark;
        var buttons = document.querySelectorAll('[data-theme-toggle]');

        for (var index = 0; index < buttons.length; index += 1) {
            buttons[index].setAttribute('aria-label', label);
            buttons[index].setAttribute('title', label);
        }
    }

    function apply(theme, options) {
        var next = normalize(theme);
        var root = document.documentElement;
        var meta = document.querySelector('meta[name="theme-color"]');
        var settings = options || {};

        root.setAttribute('data-theme', next);
        root.style.colorScheme = next;

        if (meta) {
            meta.setAttribute('content', META_COLOR[next]);
        }

        refreshLabels();

        if (settings.announce !== false) {
            document.dispatchEvent(new CustomEvent('appmenu:themechange', {
                detail: { theme: next }
            }));
        }
    }

    function set(theme, persist) {
        var next = normalize(theme);
        apply(next);

        if (persist !== false) {
            try {
                localStorage.setItem(KEY, next);
            } catch (error) {
                /* Almacenamiento no disponible: el tema sigue aplicado en la sesión. */
            }
        }
    }

    function toggle() {
        set(current() === LIGHT ? DARK : LIGHT);
    }

    /* Aplica la preferencia (o el valor por defecto) antes de cargar los CSS. */
    apply(readPreference() || current(), { announce: false });

    document.addEventListener('click', function (event) {
        var button = event.target.closest ? event.target.closest('[data-theme-toggle]') : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        toggle();
    });

    window.addEventListener('storage', function (event) {
        if (event.key === KEY) {
            apply(normalize(event.newValue));
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', refreshLabels);
    } else {
        refreshLabels();
    }

    window.AppMenuTheme = {
        key: KEY,
        get: current,
        set: set,
        toggle: toggle,
        refreshLabels: refreshLabels
    };
})();
