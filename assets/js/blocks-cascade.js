/**
 * Block-checkout enhancement: narrow the settlement <datalist> to the
 * chosen municipality, and bind the datalist to the settlement input
 * the first time the user focuses it.
 *
 * v0.1.7 design: zero polling, zero MutationObserver, zero pre-emptive
 * DOM mutation. Just two delegated event listeners on document — one
 * for `focus` (binds the datalist once), one for `change` (narrows
 * the list). Avoids fighting WC Blocks' React reconciliation.
 *
 * Why focus-bind instead of server-side: WC Blocks's
 * woocommerce_register_additional_checkout_field rejects the `list`
 * attribute as invalid, so we can't pass it from PHP. Setting it on
 * focus is safe because React only re-renders on prop changes, and
 * `list` isn't a tracked prop.
 */
(function () {
    'use strict';

    function narrowDatalist(munId) {
        const dl = document.getElementById('codeon-geo-settlements');
        if (!dl) return;
        const opts = dl.options;
        for (let i = 0; i < opts.length; i++) {
            const match = !munId || opts[i].dataset.mun === munId;
            opts[i].disabled = !match;
            opts[i].hidden = !match;
        }
    }

    function isMunSelect(el) {
        return el && el.matches && (
            el.matches('[data-codeon-geo="municipality"]') ||
            (el.tagName === 'SELECT' && (el.name === 'codeon/municipality' ||
                                         el.id && el.id.indexOf('codeon-municipality') !== -1))
        );
    }

    function isSettlementInput(el) {
        return el && el.matches && (
            el.matches('[data-codeon-geo="settlement"]') ||
            (el.tagName === 'INPUT' && (el.name === 'codeon/settlement' ||
                                        el.id && el.id.indexOf('codeon-settlement') !== -1))
        );
    }

    // Bind datalist on first focus — by then React has rendered the input
    // and won't re-render on a non-tracked attribute mutation.
    document.addEventListener('focus', function (e) {
        const t = e.target;
        if (isSettlementInput(t) && !t.getAttribute('list')) {
            t.setAttribute('list', 'codeon-geo-settlements');
        }
    }, true);

    // Narrow datalist when municipality changes.
    document.addEventListener('change', function (e) {
        if (isMunSelect(e.target)) narrowDatalist(e.target.value);
    }, true);
})();
