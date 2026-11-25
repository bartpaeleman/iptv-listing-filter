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

        $epgSources = [];
        try {
            $epgSources = (new \App\Repositories\EpgSourceRepository())->allDecrypted(false);
        } catch (\Throwable) {
            $epgSources = [];
        }
        $maskedSources = array_map(static function (array $s): array {
            $u = (string) ($s['url'] ?? '');
            $s['url_masked'] = $u !== '' ? substr($u, 0, 36) . (strlen($u) > 36 ? '••••' : '') : '';
            unset($s['url']);
            return $s;
        }, $epgSources);

        $xtreamUser = (string) $settings->get('xtream_username', '');
        $xtreamPass = (string) $settings->get('xtream_password', '');
        $xtreamCustomEpg = (string) $settings->get('xtream_custom_epg_url', '');
        $xtreamOutUser = (string) $settings->get('xtream_out_username', '');
        $xtreamOutPass = (string) $settings->get('xtream_out_password', '');

        $this->jsonSuccess([
            'source_mode' => $settings->get('source_mode', 'xtream'),
            'xtream_host' => $settings->get('xtream_host', ''),
            'xtream_username_masked' => $xtreamUser !== '' ? substr($xtreamUser, 0, 3) . '••••' : '',
            'xtream_password_set' => $xtreamPass !== '',
            'xtream_output_params' => $settings->get('xtream_output_params', 'type=m3u_plus&output=ts'),
            'xtream_epg_mode' => $settings->get('xtream_epg_mode', 'xtream_xmltv'),
            'xtream_custom_epg_url_masked' => $xtreamCustomEpg !== '' ? substr($xtreamCustomEpg, 0, 36) . (strlen($xtreamCustomEpg) > 36 ? '••••' : '') : '',
            'xtream_out_enabled' => $settings->get('xtream_out_enabled', '0') === '1',
            'xtream_out_username_masked' => $xtreamOutUser !== '' ? substr($xtreamOutUser, 0, 3) . '••••' : '',
            'xtream_out_password_set' => $xtreamOutPass !== '',
            'm3u_source_url_masked' => $m3u !== '' ? substr($m3u, 0, 28) . '••••••' : '',
            'epg_source_url_masked' => $epg !== '' ? substr($epg, 0, 36) . (strlen($epg) > 36 ? '••••' : '') : '',
            'sync_interval_hours' => (int) $settings->get('sync_interval_hours', '72'),
            'sync_preferred_hour' => (int) $settings->get('sync_preferred_hour', '3'),
            'enforce_lan_ip' => $settings->get('enforce_lan_ip', '1') === '1',
            'output_token' => $settings->get('output_token', ''),
            'app_root' => dirname(__DIR__, 2),
            'epg_sources' => $maskedSources,
        ]);
    }

    public function save(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $settings = new SettingsRepository();
        $sourceMode = in_array($input['source_mode'] ?? '', ['xtream', 'separate_urls'], true)
            ? (string) $input['source_mode']
            : (string) $settings->get('source_mode', 'xtream');
        $settings->set('source_mode', $sourceMode, false);

        if ($sourceMode === 'xtream') {
            $host = trim((string) ($input['xtream_host'] ?? $settings->get('xtream_host', '')));
            $username = trim((string) ($input['xtream_username'] ?? ''));
            if ($username === '') {
                $username = (string) $settings->get('xtream_username', '');
            }
            $password = (string) ($input['xtream_password'] ?? '');
            if ($password === '') {
                $password = (string) $settings->get('xtream_password', '');
            }
            $outputParams = trim((string) ($input['xtream_output_params'] ?? $settings->get('xtream_output_params', 'type=m3u_plus&output=ts')));
            if ($outputParams === '') {
                $outputParams = 'type=m3u_plus&output=ts';
            }

            if ($host === '' || !filter_var($host, FILTER_VALIDATE_URL) || $username === '' || $password === '') {
                $this->jsonError('Vul een geldige Server URL, Gebruikersnaam en Wachtwoord in voor Xtream Codes API.', 400);
            }

            $settings->set('xtream_host', rtrim($host, '/'), false);
            $settings->set('xtream_username', $username, true);
            $settings->set('xtream_password', $password, true);
            $settings->set('xtream_output_params', $outputParams, false);

            $m3uUrl = \App\Services\XtreamUrlBuilder::buildM3uPlusUrl($host, $username, $password, $outputParams);
            $epgMode = in_array($input['xtream_epg_mode'] ?? '', ['xtream_xmltv', 'custom_url'], true)
                ? (string) $input['xtream_epg_mode']
                : (string) $settings->get('xtream_epg_mode', 'xtream_xmltv');
            $settings->set('xtream_epg_mode', $epgMode, false);

            if ($epgMode === 'custom_url') {
                $customEpg = trim((string) ($input['xtream_custom_epg_url'] ?? ''));
                if ($customEpg === '') {
                    $customEpg = (string) $settings->get('xtream_custom_epg_url', '');
                }
                if ($customEpg === '' || !filter_var($customEpg, FILTER_VALIDATE_URL)) {
                    $this->jsonError('Vul een geldige aangepaste EPG URL in.', 400);
                }
                $settings->set('xtream_custom_epg_url', $customEpg, true);
                $epgUrl = $customEpg;
            } else {
                $epgUrl = \App\Services\XtreamUrlBuilder::buildXmltvUrl($host, $username, $password);
            }

            $settings->set('m3u_source_url', $m3uUrl, true);
            $settings->set('epg_source_url', $epgUrl, true);
        } else {
            if (!empty($input['m3u_source_url'])) {
                $settings->set('m3u_source_url', trim((string) $input['m3u_source_url']), true);
            }
            if (!empty($input['epg_source_url'])) {
                $settings->set('epg_source_url', trim((string) $input['epg_source_url']), true);
            }
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
        if (isset($input['xtream_out_enabled'])) {
            $settings->set('xtream_out_enabled', $input['xtream_out_enabled'] ? '1' : '0', false);
        }
        if (!empty($input['xtream_out_username'])) {
            $settings->set('xtream_out_username', trim((string) $input['xtream_out_username']), true);
        }
        if (!empty($input['xtream_out_password'])) {
            $settings->set('xtream_out_password', (string) $input['xtream_out_password'], true);
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

    public function crontabStatus(): void
    {
        $this->requireAuth();
        $this->jsonSuccess((new \App\Services\CrontabService())->getStatus());
    }

    public function crontabSave(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $schedule = trim((string) ($input['schedule'] ?? ''));
        $phpPath = trim((string) ($input['php_path'] ?? ''));

        try {
            $status = (new \App\Services\CrontabService())->saveJob($schedule, $phpPath);
            $this->jsonSuccess($status);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function crontabDelete(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        try {
            $status = (new \App\Services\CrontabService())->deleteJob();
            $this->jsonSuccess($status);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function saveEpgSource(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $name = trim((string) ($input['name'] ?? 'EPG Bron'));
        $url = trim((string) ($input['url'] ?? ''));
        $prio = (int) ($input['priority'] ?? 10);
        if ($url === '') {
            $this->jsonError('URL is verplicht.', 400);
        }
        $id = (new \App\Repositories\EpgSourceRepository())->addOrUpdate($name, $url, $prio);
        $this->jsonSuccess(['id' => $id]);
    }

    public function toggleEpgSource(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        (new \App\Repositories\EpgSourceRepository())->setEnabled((int) ($input['id'] ?? 0), (bool) ($input['is_enabled'] ?? true));
        $this->jsonSuccess();
    }

    public function deleteEpgSource(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        (new \App\Repositories\EpgSourceRepository())->deleteById((int) ($input['id'] ?? 0));
        $this->jsonSuccess();
    }

    public function testEpgSource(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        $repo = new \App\Repositories\EpgSourceRepository();
        $row = null;
        foreach ($repo->allDecrypted(false) as $s) {
            if ((int) $s['id'] === $id) {
                $row = $s;
                break;
            }
        }
        if (!$row || empty($row['url'])) {
            $this->jsonError('EPG bron niet gevonden.', 404);
        }

        try {
            $stats = (new \App\Services\EpgParserService())->sync((string) $row['url']);
            $repo->updateStatus($id, "OK ({$stats['epg_channels']} zenders)");
            $this->jsonSuccess($stats);
        } catch (\Throwable $e) {
            $repo->updateStatus($id, 'Fout: ' . $e->getMessage());
            $this->jsonError($e->getMessage(), 500);
        }
    }

    public function clearDbTables(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $scope = (string) ($input['scope'] ?? '');
        if (!in_array($scope, ['m3u', 'epg'], true)) {
            $this->jsonError('Ongeldige scope. Gebruik "m3u" of "epg".', 400);
        }

        $service = new \App\Services\DatabaseCleanupService();
        if ($scope === 'm3u') {
            $counts = $service->clearM3uData();
            $message = sprintf(
                'M3U data gewist: %d kanalen en %d groepen verwijderd.',
                $counts['channels'],
                $counts['m3u_groups']
            );
        } else {
            $counts = $service->clearEpgData();
            $message = sprintf(
                'EPG data gewist: %d programma\'s en %d EPG-zenders verwijderd.',
                $counts['epg_programmes'],
                $counts['epg_channels']
            );
        }

        $this->jsonSuccess([
            'scope' => $scope,
            'deleted' => $counts,
            'message' => $message,
        ]);
    }
}
