/**
 * Tbilisi-area picker (Mode B: Tbilisi + surrounding areas).
 *
 * The merchant has restricted checkout to Tbilisi and a curated set of
 * surrounding settlements. Region + Municipality fields are hidden;
 * the only customer-facing geo input is the City field, repurposed as
 * an Area dropdown with options "Tbilisi" + each surrounding settlement.
 *
 * When the customer picks an area, this script silently fills the
 * hidden State (Region) field with the corresponding WC state code so
 * tax / shipping / order meta resolve correctly. The Municipality
 * field isn't on the form in Tbilisi mode, so we skip it — server-side
 * order persistence (OrderMeta) can derive muni from the state + city
 * combination via the catalog if needed.
 *
 * The lookup table comes from PHP via wp_localize_script:
 *   window.CodeOnTbilisi.areas = [
 *     { key: 'tbilisi', state: 'TB', muni_id: 'tbilisi', city: 'Tbilisi' },
 *     { key: 's2473',   state: 'KA', muni_id: 'telavi',  city: 'კონდოლი' },
 *     ...
 *   ]
 */
(function ($) {
    'use strict';

    var cfg = window.CodeOnTbilisi;
    if (!cfg || !Array.isArray(cfg.areas) || cfg.areas.length === 0) {
        return;
    }

    var BY_KEY = {};
    cfg.areas.forEach(function (a) { BY_KEY[a.key] = a; });

    var SELECTORS = {
        country: '#billing_country, #shipping_country',
        state:   '#billing_state, #shipping_state',
        city:    '#billing_city, #shipping_city',
    };

    function applyArea(prefix) {
        var $city  = $('#' + prefix + 'city');
        var $state = $('#' + prefix + 'state');
        if (!$city.length) return;

        var key = $city.val();
        var area = key && BY_KEY[key] ? BY_KEY[key] : null;
        if (!area) return;

        // Silent .val() set — no .trigger('change') on state, otherwise
        // WC's update_checkout would re-fire and we'd thrash.
        if ($state.length) {
            $state.val(area.state);
            if ($state.data('select2')) {
                $state.trigger('change.select2');
            }
        }
    }

    function bind() {
        $(document.body)
            .off('change.codeonTbilisi')
            .on('change.codeonTbilisi', SELECTORS.city, function () {
                var prefix = $(this).attr('id').replace('city', '');
                applyArea(prefix);
            });

        ['billing_', 'shipping_'].forEach(function (prefix) {
            // If the form already has a city value (saved address), apply it.
            var $city = $('#' + prefix + 'city');
            if ($city.length && $city.val()) {
                applyArea(prefix);
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})(jQuery);
