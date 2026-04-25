/**
 * Classic-checkout cascade for Georgian addresses.
 *
 * Watches the country / state / municipality fields. When any changes:
 *   1. country = GE → enable the cascade
 *   2. state changes → fetch municipalities for that region, populate select
 *   3. municipality changes → fetch settlements, populate the city select
 *
 * Block checkout uses an entirely different machinery (M2). This script
 * targets WC's classic shortcode-based checkout (and the My Account
 * edit-address page).
 *
 * Built with vanilla JS + jQuery (because WC's update_checkout event
 * fires via jQuery and we need to listen to it).
 */
(function ($) {
    'use strict';

    const cfg = window.CodeOnGeo;
    if (!cfg || !cfg.restUrl) return;

    const SELECTORS = {
        country:     '#billing_country, #shipping_country',
        state:       '#billing_state, #shipping_state',
        municipality:'#billing_municipality, #shipping_municipality',
        city:        '#billing_city, #shipping_city',
    };

    // Cache REST responses for the lifetime of the page — the dataset
    // doesn't change between page loads, so re-fetching the same region's
    // muns when the customer toggles billing/shipping is wasteful.
    const cache = new Map();

    async function fetchJson(path) {
        if (cache.has(path)) return cache.get(path);
        const resp = await fetch(cfg.restUrl + path, {
            headers: { 'Accept': 'application/json' }
        });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        cache.set(path, data);
        return data;
    }

    function fillSelect($select, items, currentValue) {
        // WC's checkout JS may have set `disabled` on the select while a
        // refresh was in flight — re-enable.
        $select.prop('disabled', false);
        const dom = $select[0];
        while (dom.firstChild) dom.removeChild(dom.firstChild);

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = cfg.i18n.select;
        dom.appendChild(placeholder);

        items.forEach(function (it) {
            const opt = document.createElement('option');
            opt.value = it.id;
            opt.textContent = it.name;
            if (currentValue && String(currentValue) === String(it.id)) {
                opt.selected = true;
            }
            dom.appendChild(opt);
        });

        // For city, customers may have placed an order earlier and the
        // saved value is the settlement NAME (we use name as the city
        // field value, not the id). Mark it selected if it matches.
        if (currentValue && !$select.find('option[selected]').length) {
            const matched = $select.find('option').filter(function () {
                return $(this).text() === currentValue || $(this).val() === currentValue;
            });
            if (matched.length) matched.prop('selected', true);
        }

        // Re-trigger Select2 if WC enhanced this select.
        $select.trigger('change');
    }

    function gatherContext(prefix) {
        return {
            country:      $('#' + prefix + 'country').val(),
            state:        $('#' + prefix + 'state').val(),
            municipality: $('#' + prefix + 'municipality').val(),
            city:         $('#' + prefix + 'city').val(),
        };
    }

    async function refreshMunicipalities(prefix) {
        const ctx = gatherContext(prefix);
        const $mun = $('#' + prefix + 'municipality');
        if (!$mun.length) return;
        if (ctx.country !== 'GE' || !ctx.state) {
            fillSelect($mun, [], '');
            return;
        }
        try {
            // ctx.state can be either a region slug ("kakheti") or a WC
            // state code ("KA"). The REST controller accepts both.
            const muns = await fetchJson('regions/' + encodeURIComponent(ctx.state) + '/municipalities');
            fillSelect($mun, muns, ctx.municipality);
            await refreshSettlements(prefix);
        } catch (e) {
            // Soft fail — keep the existing options so the form is still
            // submittable. Console-log for debugging.
            console.warn('CodeOnGeo: failed to load municipalities', e);
        }
    }

    async function refreshSettlements(prefix) {
        const ctx = gatherContext(prefix);
        const $city = $('#' + prefix + 'city');
        if (!$city.length) return;
        if (ctx.country !== 'GE' || !ctx.municipality) {
            fillSelect($city, [], '');
            return;
        }
        try {
            const settles = await fetchJson('municipalities/' + encodeURIComponent(ctx.municipality) + '/settlements');
            // City field stores the settlement NAME (not id) so existing
            // WC reports / customer addresses keep working with text values.
            const items = settles.map(function (s) {
                return { id: s.name_ka, name: s.name };
            });
            fillSelect($city, items, ctx.city);
        } catch (e) {
            console.warn('CodeOnGeo: failed to load settlements', e);
        }
    }

    function bind() {
        // Fire when country, state, or municipality changes — for both
        // billing and shipping. Use delegated handlers so WC's checkout
        // re-renders (via update_checkout) don't drop our bindings.
        const onStateChange = function () {
            const prefix = $(this).attr('id').replace('state', '');
            refreshMunicipalities(prefix);
        };
        const onMunChange = function () {
            const prefix = $(this).attr('id').replace('municipality', '');
            refreshSettlements(prefix);
        };

        $(document.body)
            .on('change', SELECTORS.state, onStateChange)
            .on('change', SELECTORS.municipality, onMunChange)
            // When WC re-renders the checkout (shipping calculator, coupon,
            // address change), re-populate the dependent dropdowns from
            // current values.
            .on('updated_checkout', function () {
                ['billing_', 'shipping_'].forEach(refreshMunicipalities);
            });

        // Initial population on page load — covers the My Account edit-address
        // page where there's no update_checkout event.
        ['billing_', 'shipping_'].forEach(refreshMunicipalities);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})(jQuery);
