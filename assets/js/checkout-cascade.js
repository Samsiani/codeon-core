/**
 * Classic-checkout cascade for Georgian addresses (v0.1.12).
 *
 * Default UX (Region hidden via setting):
 *   Country = Georgia
 *   Municipality (all 77 muns visible) → user picks → JS auto-sets state
 *   Settlement (cascades from Municipality)
 *
 * When Region is enabled in Settings (visible field):
 *   Picking Region narrows the Municipality dropdown to that region's muns.
 *   Picking Municipality auto-sets Region (if not already matching).
 *
 * Plus: WC's Select2 enhancement on Municipality + Settlement so they
 * look identical to the Country dropdown.
 */
(function ($) {
    'use strict';

    const cfg = window.CodeOnGeo;
    if (!cfg || !cfg.restUrl) return;

    const SELECTORS = {
        country:      '#billing_country, #shipping_country',
        state:        '#billing_state, #shipping_state',
        municipality: '#billing_municipality, #shipping_municipality',
        city:         '#billing_city, #shipping_city',
    };

    // muni id → wc state code (always present)
    // state code → [muni ids] (derived once)
    const MUN_TO_STATE = cfg.munToState || {};
    const STATE_TO_MUNS = {};
    for (const munId in MUN_TO_STATE) {
        const sc = MUN_TO_STATE[munId];
        (STATE_TO_MUNS[sc] = STATE_TO_MUNS[sc] || []).push(munId);
    }

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
     * Snapshot the original full Municipality option list once on first
     * touch. Used to restore the full list when Region is cleared.
     */
    function snapshotMunOptions($mun) {
        if ($mun.data('codeon-original-options')) return;
        const opts = [];
        $mun.find('option').each(function () {
            opts.push({ value: this.value, text: this.textContent });
        });
        $mun.data('codeon-original-options', opts);
    }

    function fillSelect($select, items, currentValue, placeholderText) {
        const dom = $select[0];
        while (dom.firstChild) dom.removeChild(dom.firstChild);
        const placeholderLabel = placeholderText || cfg.i18n.select;
        const placeholder = document.createElement('option');
        placeholder.value = '';
        placeholder.textContent = placeholderLabel;
        dom.appendChild(placeholder);

        let matched = false;
        items.forEach(function (it) {
            const opt = document.createElement('option');
            opt.value = it.id !== undefined ? it.id : it.value;
            opt.textContent = it.name !== undefined ? it.name : it.text;
            if (currentValue && (String(currentValue) === String(opt.value) || currentValue === opt.textContent)) {
                opt.selected = true;
                matched = true;
            }
            dom.appendChild(opt);
        });
        if (!matched) dom.selectedIndex = 0;
        $select.prop('disabled', items.length === 0);

        // Idempotent Select2 refresh: tear down any existing instance, then
        // re-enhance with the fresh placeholder. If destroy left stale data
        // behind, enhance() destroys again before re-initialising — so the
        // placeholder transition (e.g. "Choose Settlement" after Municipality
        // is picked) never gets stuck on the previous text.
        enhanceWithSelect2($select, placeholderLabel);
    }

    function enhanceWithSelect2($select, placeholderLabel) {
        if (typeof $.fn.select2 !== 'function') return;
        if ($select.data('select2')) {
            $select.select2('destroy');
        }
        $select.select2({
            width: '100%',
            placeholder: placeholderLabel || $select.find('option:first').text() || cfg.i18n.select,
            allowClear: false,
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
     * When Region (state) is visible AND user picks one, narrow the muni
     * options to munis of that region. If state cleared, restore full list.
     */
    function narrowMunByRegion(prefix, stateCode) {
        const $mun = $('#' + prefix + 'municipality');
        if (!$mun.length) return;
        snapshotMunOptions($mun);
        const all = $mun.data('codeon-original-options') || [];
        const currentMun = $mun.val();

        let filtered;
        if (!stateCode) {
            filtered = all.slice(1).map(o => ({ value: o.value, text: o.text }));
        } else {
            const allowed = STATE_TO_MUNS[stateCode] || [];
            filtered = all
                .filter(o => o.value && allowed.indexOf(o.value) !== -1)
                .map(o => ({ value: o.value, text: o.text }));
        }

        // Preserve current mun selection only if it survives the filter.
        const keepValue = filtered.some(o => o.value === currentMun) ? currentMun : '';
        fillSelect($mun, filtered, keepValue);
    }

    /**
     * Picking a Municipality: auto-set state (silent), then cascade settlements.
     */
    async function onMunicipalityChange(prefix) {
        const ctx = gatherContext(prefix);
        const $state = $('#' + prefix + 'state');
        if (MUN_TO_STATE[ctx.municipality]) {
            const stateCode = MUN_TO_STATE[ctx.municipality];
            // Silent .val() set — no .trigger('change') (would loop with WC).
            $state.val(stateCode);
            // If state field is enhanced (Select2), update the visible UI too.
            if ($state.data('select2')) {
                $state.trigger('change.select2');
            }
        }
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
            const items = settles.map(function (s) { return { id: s.name_ka, name: s.name }; });
            fillSelect($city, items, ctx.city, cfg.i18n.selectSettlement);
        } catch (e) {
            console.warn('CodeOnGeo: failed to load settlements', e);
        }
    }

    function bind() {
        $(document.body)
            .off('change.codeonGeo')
            .on('change.codeonGeo', SELECTORS.state, function () {
                const prefix = $(this).attr('id').replace('state', '');
                narrowMunByRegion(prefix, $(this).val());
            })
            .on('change.codeonGeo', SELECTORS.municipality, function () {
                const prefix = $(this).attr('id').replace('municipality', '');
                onMunicipalityChange(prefix);
            });

        ['billing_', 'shipping_'].forEach(function (prefix) {
            const $mun = $('#' + prefix + 'municipality');
            const $city = $('#' + prefix + 'city');
            if ($mun.length) enhanceWithSelect2($mun);
            if ($city.length) enhanceWithSelect2($city);

            const ctx = gatherContext(prefix);
            // If state is already chosen on page load (visible Region field
            // with saved address), narrow muni list to match.
            if (ctx.country === 'GE' && ctx.state) {
                narrowMunByRegion(prefix, ctx.state);
            }
            // If muni already chosen (saved address), populate settlements.
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
