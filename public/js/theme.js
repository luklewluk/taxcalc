/**
 * Colour theme: follows the operating system until the user picks one.
 *
 * Loaded without `defer` in <head>, before the stylesheet, so a pick is applied
 * before the first paint - a deferred script would flash the system theme on
 * every page. CSP allows no inline script, hence a file of its own.
 *
 * One round button in the header. It starts on "auto" (no stored pick); a click
 * pins the opposite of what is on screen, and from then on it flips between
 * light and dark.
 *
 * The pick is the only thing this application keeps in the browser: one word,
 * `light` or `dark`, under `pit38-theme`. Never financial data, never workbench
 * state. "Auto" is simply the absence of the entry.
 */
(function () {
    'use strict';

    var KEY = 'pit38-theme';
    var root = document.documentElement;

    function read() {
        try {
            var value = window.localStorage.getItem(KEY);

            return value === 'light' || value === 'dark' ? value : 'auto';
        } catch (error) {
            // Storage disabled or blocked: follow the system.
            return 'auto';
        }
    }

    function write(mode) {
        try {
            window.localStorage.setItem(KEY, mode);
        } catch (error) {
            // The pick still applies to this page; it just is not remembered.
        }
    }

    /*
     * The browser chrome follows <meta name="theme-color">, which the layout
     * ships as a light/dark pair gated on the system preference. A pinned mode
     * points both at that mode's colour; "auto" restores what the markup said.
     */
    var schemeMeta = document.querySelector('meta[name="color-scheme"]');
    var colourMetas = Array.prototype.slice.call(document.querySelectorAll('meta[name="theme-color"]'));
    var colours = {};

    colourMetas.forEach(function (meta) {
        meta.setAttribute('data-original', meta.content);
        var media = meta.getAttribute('media') || '';
        if (media.indexOf('dark') !== -1) {
            colours.dark = meta.content;
        } else if (media.indexOf('light') !== -1) {
            colours.light = meta.content;
        }
    });

    function apply(mode) {
        if (mode === 'auto') {
            root.removeAttribute('data-theme');
        } else {
            root.setAttribute('data-theme', mode);
        }

        if (schemeMeta) {
            schemeMeta.content = mode === 'auto' ? 'light dark' : mode;
        }

        colourMetas.forEach(function (meta) {
            meta.content = mode === 'auto' || !colours[mode]
                ? meta.getAttribute('data-original')
                : colours[mode];
        });
    }

    var current = read();
    apply(current);

    var systemDark = typeof window.matchMedia === 'function'
        ? window.matchMedia('(prefers-color-scheme: dark)')
        : null;

    /** What is on screen: the pick, or the system's theme while there is none. */
    function effective() {
        if (current !== 'auto') {
            return current;
        }

        return systemDark && systemDark.matches ? 'dark' : 'light';
    }

    var NAMES = {auto: 'automatyczny (jak w systemie)', light: 'jasny', dark: 'ciemny'};

    function sync(toggle) {
        var label = 'Motyw: ' + NAMES[current] + '. Przełącz na '
            + (effective() === 'dark' ? 'jasny' : 'ciemny') + '.';
        toggle.setAttribute('data-theme-state', current);
        toggle.setAttribute('aria-label', label);
        toggle.setAttribute('title', label);
    }

    document.addEventListener('DOMContentLoaded', function () {
        var toggles = document.querySelectorAll('[data-role="theme-toggle"]');

        toggles.forEach(function (toggle) {
            sync(toggle);
            toggle.hidden = false;

            toggle.addEventListener('click', function () {
                current = effective() === 'dark' ? 'light' : 'dark';
                apply(current);
                write(current);
                toggles.forEach(sync);
            });
        });

        // While on "auto" the label says what a click will do, and that
        // follows the system.
        if (systemDark && typeof systemDark.addEventListener === 'function') {
            systemDark.addEventListener('change', function () {
                toggles.forEach(sync);
            });
        }
    });
})();
