/*
 * CodeOn Installment Estimator — vanilla JS, no jQuery dependency.
 *
 * Wires every [data-codeon-estimator] card on the page:
 *   - principal, apr, allowed term list, default term read from data-attrs
 *   - dragging [data-codeon-est-slider] freely lets the thumb track the
 *     mouse, then snaps the value to the nearest allowed term on every input
 *   - clicking a mark label ([data-codeon-est-pill]) jumps to that term
 *   - active mark, slider value, monthly amount, months counter, and the
 *     orange progress fill update in lock-step
 *
 * Math mirrors PHP InstallmentEstimator::monthlyPayment() exactly so the SSR
 * figure matches the JS recomputation for the same default term.
 *
 * Re-inits on jQuery `updated_checkout` / `payment_method_selected` so the
 * widget survives WC's classic-checkout fragment refresh.
 */
(function () {
    'use strict';

    function monthlyPayment(principal, months, aprPct) {
        if (principal <= 0 || months <= 0) {
            return 0;
        }
        var r = (aprPct / 100) / 12;
        if (r <= 0) {
            return principal / months;
        }
        var factor = Math.pow(1 + r, months);
        return principal * r * factor / (factor - 1);
    }

    function formatMoney(value) {
        var fixed = (Math.round(value * 100) / 100).toFixed(2);
        var parts = fixed.split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return parts.join('.');
    }

    function snapToAllowed(value, allowed) {
        var best = allowed[0];
        var bestDelta = Math.abs(value - best);
        for (var i = 1; i < allowed.length; i++) {
            var delta = Math.abs(value - allowed[i]);
            if (delta < bestDelta) {
                best = allowed[i];
                bestDelta = delta;
            }
        }
        return best;
    }

    function init(card) {
        if (card.getAttribute('data-codeon-est-ready') === '1') {
            return;
        }
        card.setAttribute('data-codeon-est-ready', '1');

        var principal = parseFloat(card.getAttribute('data-principal')) || 0;
        var apr = parseFloat(card.getAttribute('data-apr')) || 0;
        var monthsAttr = card.getAttribute('data-months') || '[]';
        var defaultMonths = parseInt(card.getAttribute('data-default'), 10) || 0;

        // `data-months` is now a flat sorted array of allowed term lengths.
        // The widget no longer ships per-term monthly amounts because the
        // formula is small enough to recompute on the client.
        var allowed = [];
        try {
            var parsed = JSON.parse(monthsAttr);
            if (Array.isArray(parsed)) {
                for (var k = 0; k < parsed.length; k++) {
                    var n = parseInt(parsed[k], 10);
                    if (!isNaN(n)) {
                        allowed.push(n);
                    }
                }
            }
        } catch (e) { /* fall through with empty allowed */ }
        allowed.sort(function (a, b) { return a - b; });
        if (allowed.length === 0) {
            return;
        }
        var minM = allowed[0];
        var maxM = allowed[allowed.length - 1];

        var marks = card.querySelectorAll('[data-codeon-est-pill]');
        var slider = card.querySelector('[data-codeon-est-slider]');
        var monthlyEl = card.querySelector('[data-codeon-est-monthly]');
        var monthsEl = card.querySelector('[data-codeon-est-months]');

        function setProgress(months) {
            // Drives the orange filled portion of the slider track via CSS var.
            // Linear across the value range — matches the slider's own thumb
            // position semantics exactly.
            var span = maxM - minM;
            var pct = span > 0 ? ((months - minM) / span) * 100 : 50;
            if (pct < 0) { pct = 0; } else if (pct > 100) { pct = 100; }
            card.style.setProperty('--codeon-est-progress', pct + '%');
        }

        function setTerm(months) {
            var monthly = monthlyPayment(principal, months, apr);
            if (monthlyEl) {
                monthlyEl.textContent = formatMoney(monthly);
            }
            if (monthsEl) {
                monthsEl.textContent = String(months);
            }
            for (var i = 0; i < marks.length; i++) {
                var markMonths = parseInt(marks[i].getAttribute('data-codeon-est-pill'), 10);
                var active = markMonths === months;
                if (active) {
                    marks[i].classList.add('is-active');
                } else {
                    marks[i].classList.remove('is-active');
                }
                marks[i].setAttribute('aria-pressed', active ? 'true' : 'false');
            }
            if (slider && parseInt(slider.value, 10) !== months) {
                slider.value = String(months);
            }
            setProgress(months);
        }

        for (var i = 0; i < marks.length; i++) {
            (function (mark) {
                mark.addEventListener('click', function (e) {
                    e.preventDefault();
                    var m = parseInt(mark.getAttribute('data-codeon-est-pill'), 10);
                    if (!isNaN(m)) {
                        setTerm(m);
                    }
                });
            })(marks[i]);
        }

        if (slider) {
            var onSlide = function () {
                var raw = parseInt(slider.value, 10);
                if (isNaN(raw)) {
                    return;
                }
                setTerm(snapToAllowed(raw, allowed));
            };
            slider.addEventListener('input', onSlide);
            slider.addEventListener('change', onSlide);
        }

        // Re-render with the default term so JS-driven values exactly match
        // PHP's SSR figure (defensive against float-precision drift).
        var startMonths = allowed.indexOf(defaultMonths) !== -1 ? defaultMonths : allowed[0];
        setTerm(startMonths);
    }

    function initAll(root) {
        var scope = root || document;
        var cards = scope.querySelectorAll('[data-codeon-estimator]');
        for (var i = 0; i < cards.length; i++) {
            init(cards[i]);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { initAll(); });
    } else {
        initAll();
    }

    if (typeof window.jQuery !== 'undefined') {
        window.jQuery(document.body).on('updated_checkout payment_method_selected', function () {
            initAll();
        });
    }

    document.addEventListener('codeon-estimator:rescan', function () { initAll(); });
})();
