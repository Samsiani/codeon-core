/**
 * Classic-checkout cascade for Georgian addresses.
 *
 * Watches the country / state / municipality fields. When any changes:
 *   1. country = GE → enable the cascade
 *   2. state changes → fetch municipalities for that region, populate select
 *   3. municipality changes → fetch settlements, populate the city select
 *
 * v0.1.7 fixes the infinite loop that v0.1.5/0.1.6 had: fillSelect used
 * to call $select.trigger('change') which fired WC's update_checkout
 * handler, which in turn re-ran our refreshMunicipalities, which called
 * fillSelect again — a tight loop that froze the page. Now fillSelect
 * is silent; we drive the cascade only off user-initiated change events
 * + a one-shot population on page load.
 */
(function ($) {
    'use strict';

    const cfg = window.CodeOnGeo;
    if (!cfg || !cfg.restUrl) return;

    const SELECTORS = {
        state:        '#billing_state, #shipping_state',
        municipality: '#billing_municipality, #shipping_municipality',
        city:         '#billing_city, #shipping_city',
    };

    // Cache REST responses for the lifetime of the page.
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

    /**
     * Populate a <select> with new options. Crucially does NOT trigger a
     * change event — the previous version's trigger('change') fired
     * WC's update_checkout, which re-rendered the form, which re-ran
     * the cascade, which called fillSelect again. Tight infinite loop.
     */
    function fillSelect($select, items, currentValue) {
        $select.prop('disabled', false);
        const dom = $select[0];
        while (dom.firstChild) dom.removeChild(dom.firstChild);

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = cfg.i18n.select;
        dom.appendChild(placeholder);

        let matched = false;
        items.forEach(function (it) {
            const opt = document.createElement('option');
            opt.value = it.id;
            opt.textContent = it.name;
            if (currentValue && (String(currentValue) === String(it.id) || currentValue === it.name)) {
                opt.selected = true;
                matched = true;
            }
            dom.appendChild(opt);
        });

        // If the saved value isn't in the new list, reset to placeholder.
        if (!matched) dom.selectedIndex = 0;

        // NO trigger('change') — that's what caused the loop.
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
            const muns = await fetchJson('regions/' + encodeURIComponent(ctx.state) + '/municipalities');
            fillSelect($mun, muns, ctx.municipality);
            await refreshSettlements(prefix);
        } catch (e) {
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
            // City field stores the settlement NAME (not id) — keeps WC
            // reports / customer addresses working with text values.
            const items = settles.map(function (s) {
                return { id: s.name_ka, name: s.name };
            });
            fillSelect($city, items, ctx.city);
        } catch (e) {
            console.warn('CodeOnGeo: failed to load settlements', e);
        }
    }

    function bind() {
        // User-initiated changes only — no listener on updated_checkout
        // (that was the loop trigger).
        $(document.body)
            .off('change.codeonGeo')
            .on('change.codeonGeo', SELECTORS.state, function () {
                const prefix = $(this).attr('id').replace('state', '');
                refreshMunicipalities(prefix);
            })
            .on('change.codeonGeo', SELECTORS.municipality, function () {
                const prefix = $(this).attr('id').replace('municipality', '');
                refreshSettlements(prefix);
            });

        // One-shot population on page load if country=GE & state already set.
        ['billing_', 'shipping_'].forEach(function (prefix) {
            const ctx = gatherContext(prefix);
            if (ctx.country === 'GE' && ctx.state) {
                refreshMunicipalities(prefix);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})(jQuery);
