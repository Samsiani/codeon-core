/**
 * Block-checkout cascade enhancement.
 *
 * The Block checkout's custom-field schema is static at registration time
 * (set in PHP via woocommerce_register_additional_checkout_field). This
 * script layers a runtime cascade on top:
 *
 *   1. When State / region changes → hide municipality options whose
 *      data-region doesn't match.
 *   2. When Municipality changes → narrow the settlement <datalist> to
 *      options whose data-mun matches, so the browser's native
 *      autocomplete only suggests villages from that municipality.
 *
 * Pure DOM manipulation; no React, no WC Blocks data store dependency.
 * Runs on a MutationObserver so it survives Cart/Checkout block re-renders.
 */
(function () {
    'use strict';

    const cfg = window.CodeOnGeoBlocks || {};

    // Selectors targeting WC Blocks' rendered DOM. WC mangles slashes in the
    // field id (codeon/municipality → codeon-municipality) when generating
    // the input name attribute, so target by data-codeon-geo attribute we
    // set in PHP.
    function findFields(scope) {
        return {
            mun: scope.querySelector('[data-codeon-geo="municipality"]'),
            set: scope.querySelector('[data-codeon-geo="settlement"]'),
            datalist: document.getElementById('codeon-geo-settlements'),
            // WC Blocks renders state as a select with id ending in `-state`
            // for billing/shipping. There may be 0, 1, or 2 of these (cart
            // shows shipping only; checkout has both billing+shipping).
            states: Array.from(scope.querySelectorAll('select[id$="-state"], select[autocomplete="address-level1"]')),
        };
    }

    // ------------ municipality filter -------------------------------------
    // Hide muni options whose data-region doesn't match the current state.
    // First load: cache the original options on the select element.
    function filterMunicipalitiesByState(munSelect, stateValue) {
        if (!munSelect) return;
        if (!munSelect._codeonAllOptions) {
            munSelect._codeonAllOptions = Array.from(munSelect.options).map(o => ({
                value: o.value,
                label: o.textContent,
                region: o.dataset.region || '',
            }));
        }
        // We don't actually have data-region on the option in the current
        // implementation (WC Blocks select renderer strips data-* attrs).
        // For now this is a no-op pass-through. The PHP layer prefixes
        // each option label with the region, so the human can pick by
        // reading the label.
    }

    // ------------ settlement datalist narrowing ---------------------------
    // The <datalist id="codeon-geo-settlements"> is rendered server-side
    // with all 4,394 options + data-mun on each. When muni changes, hide
    // (or remove) options whose data-mun ≠ chosen muni so the browser's
    // autocomplete dropdown only suggests that municipality's settlements.
    function narrowDatalist(datalist, munId) {
        if (!datalist) return;
        const opts = datalist.querySelectorAll('option');
        if (!munId) {
            // Show all
            opts.forEach(o => { o.disabled = false; o.hidden = false; });
            return;
        }
        opts.forEach(o => {
            const match = o.dataset.mun === munId;
            o.disabled = !match;
            o.hidden = !match;
        });
    }

    function bind(scope) {
        const f = findFields(scope);
        if (!f.mun || !f.set) return; // not on a checkout page yet

        // Initial pass.
        f.states.forEach(s => filterMunicipalitiesByState(f.mun, s.value));
        narrowDatalist(f.datalist, f.mun.value);

        // Wire change listeners. WC Blocks fires native `change` events on
        // its inputs after committing to the cart/checkout data store.
        f.states.forEach(s => {
            s.removeEventListener('change', s._codeonStateHandler || (() => {}));
            s._codeonStateHandler = () => filterMunicipalitiesByState(f.mun, s.value);
            s.addEventListener('change', s._codeonStateHandler);
        });

        f.mun.removeEventListener('change', f.mun._codeonMunHandler || (() => {}));
        f.mun._codeonMunHandler = () => narrowDatalist(f.datalist, f.mun.value);
        f.mun.addEventListener('change', f.mun._codeonMunHandler);
    }

    function observeAndBind() {
        // Bind once if the fields are already there.
        bind(document);

        // Re-bind on any block re-render. WC Blocks unmounts/remounts the
        // checkout block on shipping calculation and other live-update events.
        const obs = new MutationObserver(() => bind(document));
        obs.observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', observeAndBind);
    } else {
        observeAndBind();
    }
})();
