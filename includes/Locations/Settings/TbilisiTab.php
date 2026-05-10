<?php
/**
 * Tbilisi & surroundings settings tab.
 *
 * Three controls:
 *   - master toggle (`tbilisi_only_mode`) — turns the override on
 *   - scope radio (`tbilisi_scope`) — Tbilisi only, OR Tbilisi + surroundings
 *   - surroundings picker (`tbilisi_surrounding_settlements`) — Select2-ajax
 *     over the 4,394-strong settlement dataset, results fetched via the
 *     existing `/wp-json/codeon-geo/v1/search` endpoint
 *
 * The picker is rendered via `Field::raw()` because the settlements list
 * is too large to inline as a static `<select>`. Currently-saved
 * settlements get pre-rendered as `<option selected>` so Select2's
 * initial value is hydrated server-side without a separate REST call.
 *
 * @package CodeOn\Core\Locations\Settings
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Settings;

defined('ABSPATH') || exit;

use CodeOn\Core\Locations\Data\DisplayFormatter;
use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Framework\Admin\Tab;
use CodeOn\Framework\Schema\Field;
use CodeOn\Framework\Storage\SettingsRepository;

final class TbilisiTab extends Tab
{
    public function __construct(private readonly SettingsRepository $repo) {}

    public function slug(): string
    {
        return 'tbilisi';
    }

    public function label(): string
    {
        return __('Tbilisi & surroundings', 'codeon-core');
    }

    public function repository(): SettingsRepository
    {
        return $this->repo;
    }

    /**
     * Manually thread the surroundings multiselect through the save
     * pipeline. Framework's FieldValidator skips Field::RAW entries
     * (no built-in sanitizer / validator), so the picker's POST data
     * never reaches the repository. We pull the raw POST here,
     * sanitize to a list of positive integers (settlement IDs), and
     * inject it into the clean payload that the framework persists.
     *
     * @param array<string,mixed> $clean
     * @return array<string,mixed>
     */
    public function beforeSave(array $clean): array
    {
        $posted = (isset($_POST['codeon']) && is_array($_POST['codeon']))
            ? wp_unslash($_POST['codeon'])
            : [];
        $raw = $posted['tbilisi_surrounding_settlements'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $repo = Repository::instance();
        $ids  = [];
        foreach ($raw as $candidate) {
            $sid = (int) $candidate;
            if ($sid <= 0) continue;
            if ($repo->settlement($sid) === null) continue;
            $ids[] = $sid;
        }
        $clean['tbilisi_surrounding_settlements'] = array_values(array_unique($ids));
        return $clean;
    }

    /**
     * Render strategy:
     *
     *   1) Emit a tiny inline <style> BEFORE the schema renders so our
     *      conditional rows are `display: none` from the very first
     *      paint — eliminates the visible→hidden FOUC flash.
     *
     *   2) Delegate to the framework schema renderer.
     *
     *   3) Emit a self-contained inline <script> AFTER the rows that:
     *        - detaches our rows from the framework's broken automatic
     *          showWhen logic (removes `data-codeon-show*` so the
     *          framework JS skips them entirely)
     *        - runs our own reveal logic immediately — synchronously
     *          during parsing, before any DOMContentLoaded handler
     *          (framework's or otherwise) can fire.
     *
     * Why bypass the framework's showWhen entirely:
     *   The framework renders every checkbox as a hidden `<input
     *   type="hidden" value="0">` next to the real checkbox sharing
     *   the same name (so unchecked boxes still POST 0). Its
     *   `inputValue()` helper reads `[name="codeon[…]"]` via plain
     *   `querySelector` and matches the hidden input first — so it
     *   always returns "0" regardless of checkbox state. Every
     *   checkbox-gated showWhen across every consumer plugin is
     *   silently broken. Patching the vendored framework JS in
     *   codeon-core doesn't help on stores that have other CodeOn
     *   plugins co-installed (WP only registers one URL per script
     *   handle, and the first registration wins — including its
     *   stale framework JS). A scoped, framework-independent reveal
     *   per tab is the only fix guaranteed to work everywhere.
     */
    public function render(string $nonceAction): void
    {
        $this->renderHidingStyle();
        parent::render($nonceAction);
        $this->renderRevealScript();
    }

    private function renderHidingStyle(): void
    {
        // Pre-paint hide of the conditional rows. The reveal script
        // below removes the data-codeon-show attribute on rows that
        // should actually be visible, so this rule stops matching them
        // — at which point default <tr> display takes over again.
        ?>
        <style>
            tr[data-codeon-show="tbilisi_only_mode"],
            tr[data-codeon-show="tbilisi_scope"] { display: none; }
        </style>
        <?php
    }

    private function renderRevealScript(): void
    {
        ?>
        <script>
        (function () {
            'use strict';

            // Snapshot our rows once, then strip their showWhen attrs
            // so the framework's (broken) automatic logic ignores them
            // entirely. After this, we own visibility for these rows.
            var ourRows = [];
            Array.prototype.forEach.call(
                document.querySelectorAll(
                    '[data-codeon-show="tbilisi_only_mode"], [data-codeon-show="tbilisi_scope"]'
                ),
                function (row) {
                    ourRows.push({
                        el:    row,
                        path:  row.getAttribute('data-codeon-show'),
                        value: row.getAttribute('data-codeon-show-value') || '',
                    });
                    row.removeAttribute('data-codeon-show');
                    row.removeAttribute('data-codeon-show-op');
                    row.removeAttribute('data-codeon-show-value');
                }
            );

            function masterChecked() {
                var cb = document.querySelector('input[name="codeon[tbilisi_only_mode]"][type="checkbox"]');
                return !!(cb && cb.checked);
            }
            function scopeValue() {
                var radios = document.querySelectorAll('input[name="codeon[tbilisi_scope]"]');
                for (var i = 0; i < radios.length; i++) {
                    if (radios[i].checked) return radios[i].value;
                }
                return '';
            }
            function applyReveal() {
                for (var i = 0; i < ourRows.length; i++) {
                    var row = ourRows[i];
                    var visible;
                    if (row.path === 'tbilisi_only_mode') {
                        visible = masterChecked();
                    } else if (row.path === 'tbilisi_scope') {
                        visible = masterChecked() && scopeValue() === row.value;
                    } else {
                        visible = false;
                    }
                    row.el.style.display = visible ? '' : 'none';
                }
            }

            // Synchronous first run while still parsing — eliminates
            // the visible→hidden flicker. The rows are CSS-hidden by
            // default; this call shows the ones that should be shown.
            applyReveal();

            // Live updates as the merchant flips controls.
            document.addEventListener('change', function (e) {
                if (!e.target || !e.target.name) return;
                var n = String(e.target.name);
                if (n !== 'codeon[tbilisi_only_mode]' && n !== 'codeon[tbilisi_scope]') return;
                applyReveal();
            });
        })();
        </script>
        <?php
    }

    public function schema(): array
    {
        return [
            Field::heading('h_tb_master', __('Tbilisi-area mode', 'codeon-core'),
                __('When enabled, checkout is restricted to Tbilisi-area locations and overrides ALL other location rules — General-tab field modes and the master Locations switch are ignored. Other WC field toggles (Country, Company, Address line 2, Postcode) keep working.', 'codeon-core')),

            Field::checkbox('tbilisi_only_mode', __('Restrict checkout to Tbilisi area', 'codeon-core'))
                ->default(false)
                ->description(__('When ON, the Region / Municipality / Settlement cascade is replaced by the simpler Tbilisi-area picker chosen below. When OFF, the cascade behaves per the General tab.', 'codeon-core')),

            Field::radio('tbilisi_scope', __('Coverage', 'codeon-core'))
                ->options([
                    TbilisiMode::SCOPE_ONLY => __('Tbilisi only — no area picker shown to customers; only the address fields appear and the order is silently filed under Tbilisi.', 'codeon-core'),
                    TbilisiMode::SCOPE_PLUS => __('Tbilisi + surrounding areas — customer picks an Area from a single dropdown (Tbilisi or one of the settlements you select below).', 'codeon-core'),
                ])
                ->default(TbilisiMode::SCOPE_ONLY)
                ->showWhen('tbilisi_only_mode', 'truthy', ''),

            Field::raw('tbilisi_surrounding_settlements', function ($value): void {
                $this->renderSettlementsPicker((array) ($value ?? []));
            })->showWhen('tbilisi_scope', '=', TbilisiMode::SCOPE_PLUS),
        ];
    }

    /**
     * Custom Select2-ajax multiselect over the full settlement dataset.
     * Currently-saved IDs are pre-rendered as `<option selected>` so the
     * field hydrates without a round-trip; new picks come from the
     * existing `/codeon-geo/v1/search` endpoint.
     *
     * @param list<int|string> $current
     */
    private function renderSettlementsPicker(array $current): void
    {
        $repo = Repository::instance();
        $fmt  = DisplayFormatter::fromOptions();

        $name = 'codeon[tbilisi_surrounding_settlements]';
        $id   = 'codeon_tbilisi_surrounding_settlements';

        ?>
        <style>
            /* Scoped Select2 styling for the surroundings picker.
               Modelled after WooCommerce's country-selector multiselect:
               light-grey container, pills floated above, dedicated
               search-input row at the bottom with a visible white
               background + border so typed text is unambiguously
               readable. `containerCssClass` / `dropdownCssClass` in
               the init below append these classes to the rendered
               markup so the rules don't bleed into other Select2
               instances. */

            .select2-container.codeon-tbilisi-picker {
                min-width: 560px;
                max-width: 820px;
            }

            /* The visible picker box. Light-grey background ("inkwell"
               area for pills) with a thin outer border. min-height makes
               it generously tall on first paint; height:auto + visible
               overflow grow it downward as pills wrap. */
            .select2-container.codeon-tbilisi-picker .select2-selection--multiple {
                min-height: 140px !important;
                height: auto !important;
                max-height: none !important;
                border: 1px solid #d4d7de !important;
                border-radius: 6px !important;
                padding: 10px 10px 6px !important;
                background: #f4f5f7 !important;
                line-height: 1.4 !important;
                overflow: visible !important;
                cursor: text;
            }
            .select2-container.codeon-tbilisi-picker.select2-container--focus .select2-selection--multiple {
                border-color: #2563eb !important;
                box-shadow: 0 0 0 3px rgba(37,99,235,0.14) !important;
            }

            /* The <ul> wrapper. Block-level so the dedicated search row
               below it stacks naturally underneath the pill rows. */
            .select2-container.codeon-tbilisi-picker .select2-selection__rendered {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
                line-height: 1.4 !important;
                white-space: normal !important;
            }

            /* Each picked settlement pill. Floated left so they wrap
               naturally; brand-soft fill so they read as interactive
               but quiet. */
            .select2-container.codeon-tbilisi-picker .select2-selection__choice {
                background: #fff !important;
                border: 1px solid #d4d7de !important;
                color: #0b0f19 !important;
                padding: 4px 10px !important;
                font-size: 13px !important;
                border-radius: 4px !important;
                margin: 0 6px 6px 0 !important;
                line-height: 1.4 !important;
                float: left !important;
                display: inline-block !important;
                vertical-align: middle !important;
            }
            .select2-container.codeon-tbilisi-picker .select2-selection__choice__remove {
                color: #4b5363 !important;
                font-size: 16px !important;
                margin-right: 6px !important;
                font-weight: 700 !important;
                vertical-align: -1px;
            }

            /* THE FIX FOR THE USER COMPLAINT: dedicated search row,
               on its own line below the pills, full-width, white
               background, visible border, larger font. Typed text
               can't get lost in the pill area. */
            .select2-container.codeon-tbilisi-picker .select2-search--inline {
                display: block !important;
                width: 100% !important;
                float: none !important;
                clear: both !important;
                margin: 4px 0 0 !important;
                padding: 0 !important;
            }
            .select2-container.codeon-tbilisi-picker .select2-search--inline .select2-search__field {
                box-sizing: border-box !important;
                width: 100% !important;
                min-height: 38px !important;
                font-size: 14px !important;
                color: #0b0f19 !important;
                background: #fff !important;
                border: 1px solid #d4d7de !important;
                border-radius: 4px !important;
                padding: 8px 12px !important;
                margin: 0 !important;
                line-height: 1.4 !important;
            }
            .select2-container.codeon-tbilisi-picker .select2-search--inline .select2-search__field:focus {
                border-color: #2563eb !important;
                box-shadow: 0 0 0 2px rgba(37,99,235,0.18) !important;
                outline: none !important;
            }
            .select2-container.codeon-tbilisi-picker .select2-search__field::placeholder {
                color: #9aa0ab;
            }

            /* Dropdown of search results — readable line-height. */
            .select2-dropdown.codeon-tbilisi-picker-dropdown {
                border-color: #d4d7de;
                box-shadow: 0 8px 24px rgba(15,23,42,0.12);
                min-width: 480px !important;
            }
            .select2-dropdown.codeon-tbilisi-picker-dropdown .select2-results__option {
                padding: 8px 12px;
                font-size: 13px;
                line-height: 1.4;
            }
            .select2-dropdown.codeon-tbilisi-picker-dropdown .select2-results__option--highlighted {
                background: #2563eb;
                color: #fff;
            }
        </style>
        <table class="form-table"><tbody>
            <tr class="codeon-row codeon-row-raw">
                <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Surrounding areas', 'codeon-core'); ?></label></th>
                <td>
                    <select id="<?php echo esc_attr($id); ?>"
                            name="<?php echo esc_attr($name); ?>[]"
                            multiple
                            class="codeon-tbilisi-settlements"
                            data-rest-search="<?php echo esc_attr(rest_url('codeon-geo/v1/search')); ?>"
                            style="min-width: 560px;">
                        <?php
                        foreach ($current as $rawId) {
                            $sid = (int) $rawId;
                            if ($sid <= 0) {
                                continue;
                            }
                            $s = $repo->settlement($sid);
                            if ($s === null) {
                                continue;
                            }
                            // Dataset's name_ka already appends the
                            // muni in parens for disambiguated entries.
                            printf(
                                '<option value="%d" selected>%s</option>',
                                $sid,
                                esc_html($fmt->label($s))
                            );
                        }
                        ?>
                    </select>
                    <p class="description">
                        <?php esc_html_e('Type to search across all 4,394 Georgian settlements. Picked settlements join Tbilisi as Area options at checkout. Order matters only inside the dropdown — Tbilisi always renders first.', 'codeon-core'); ?>
                    </p>
                </td>
            </tr>
        </tbody></table>
        <?php

        // One-shot enqueue + inline init for this picker. Wrapped in
        // is_admin() so a stray render on the front end (shouldn't
        // happen) is harmless.
        if (!wp_script_is('select2', 'enqueued') && !wp_script_is('select2', 'done')) {
            wp_enqueue_script('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js', ['jquery'], '4.1.0', true);
            wp_enqueue_style('select2', 'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css', [], '4.1.0');
        }
        ?>
        <script>
        (function ($) {
            if (!$ || !$.fn || !$.fn.select2) {
                if (typeof window !== 'undefined') {
                    var ready = function () {
                        if (window.jQuery && window.jQuery.fn && window.jQuery.fn.select2) {
                            init(window.jQuery);
                        } else {
                            setTimeout(ready, 100);
                        }
                    };
                    ready();
                }
                return;
            }
            init($);

            function init($) {
                var $sel = $('#<?php echo esc_js($id); ?>');
                if (!$sel.length || $sel.data('codeon-init')) return;
                $sel.data('codeon-init', true);
                $sel.select2({
                    width: '100%',
                    containerCssClass: 'codeon-tbilisi-picker',
                    dropdownCssClass:  'codeon-tbilisi-picker-dropdown',
                    placeholder: <?php echo wp_json_encode(__('Type to search settlements…', 'codeon-core')); ?>,
                    minimumInputLength: 2,
                    allowClear: false,
                    closeOnSelect: false,
                    ajax: {
                        url: $sel.data('rest-search'),
                        dataType: 'json',
                        delay: 200,
                        data: function (params) { return { q: params.term, limit: 30 }; },
                        processResults: function (data) {
                            return {
                                results: (data || []).map(function (row) {
                                    // The dataset's name_ka already
                                    // appends the muni in parens for
                                    // settlements that need
                                    // disambiguation (ka.wikipedia
                                    // convention), so row.name is
                                    // already self-contained — no
                                    // need to append region/muni
                                    // slugs again.
                                    return { id: row.id, text: row.name };
                                }),
                            };
                        },
                        cache: true,
                    },
                });
            }
        })(window.jQuery);
        </script>
        <?php
    }
}
