/**
 * Classic-checkout cascade for Georgian addresses (v0.1.11+).
 *
 * Cascade UX:
 *   - Country = Georgia (already preselected on most GE shops)
 *   - Region (state) field is HIDDEN — auto-set from chosen Municipality
 *   - Municipality dropdown shows all 77 munis with region prefix labels
 *   - Settlement (city) cascades from Municipality
 *
 * Also enhances the Municipality + Settlement <select> elements with WC's
 * bundled Select2 so they look identical to the Country dropdown.
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

    // Cache REST responses for the page lifetime.
    const cache = new Map();
    async function fetchJson(path) {
        if (cache.has(path)) return cache.get(path);
        const resp = await fetch(cfg.restUrl + path, { headers: { 'Accept': 'application/json' } });
        if (!resp.ok) throw new Error('HTTP ' + resp.status);
        const data = await resp.json();
        cache.set(path, data);
        return data;
    }

    /**
     * Replace a <select>'s options. Does NOT trigger 'change' (that caused
     * the v0.1.5/0.1.6 infinite loop with WC's update_checkout).
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

        $select.prop('disabled', items.length === 0);

        // Re-init Select2 if it was already enhanced (so the visible UI
        // reflects the new options). select2('destroy') then recreate.
        if ($select.data('select2')) {
            $select.select2('destroy');
        }
        enhanceWithSelect2($select);
    }

    /**
     * Apply WC's bundled Select2 to a select so it looks identical to the
     * Country/State dropdowns. WC enqueues Select2 globally on checkout
     * (via the country_select handle), so it's always available here.
     */
    function enhanceWithSelect2($select) {
        if (typeof $.fn.select2 !== 'function') return;
        if ($select.data('select2')) return;
        $select.select2({
            width: '100%',
            placeholder: $select.find('option:first').text() || cfg.i18n.select,
            allowClear: false,
            // Match WC frontend's matcher behavior — case-insensitive substring.
        });
    }

    function gatherContext(prefix) {
        return {
            country:      $('#' + prefix + 'country').val(),
            state:        $('#' + prefix + 'state').val(),
            municipality: $('#' + prefix + 'municipality').val(),
            city:         $('#' + prefix + 'city').val(),
        };
    }

    /**
     * When the user picks a Municipality:
     *   1. Auto-set the (hidden) state field via cfg.munToState map.
     *   2. Cascade: refresh Settlement options for that muni.
     */
    async function onMunicipalityChange(prefix) {
        const ctx = gatherContext(prefix);
        const $state = $('#' + prefix + 'state');

        // 1) Auto-fill state. Use silent .val() set — no .trigger('change')
        //    here because WC's update_checkout would re-fire and we'd loop.
        //    The next user interaction (selecting Settlement) triggers
        //    update_checkout naturally and WC reads the updated state.
        if (cfg.munToState && ctx.municipality && cfg.munToState[ctx.municipality]) {
            const stateCode = cfg.munToState[ctx.municipality];
            $state.val(stateCode);
        }

        // 2) Cascade settlements.
        await refreshSettlements(prefix);
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
            const items = settles.map(function (s) {
                return { id: s.name_ka, name: s.name };
            });
            fillSelect($city, items, ctx.city, cfg.i18n.selectSettlement);
        } catch (e) {
            console.warn('CodeOnGeo: failed to load settlements', e);
        }
    }

    function bind() {
        $(document.body)
            .off('change.codeonGeo')
            .on('change.codeonGeo', SELECTORS.municipality, function () {
                const prefix = $(this).attr('id').replace('municipality', '');
                onMunicipalityChange(prefix);
            });

        // Initial enhancement + cascade. Each Municipality select gets
        // Select2; each Settlement select gets Select2 once it has options
        // (on first muni change). On page load, if muni is already chosen
        // (saved address), populate settlements immediately.
        ['billing_', 'shipping_'].forEach(function (prefix) {
            const $mun = $('#' + prefix + 'municipality');
            const $city = $('#' + prefix + 'city');
            if ($mun.length) enhanceWithSelect2($mun);
            if ($city.length) enhanceWithSelect2($city);

            const ctx = gatherContext(prefix);
            if (ctx.country === 'GE' && ctx.municipality) {
                onMunicipalityChange(prefix);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})(jQuery);
