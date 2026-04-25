<?php
/**
 * Diagnostics tab — read-only dataset health.
 *
 * Shows the bundle's build timestamp + counts so a merchant can verify
 * the locations data is loaded correctly. Includes a small "test
 * cascade" widget where the merchant can simulate Region → Municipality
 * → Settlement selection to confirm the REST endpoints are responding.
 *
 * @package CodeOn\Core\Locations\Settings
 */

declare(strict_types=1);

namespace CodeOn\Core\Locations\Settings;

use CodeOn\Core\Locations\Data\Repository;
use CodeOn\Framework\Admin\Tab;

final class DiagnosticsTab extends Tab
{
    public function slug(): string
    {
        return 'diagnostics';
    }

    public function label(): string
    {
        return __('Diagnostics', 'codeon-core');
    }

    public function render(string $nonceAction): void
    {
        try {
            $repo = Repository::instance();
            $meta = $repo->meta();
        } catch (\RuntimeException $e) {
            echo '<div class="notice notice-error inline"><p>' .
                esc_html($e->getMessage()) . '</p></div>';
            return;
        }

        $sample = $repo->regions(includeOccupied: false);

        ?>
        <div class="codeon-diagnostics">
            <h2 class="screen-reader-text"><?php esc_html_e('Diagnostics', 'codeon-core'); ?></h2>

            <table class="widefat striped" style="max-width:600px;">
                <tbody>
                    <tr>
                        <th><?php esc_html_e('Dataset version', 'codeon-core'); ?></th>
                        <td><code><?php echo esc_html($meta['version']); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Built at', 'codeon-core'); ?></th>
                        <td><?php echo esc_html($meta['built_at']); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Source', 'codeon-core'); ?></th>
                        <td><code><?php echo esc_html($meta['source']); ?></code></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Regions', 'codeon-core'); ?></th>
                        <td><?php echo esc_html(number_format_i18n($meta['region_count'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Municipalities', 'codeon-core'); ?></th>
                        <td><?php echo esc_html(number_format_i18n($meta['municipality_count'])); ?></td>
                    </tr>
                    <tr>
                        <th><?php esc_html_e('Settlements (cities + towns + villages)', 'codeon-core'); ?></th>
                        <td><?php echo esc_html(number_format_i18n($meta['settlement_count'])); ?></td>
                    </tr>
                </tbody>
            </table>

            <h3 style="margin-top:24px;"><?php esc_html_e('REST endpoints', 'codeon-core'); ?></h3>
            <ul>
                <?php
                $base = rest_url('codeon-geo/v1/');
                foreach ([
                    'regions',
                    'regions/kakheti/municipalities',
                    'municipalities/telavi/settlements',
                    'search?q=კონდ&limit=5',
                ] as $path):
                    $url = $base . $path;
                    ?>
                    <li><a href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener"><code><?php echo esc_html($url); ?></code></a></li>
                <?php endforeach; ?>
            </ul>

            <h3 style="margin-top:24px;"><?php esc_html_e('Test cascade', 'codeon-core'); ?></h3>
            <p class="description"><?php esc_html_e('Pick a region to see its municipalities. Confirms the dataset is loaded and the REST endpoints are responding.', 'codeon-core'); ?></p>

            <p>
                <select id="codeon-diag-region" style="min-width:240px;">
                    <option value=""><?php esc_html_e('— region —', 'codeon-core'); ?></option>
                    <?php foreach ($sample as $r): ?>
                        <option value="<?php echo esc_attr($r['id']); ?>">
                            <?php echo esc_html($r['name_ka']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <select id="codeon-diag-mun" style="min-width:240px;" disabled>
                    <option value=""><?php esc_html_e('— municipality —', 'codeon-core'); ?></option>
                </select>

                <select id="codeon-diag-set" style="min-width:240px;" disabled>
                    <option value=""><?php esc_html_e('— settlement —', 'codeon-core'); ?></option>
                </select>
            </p>
        </div>

        <script>
        (function () {
            const base = <?php echo wp_json_encode(rest_url('codeon-geo/v1/')); ?>;
            const $r = document.getElementById('codeon-diag-region');
            const $m = document.getElementById('codeon-diag-mun');
            const $s = document.getElementById('codeon-diag-set');
            const labels = {
                mun: <?php echo wp_json_encode(__('— municipality —', 'codeon-core')); ?>,
                set: <?php echo wp_json_encode(__('— settlement —', 'codeon-core')); ?>,
            };
            function reset(el, label) {
                while (el.firstChild) el.removeChild(el.firstChild);
                const opt = document.createElement('option');
                opt.value = '';
                opt.textContent = label;
                el.appendChild(opt);
                el.disabled = true;
            }
            function fill(el, items) {
                items.forEach(function (it) {
                    const opt = document.createElement('option');
                    opt.value = it.id;
                    opt.textContent = it.name_ka || it.name;
                    el.appendChild(opt);
                });
                el.disabled = false;
            }
            $r.addEventListener('change', async function () {
                reset($m, labels.mun);
                reset($s, labels.set);
                if (!$r.value) return;
                const resp = await fetch(base + 'regions/' + $r.value + '/municipalities');
                fill($m, await resp.json());
            });
            $m.addEventListener('change', async function () {
                reset($s, labels.set);
                if (!$m.value) return;
                const resp = await fetch(base + 'municipalities/' + $m.value + '/settlements');
                fill($s, await resp.json());
            });
        })();
        </script>
        <?php
    }
}
