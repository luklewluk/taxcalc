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
        reviewForm.addEventListener('submit', function (event) {
            // The ordinary calculate action is intercepted below and stays on
            // this page, so it must not disable the navigation-loss warning.
            if (event.submitter && !event.submitter.hasAttribute('formaction')) {
                return;
            }
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

    /* Accessible workbench tabs. Without this class every panel stays visible. */
    var tabsRoot = document.querySelector('[data-tabs]');
    var activateTab = function (name, focus) {
        if (!tabsRoot) {
            return;
        }
        var selected = tabsRoot.querySelector('[role="tab"][data-tab="' + name + '"]');
        if (!selected) {
            return;
        }
        tabsRoot.querySelectorAll('[role="tab"]').forEach(function (tab) {
            var active = tab === selected;
            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            tab.tabIndex = active ? 0 : -1;
        });
        tabsRoot.querySelectorAll('[role="tabpanel"]').forEach(function (panel) {
            panel.hidden = panel.getAttribute('data-panel') !== name;
        });
        if (focus) {
            selected.focus();
        }
        if (window.location.hash !== '#' + name) {
            history.replaceState(null, '', '#' + name);
        }
    };

    if (tabsRoot) {
        tabsRoot.classList.add('is-ready');
        tabsRoot.querySelectorAll('[role="tab"]').forEach(function (tab) {
            tab.addEventListener('click', function () {
                activateTab(tab.getAttribute('data-tab') || 'summary', false);
            });
            tab.addEventListener('keydown', function (event) {
                var tabs = Array.from(tabsRoot.querySelectorAll('[role="tab"]'));
                var index = tabs.indexOf(tab);
                var next = null;
                if (event.key === 'ArrowRight' || event.key === 'ArrowDown') {
                    next = tabs[(index + 1) % tabs.length];
                } else if (event.key === 'ArrowLeft' || event.key === 'ArrowUp') {
                    next = tabs[(index - 1 + tabs.length) % tabs.length];
                } else if (event.key === 'Home') {
                    next = tabs[0];
                } else if (event.key === 'End') {
                    next = tabs[tabs.length - 1];
                }
                if (next) {
                    event.preventDefault();
                    activateTab(next.getAttribute('data-tab') || 'summary', true);
                }
            });
        });
        var initialTab = window.location.hash.slice(1) || tabsRoot.getAttribute('data-initial-tab');
        activateTab(initialTab || 'summary', false);
        window.addEventListener('hashchange', function () {
            activateTab(window.location.hash.slice(1) || 'summary', false);
        });

        document.addEventListener('click', function (event) {
            var link = event.target instanceof Element ? event.target.closest('[data-attention-target]') : null;
            if (!link) {
                return;
            }
            event.preventDefault();
            activateTab(link.getAttribute('data-attention-target') || 'attention', false);
            var rowId = link.getAttribute('data-row-target');
            if (!rowId) {
                return;
            }
            var row = document.getElementById('row-' + rowId);
            var field = row ? row.querySelector('[name$="[country]"], input:not([type="hidden"]), select') : null;
            if (field) {
                field.focus();
            }
        });
    }

    var workbench = document.querySelector('form[data-workbench]');
    if (workbench) {
        var rowNumber = Date.now();
        var newId = function () {
            if (window.crypto && typeof window.crypto.randomUUID === 'function') {
                return window.crypto.randomUUID();
            }
            rowNumber += 1;
            return 'manual-' + rowNumber + '-' + Math.random().toString(16).slice(2);
        };

        document.querySelectorAll('[data-add-row]').forEach(function (button) {
            button.addEventListener('click', function () {
                var group = button.getAttribute('data-add-row');
                var template = document.querySelector('template[data-row-template="' + group + '"]');
                var body = document.querySelector('[data-editor-body="' + group + '"]');
                if (!template || !body) {
                    return;
                }
                rowNumber += 1;
                var holder = document.createElement('tbody');
                holder.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(rowNumber));
                var row = holder.firstElementChild;
                if (!row) {
                    return;
                }
                var id = newId();
                row.setAttribute('data-row-id', id);
                var idInput = row.querySelector('input[name$="[id]"]');
                if (idInput) {
                    idInput.value = id;
                }
                body.appendChild(row);
                var first = row.querySelector('input:not([type="hidden"]), select');
                if (first) {
                    first.focus();
                }
            });
        });

        var setTombstone = function (checkbox) {
            var row = checkbox.closest('tr');
            var idInput = row ? row.querySelector('input[name$="[id]"]') : null;
            if (!idInput || !idInput.value) {
                return;
            }
            var escaped = window.CSS && CSS.escape ? CSS.escape(idInput.value) : idInput.value.replace(/"/g, '\\"');
            var existing = workbench.querySelector('input[name="tombstones[]"][value="' + escaped + '"]');
            if (checkbox.checked && !existing) {
                var tombstone = document.createElement('input');
                tombstone.type = 'hidden';
                tombstone.name = 'tombstones[]';
                tombstone.value = idInput.value;
                workbench.appendChild(tombstone);
            } else if (!checkbox.checked && existing) {
                existing.remove();
            }
        };

        var timer = null;
        var controller = null;
        var revision = 0;
        var recalculate = function (switchToProblem) {
            revision += 1;
            var revisionInput = workbench.querySelector('[data-revision]');
            if (revisionInput) {
                revisionInput.value = String(revision);
            }
            if (controller) {
                controller.abort();
            }
            controller = new AbortController();
            fetch(workbench.action, {
                method: 'POST',
                body: new FormData(workbench),
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
                signal: controller.signal,
                credentials: 'same-origin'
            }).then(function (response) {
                if (!response.ok) {
                    throw new Error('HTTP ' + response.status);
                }
                return response.json();
            }).then(function (payload) {
                if (payload.version !== revision) {
                    return;
                }
                // A country staged in the attention panel has to survive the
                // fragment rewrite. The group id is derived from the
                // instrument, not from the first blank row, so it still names
                // the same control after rows are filled.
                var staged = {};
                var focused = document.activeElement instanceof Element
                    ? document.activeElement.getAttribute('data-country-group-apply')
                    : null;
                workbench.querySelectorAll('[data-country-group-select]').forEach(function (select) {
                    if (select instanceof HTMLSelectElement && select.value !== '') {
                        staged[select.getAttribute('data-country-group-select')] = select.value;
                    }
                });

                Object.keys(payload.fragments || {}).forEach(function (name) {
                    var target = workbench.querySelector('[data-fragment="' + name + '"]');
                    if (target) {
                        target.innerHTML = payload.fragments[name];
                    }
                });

                Object.keys(staged).forEach(function (groupId) {
                    var select = workbench.querySelector('[data-country-group-select="' + groupId + '"]');
                    if (select instanceof HTMLSelectElement) {
                        select.value = staged[groupId];
                    }
                });
                if (focused) {
                    var button = workbench.querySelector('[data-country-group-apply="' + focused + '"]');
                    if (button instanceof HTMLElement) {
                        button.focus();
                    }
                }
                if (switchToProblem && !payload.ok && payload.activeTab) {
                    activateTab(payload.activeTab, false);
                }
            }).catch(function (error) {
                if (error.name === 'AbortError') {
                    return;
                }
                var messages = workbench.querySelector('[data-fragment="messages"]');
                if (messages) {
                    messages.innerHTML = '<p class="message message--error">Automatyczne przeliczenie nie powiodło się. Użyj przycisku „Przelicz”.</p>';
                }
            });
        };

        workbench.addEventListener('input', function (event) {
            // The group country lives inside [data-fragment="attention"], which
            // recalculate() replaces wholesale. Recalculating from it would
            // delete the control the user is still using, so this staging field
            // both skips the debounce and disarms a run armed by an earlier
            // edit.
            if (event.target instanceof Element && event.target.hasAttribute('data-no-recalc')) {
                window.clearTimeout(timer);
                return;
            }
            // A setting changes values inside the editor tables, and those are
            // not AJAX fragments - recalculating in the background would leave
            // them showing the old countries while the summary used the new
            // ones. So a setting submits for real.
            if (event.target instanceof Element && event.target.hasAttribute('data-full-reload')) {
                window.clearTimeout(timer);
                if (controller) {
                    controller.abort();
                }
                var apply = workbench.querySelector('[data-apply-settings]');
                if (apply instanceof HTMLElement) {
                    apply.click();
                }
                return;
            }
            var input = event.target;
            if (!(input instanceof HTMLInputElement || input instanceof HTMLSelectElement) || input.type === 'file') {
                return;
            }
            if (input instanceof HTMLSelectElement && /\[country]$/.test(input.name)) {
                input.setCustomValidity('');
            }
            window.clearTimeout(timer);
            timer = window.setTimeout(function () { recalculate(false); }, 450);
        });
        workbench.addEventListener('change', function (event) {
            var input = event.target;
            if (input instanceof HTMLInputElement && /\[remove]$/.test(input.name)) {
                setTombstone(input);
            }
        });
        workbench.addEventListener('submit', function (event) {
            if (event.submitter && event.submitter.hasAttribute('formaction')) {
                return;
            }
            // The ordinary submit remains a complete no-JS fallback. With JS,
            // the explicit Recalculate button uses the same versioned endpoint.
            if (event.submitter && !event.submitter.hasAttribute('formaction')) {
                event.preventDefault();
                recalculate(true);
            }
        });

        var fieldValue = function (row, field) {
            var input = row.querySelector('[name$="[' + field + ']"]');
            return input ? input.value.trim().toUpperCase() : '';
        };
        var queueKey = function (row) {
            return fieldValue(row, 'broker') + '|' + (fieldValue(row, 'pool') || fieldValue(row, 'symbol'));
        };

        // Deliberately does NOT fill the rows client-side. The editor tables are
        // not AJAX fragments, so a client-side fill plus a background
        // recalculation would leave the server holding countries the visible
        // form no longer carries. The button submits for real, the server writes
        // the rows through CountryReview::groups() - one implementation of the
        // instrument identity - and the whole workbench re-renders. All this
        // handler does is refuse to submit an empty choice.
        workbench.addEventListener('click', function (event) {
            var apply = event.target instanceof Element ? event.target.closest('[data-country-group-apply]') : null;
            if (apply) {
                var groupId = apply.getAttribute('data-country-group-apply');
                var choice = workbench.querySelector('[data-country-group-select="' + groupId + '"]');
                if (!(choice instanceof HTMLSelectElement)) {
                    return;
                }
                if (!/^[A-Z]{2}$/.test(choice.value.trim().toUpperCase())) {
                    event.preventDefault();
                    choice.setCustomValidity('Wybierz kraj, który ma zostać ustawiony dla tego instrumentu.');
                    choice.reportValidity();
                    return;
                }
                choice.setCustomValidity('');
                window.clearTimeout(timer);
                if (controller) {
                    controller.abort();
                }
                return;
            }

            var button = event.target instanceof Element ? event.target.closest('[data-bulk-country]') : null;
            if (!button) {
                return;
            }

            event.preventDefault();
            var sourceRow = button.closest('tr');
            var sourceCountry = sourceRow ? sourceRow.querySelector('[name$="[country]"]') : null;
            if (!sourceRow || !(sourceCountry instanceof HTMLSelectElement)) {
                return;
            }
            var country = sourceCountry.value.trim().toUpperCase();
            if (!/^[A-Z]{2}$/.test(country)) {
                sourceCountry.setCustomValidity('Wybierz kraj, który ma zostać ustawiony w tej puli FIFO.');
                sourceCountry.reportValidity();
                return;
            }
            sourceCountry.setCustomValidity('');

            var sourceKey = queueKey(sourceRow);
            workbench.querySelectorAll('[data-editor-body="trades"] > tr').forEach(function (row) {
                if (queueKey(row) !== sourceKey) {
                    return;
                }
                var select = row.querySelector('[name$="[country]"]');
                if (select instanceof HTMLSelectElement && select.value.trim() === '') {
                    select.value = country;
                }
                row.querySelectorAll('.needs-country, .bulk-country').forEach(function (hint) {
                    hint.hidden = true;
                });
            });

            window.clearTimeout(timer);
            recalculate(false);
        });
    }
})();
