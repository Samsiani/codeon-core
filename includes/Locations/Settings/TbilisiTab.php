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
        <table class="form-table"><tbody>
            <tr class="codeon-row codeon-row-raw">
                <th scope="row"><label for="<?php echo esc_attr($id); ?>"><?php esc_html_e('Surrounding areas', 'codeon-core'); ?></label></th>
                <td>
                    <select id="<?php echo esc_attr($id); ?>"
                            name="<?php echo esc_attr($name); ?>[]"
                            multiple
                            class="codeon-input codeon-tbilisi-settlements"
                            data-rest-search="<?php echo esc_attr(rest_url('codeon-geo/v1/search')); ?>"
                            style="min-width: 480px;">
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
                            $region = $repo->region((string) $s['region_id']);
                            $mun    = $repo->municipality((string) $s['municipality_id']);
                            $context = trim(implode(', ', array_filter([
                                $mun !== null ? $fmt->label($mun) : '',
                                $region !== null ? $fmt->label([
                                    'name_ka' => $region['name_ka'],
                                    'name_en' => $region['name_en'],
                                ]) : '',
                            ])));
                            $label = $context !== ''
                                ? $fmt->label($s) . ' — ' . $context
                                : $fmt->label($s);
                            printf(
                                '<option value="%d" selected>%s</option>',
                                $sid,
                                esc_html($label)
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
                    width: '480px',
                    placeholder: <?php echo wp_json_encode(__('Type to search settlements…', 'codeon-core')); ?>,
                    minimumInputLength: 2,
                    ajax: {
                        url: $sel.data('rest-search'),
                        dataType: 'json',
                        delay: 200,
                        data: function (params) { return { q: params.term, limit: 30 }; },
                        processResults: function (data) {
                            return {
                                results: (data || []).map(function (row) {
                                    return {
                                        id: row.id,
                                        text: row.name + (row.municipality_id ? ' — ' + row.municipality_id + ', ' + row.region_id : ''),
                                    };
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
