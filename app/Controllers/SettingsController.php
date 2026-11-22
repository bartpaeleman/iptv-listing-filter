<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\SettingsRepository;

final class SettingsController extends BaseController
{
    public function get(): void
    {
        $this->requireAuth();
        $settings = new SettingsRepository();
        $m3u = (string) $settings->get('m3u_source_url', '');
        $epg = (string) $settings->get('epg_source_url', '');

        $this->jsonSuccess([
            'm3u_source_url_masked' => $m3u !== '' ? substr($m3u, 0, 28) . '••••••' : '',
            'epg_source_url_masked' => $epg !== '' ? substr($epg, 0, 36) . (strlen($epg) > 36 ? '••••' : '') : '',
            'sync_interval_hours' => (int) $settings->get('sync_interval_hours', '72'),
            'sync_preferred_hour' => (int) $settings->get('sync_preferred_hour', '3'),
            'enforce_lan_ip' => $settings->get('enforce_lan_ip', '1') === '1',
            'output_token' => $settings->get('output_token', ''),
            'app_root' => dirname(__DIR__, 2),
        ]);
    }

    public function save(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $settings = new SettingsRepository();
        if (!empty($input['m3u_source_url'])) {
            $settings->set('m3u_source_url', trim((string) $input['m3u_source_url']), true);
        }
        if (!empty($input['epg_source_url'])) {
            $settings->set('epg_source_url', trim((string) $input['epg_source_url']), true);
        }
        if (isset($input['sync_interval_hours'])) {
            $settings->set('sync_interval_hours', (string) max(1, (int) $input['sync_interval_hours']), false);
        }
        if (isset($input['sync_preferred_hour'])) {
            $hour = max(0, min(23, (int) $input['sync_preferred_hour']));
            $settings->set('sync_preferred_hour', (string) $hour, false);
        }
        if (isset($input['enforce_lan_ip'])) {
            $settings->set('enforce_lan_ip', $input['enforce_lan_ip'] ? '1' : '0', false);
        }

        $this->jsonSuccess();
    }

    public function regenerateToken(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $newToken = bin2hex(random_bytes(16));
        (new SettingsRepository())->set('output_token', $newToken, false);
        $this->jsonSuccess(['output_token' => $newToken]);
    }
}
