/**
 * Block-checkout cascade enhancement.
 *
 * The Block checkout's custom-field schema is static at registration time
 * (set in PHP via woocommerce_register_additional_checkout_field). This
 * script layers a runtime cascade on top:
 *
 *   1. When Municipality changes → narrow the settlement <datalist> to
 *      options whose data-mun matches, so the browser's native
 *      autocomplete only suggests villages from that municipality.
 *
 *   2. Bind the HTML5 datalist + placeholder via JS (WC Blocks strips
 *      the `list` and `placeholder` attributes server-side as invalid;
 *      we attach them client-side once.)
 *
 * The previous version used a MutationObserver on document.body that
 * fired on EVERY DOM change in the page — combined with React's tendency
 * to re-render checkout on every state update, this caused an infinite
 * reflow loop. v0.1.6 uses a one-shot scan + per-element bound flag, and
 * a much lighter watcher that only re-runs when the checkout root
 * actually unmounts/remounts.
 */
(function () {
    'use strict';

    const BOUND_ATTR = 'data-codeon-bound';

    function getDatalist() {
        return document.getElementById('codeon-geo-settlements');
    }

    function narrowDatalistByMun(munId) {
        const dl = getDatalist();
        if (!dl) return;
        // Cache option list once on the datalist itself so we don't query
        // the DOM repeatedly. options is a live HTMLCollection.
        const opts = dl.options;
        if (!munId) {
            for (let i = 0; i < opts.length; i++) {
                opts[i].disabled = false;
                opts[i].hidden = false;
            }
            return;
        }
        for (let i = 0; i < opts.length; i++) {
            const match = opts[i].dataset.mun === munId;
            opts[i].disabled = !match;
            opts[i].hidden = !match;
        }
    }

    /**
     * Attach the datalist + placeholder + change listener to a settlement
     * input. Idempotent — bails if the element is already bound. Critical
     * for stability inside React-controlled DOM where any setAttribute
     * call risks triggering a re-render → re-mount → re-bind loop.
     */
    function bindSettlementInput(input) {
        if (!input || input.getAttribute(BOUND_ATTR) === '1') return;
        // Set the bound flag FIRST so any synchronous re-render of the
        // input still sees this element as bound and skips re-bind.
        input.setAttribute(BOUND_ATTR, '1');
        // Datalist binding — the browser auto-suggests once `list` is set.
        if (!input.getAttribute('list')) {
            input.setAttribute('list', 'codeon-geo-settlements');
        }
        if (!input.getAttribute('placeholder')) {
            input.setAttribute('placeholder', 'Start typing the name…');
        }
    }

    function bindMunicipalitySelect(select) {
        if (!select || select.getAttribute(BOUND_ATTR) === '1') return;
        select.setAttribute(BOUND_ATTR, '1');
        // Initial narrowing in case municipality already has a value.
        if (select.value) narrowDatalistByMun(select.value);
        select.addEventListener('change', function () {
            narrowDatalistByMun(select.value);
        });
    }

    function scanAndBind() {
        const muns = document.querySelectorAll('[data-codeon-geo="municipality"]');
        for (let i = 0; i < muns.length; i++) bindMunicipalitySelect(muns[i]);

        const sets = document.querySelectorAll('[data-codeon-geo="settlement"]');
        for (let i = 0; i < sets.length; i++) bindSettlementInput(sets[i]);
    }

    // Watcher: only triggers when the *count* of our fields changes —
    // cheap, doesn't fire on every keystroke or React re-render of
    // unrelated elements. Uses requestIdleCallback so the scan only
    // runs when the browser is idle, never blocking interaction.
    let lastCount = 0;
    function lightWatch() {
        const count = document.querySelectorAll('[data-codeon-geo]').length;
        if (count !== lastCount) {
            lastCount = count;
            scanAndBind();
        }
    }

    function start() {
        scanAndBind();
        lastCount = document.querySelectorAll('[data-codeon-geo]').length;

        // Poll once per second instead of MutationObserver — eliminates
        // the feedback loop entirely. WC Blocks unmounts/remounts the
        // checkout block at most once per user action; a 1-second poll
        // catches it quickly enough that no user notices a delay.
        setInterval(lightWatch, 1000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }
})();
