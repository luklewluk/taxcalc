/**
 * Colour theme: follows the operating system unless the user picks one.
 *
 * Loaded without `defer` in <head>, before the stylesheet, so a pick is applied
 * before the first paint - a deferred script would flash the system theme on
 * every page. CSP allows no inline script, hence a file of its own.
 *
 * The pick is the only thing this application keeps in the browser: one word,
 * `light` or `dark`, under `pit38-theme`. Never financial data, never workbench
 * state. "Auto" removes the entry rather than storing it.
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
            if (mode === 'auto') {
                window.localStorage.removeItem(KEY);
            } else {
                window.localStorage.setItem(KEY, mode);
            }
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

    function sync(group) {
        group.querySelectorAll('[data-theme-choice]').forEach(function (button) {
            button.setAttribute('aria-pressed', String(button.getAttribute('data-theme-choice') === current));
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        var groups = document.querySelectorAll('[data-role="theme-switch"]');

        groups.forEach(function (group) {
            sync(group);
            group.hidden = false;

            group.addEventListener('click', function (event) {
                var button = event.target instanceof Element ? event.target.closest('[data-theme-choice]') : null;
                if (!button) {
                    return;
                }

                current = button.getAttribute('data-theme-choice');
                apply(current);
                write(current);
                groups.forEach(sync);
            });
        });
    });
})();
