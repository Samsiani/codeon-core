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
     *
     * `placeholderText` allows showing a contextual hint when there are no
     * options ("Select region first…" instead of just "Select…"). Also
     * disables the select when there are no real options, since clicking
     * an empty dropdown is a confusing dead-end.
     */
    function fillSelect($select, items, currentValue, placeholderText) {
        const dom = $select[0];
        while (dom.firstChild) dom.removeChild(dom.firstChild);

        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = placeholderText || cfg.i18n.select;
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

        if (!matched) dom.selectedIndex = 0;

        // Disable when there are no real options — gives the user a visual
        // cue that something else needs to be filled in first.
        $select.prop('disabled', items.length === 0);
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
            fillSelect($mun, [], '', cfg.i18n.pickRegionFirst);
            // Cascade clear settlements too — stale options confuse.
            const $city = $('#' + prefix + 'city');
            if ($city.length) fillSelect($city, [], '', cfg.i18n.pickMuniFirst);
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
            fillSelect($city, [], '', cfg.i18n.pickMuniFirst);
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

        // Initial state on page load: cascade to fill if state is already
        // chosen, OR show the helpful "Pick region first" placeholder so
        // users know what to do instead of staring at an empty dropdown.
        ['billing_', 'shipping_'].forEach(function (prefix) {
            const ctx = gatherContext(prefix);
            if (ctx.country === 'GE' && ctx.state) {
                refreshMunicipalities(prefix);
            } else if (ctx.country === 'GE') {
                // Country is GE but state isn't — set the helpful placeholder
                // on muni + city so the user knows what blocks them.
                const $mun = $('#' + prefix + 'municipality');
                const $city = $('#' + prefix + 'city');
                if ($mun.length) fillSelect($mun, [], '', cfg.i18n.pickRegionFirst);
                if ($city.length) fillSelect($city, [], '', cfg.i18n.pickRegionFirst);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})(jQuery);
