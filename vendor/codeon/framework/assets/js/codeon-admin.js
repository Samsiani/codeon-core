/*
 * CodeOn Framework — admin chrome JavaScript.
 *
 * Vanilla, no framework, no build step. Handles:
 *   - conditional show_when: toggles rows whose [data-codeon-show] attribute
 *     resolves to true based on another field's current value
 *   - confirm guards: any button with [data-codeon-confirm] shows a JS
 *     confirm() before submitting
 *   - clipboard copy: [data-codeon-copy] buttons copy the attribute value
 *   - write-only password UX: leaving the field blank keeps the server-side
 *     stored value; on focus we hint that typing will overwrite
 *
 * Everything is keyed by data attributes so adding a new interaction never
 * requires a new script handle or a wp_localize_script call.
 */
(function () {
    'use strict';

    function $$(sel, root) {
        return Array.prototype.slice.call((root || document).querySelectorAll(sel));
    }

    // --- Conditional show_when ---------------------------------------------

    function inputValue(name) {
        var el = document.querySelector(
            '[name="codeon[' + name + ']"], [name="codeon[' + name + '][]"]'
        );
        if (!el) return '';
        if (el.type === 'checkbox') return el.checked ? '1' : '0';
        if (el.tagName === 'SELECT' && el.multiple) {
            return Array.prototype.map.call(el.selectedOptions, function (o) { return o.value; }).join(',');
        }
        // Radio group — find the checked one.
        if (el.type === 'radio') {
            var group = document.querySelectorAll('[name="codeon[' + name + ']"]');
            for (var i = 0; i < group.length; i++) if (group[i].checked) return group[i].value;
            return '';
        }
        return el.value;
    }

    function evalShowWhen(path, op, expected, actual) {
        switch (op) {
            case '=':
            case '==':
                return String(actual) === String(expected);
            case '!=':
                return String(actual) !== String(expected);
            case 'in':
                return String(expected).split(',').indexOf(String(actual)) !== -1;
            case 'truthy':
                return !!actual && actual !== '0';
            case 'falsy':
                return !actual || actual === '0';
            default:
                return true;
        }
    }

    function applyShowWhen() {
        $$('[data-codeon-show]').forEach(function (row) {
            var path = row.getAttribute('data-codeon-show');
            var op = row.getAttribute('data-codeon-show-op') || '=';
            var expected = row.getAttribute('data-codeon-show-value');
            var actual = inputValue(path);
            if (evalShowWhen(path, op, expected, actual)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function wireShowWhenWatchers() {
        document.addEventListener('change', function (e) {
            if (!e.target || !e.target.name) return;
            if (String(e.target.name).indexOf('codeon[') !== 0) return;
            applyShowWhen();
        });
        document.addEventListener('input', function (e) {
            if (!e.target || !e.target.name) return;
            if (String(e.target.name).indexOf('codeon[') !== 0) return;
            applyShowWhen();
        });
    }

    // --- Confirm guards ----------------------------------------------------

    function wireConfirmGuards() {
        document.addEventListener('click', function (e) {
            var target = e.target;
            while (target && target !== document.body) {
                if (target.hasAttribute && target.hasAttribute('data-codeon-confirm')) {
                    var msg = target.getAttribute('data-codeon-confirm') ||
                        (window.CodeOnFramework && window.CodeOnFramework.i18n && window.CodeOnFramework.i18n.confirmDestructive) ||
                        'This action cannot be undone. Continue?';
                    if (!window.confirm(msg)) {
                        e.preventDefault();
                        e.stopPropagation();
                    }
                    return;
                }
                target = target.parentNode;
            }
        });
    }

    // --- Clipboard copy ----------------------------------------------------

    function copyToClipboard(text) {
        if (navigator.clipboard && navigator.clipboard.writeText) {
            return navigator.clipboard.writeText(text);
        }
        // Legacy fallback.
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        return Promise.resolve();
    }

    function wireCopyButtons() {
        document.addEventListener('click', function (e) {
            var t = e.target;
            while (t && t !== document.body) {
                if (t.hasAttribute && t.hasAttribute('data-codeon-copy')) {
                    e.preventDefault();
                    var payload = t.getAttribute('data-codeon-copy');
                    copyToClipboard(payload).then(function () {
                        var msg = (window.CodeOnFramework && window.CodeOnFramework.i18n && window.CodeOnFramework.i18n.copied) || 'Copied.';
                        // Use textContent — never innerHTML — so no XSS surface
                        // even if a future caller passes user-supplied text.
                        var prev = t.textContent;
                        t.textContent = msg;
                        setTimeout(function () { t.textContent = prev; }, 1400);
                    });
                    return;
                }
                t = t.parentNode;
            }
        });
    }

    // --- Write-only password hint -----------------------------------------

    function wirePasswordWriteOnly() {
        $$('input[data-codeon-write-only="1"]').forEach(function (input) {
            input.addEventListener('focus', function () {
                if (input.placeholder && input.placeholder.indexOf('•') === 0) {
                    input.dataset.codeonOriginalPlaceholder = input.placeholder;
                    input.placeholder = '';
                }
            });
            input.addEventListener('blur', function () {
                if (input.value === '' && input.dataset.codeonOriginalPlaceholder) {
                    input.placeholder = input.dataset.codeonOriginalPlaceholder;
                }
            });
        });
    }

    // --- Radio-card click-anywhere ----------------------------------------

    function wireRadioCards() {
        $$('.codeon-radio-card').forEach(function (card) {
            var input = card.querySelector('input[type="radio"]');
            if (!input) return;
            card.addEventListener('click', function (e) {
                if (e.target === input) return;
                input.checked = true;
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
            input.addEventListener('change', function () {
                $$('.codeon-radio-card').forEach(function (c) {
                    var ci = c.querySelector('input[type="radio"]');
                    if (ci && ci.name === input.name) c.classList.toggle('is-on', ci.checked);
                });
            });
        });
    }

    // --- Boot --------------------------------------------------------------

    function boot() {
        applyShowWhen();
        wireShowWhenWatchers();
        wireConfirmGuards();
        wireCopyButtons();
        wirePasswordWriteOnly();
        wireRadioCards();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
