/**
 * Progressive enhancement only - every page works with JavaScript disabled.
 * No third-party code, no network calls, no storage.
 */
(function () {
    'use strict';

    document.addEventListener('click', function (event) {
        var target = event.target;
        if (target instanceof Element && target.closest('[data-action="print"]')) {
            window.print();
        }
    });

    /**
     * The print dialog can only be opened from script, so the markup ships the
     * button hidden and states the keyboard shortcut instead. Swap the two round
     * here: a control the user can see must always do something.
     */
    document.querySelectorAll('[data-action="print"][hidden]').forEach(function (button) {
        button.hidden = false;
    });

    document.querySelectorAll('[data-role="print-fallback"]').forEach(function (hint) {
        hint.hidden = true;
    });

    /**
     * Fade rows marked for removal so the effect is visible before the form is
     * submitted.
     */
    document.addEventListener('change', function (event) {
        var input = event.target;
        if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') {
            return;
        }

        if (!/\[remove]$/.test(input.name)) {
            return;
        }

        var row = input.closest('tr');
        if (row) {
            row.classList.toggle('row--removed', input.checked);
        }
    });

    /**
     * Warn before leaving a filled-in review form: the data lives only in this
     * page, so navigating away really does lose it.
     */
    var reviewForm = document.querySelector('form[data-role="review"]');
    if (reviewForm) {
        var submitting = false;
        reviewForm.addEventListener('submit', function () {
            submitting = true;
        });

        window.addEventListener('beforeunload', function (event) {
            if (submitting) {
                return undefined;
            }

            event.preventDefault();
            event.returnValue = '';
            return '';
        });
    }

    /**
     * Upload area: tell the user what they picked, and accept a drop onto the
     * whole box rather than only onto the file control itself. Keyboard and
     * pointer users keep the native control, which is why nothing here is
     * required for the form to work.
     */
    var dropzone = document.querySelector('[data-role="dropzone"]');
    var fileInput = dropzone ? dropzone.querySelector('input[type="file"]') : null;

    if (dropzone && fileInput) {
        var status = dropzone.querySelector('[data-role="file-summary"]');

        var describe = function (count) {
            if (count === 0) {
                return '';
            }

            if (count === 1) {
                return '1 plik wybrany';
            }

            var tens = count % 100;
            var units = count % 10;
            if (units >= 2 && units <= 4 && (tens < 12 || tens > 14)) {
                return count + ' pliki wybrane';
            }

            return count + ' plików wybranych';
        };

        var refresh = function () {
            var count = fileInput.files ? fileInput.files.length : 0;
            dropzone.classList.toggle('is-filled', count > 0);
            if (status) {
                status.textContent = describe(count);
            }
        };

        fileInput.addEventListener('change', refresh);
        refresh();

        ['dragenter', 'dragover'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                event.preventDefault();
                dropzone.classList.add('is-dragover');
            });
        });

        ['dragleave', 'dragend'].forEach(function (type) {
            dropzone.addEventListener(type, function (event) {
                if (event.target === dropzone) {
                    dropzone.classList.remove('is-dragover');
                }
            });
        });

        dropzone.addEventListener('drop', function (event) {
            event.preventDefault();
            dropzone.classList.remove('is-dragover');

            if (!event.dataTransfer || !event.dataTransfer.files.length) {
                return;
            }

            try {
                fileInput.files = event.dataTransfer.files;
            } catch (error) {
                // Older browsers do not allow assigning a FileList; the native
                // control still works, so there is nothing to recover from.
                return;
            }

            refresh();
        });
    }

    /**
     * Printing must never lose content that happens to sit inside a collapsed
     * disclosure. Open everything before the dialog, restore afterwards.
     */
    var reopen = [];

    var expandForPrint = function () {
        reopen = [];
        document.querySelectorAll('details:not([open])').forEach(function (details) {
            details.open = true;
            reopen.push(details);
        });
    };

    var restoreAfterPrint = function () {
        reopen.forEach(function (details) {
            details.open = false;
        });
        reopen = [];
    };

    window.addEventListener('beforeprint', expandForPrint);
    window.addEventListener('afterprint', restoreAfterPrint);

    if (typeof window.matchMedia === 'function') {
        var printQuery = window.matchMedia('print');
        if (typeof printQuery.addEventListener === 'function') {
            printQuery.addEventListener('change', function (event) {
                if (event.matches) {
                    expandForPrint();
                } else {
                    restoreAfterPrint();
                }
            });
        }
    }
})();
