<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\EpgRepository;
use App\Services\EpgMatcherService;
use App\Services\ViewCacheService;

final class EpgController extends BaseController
{
    public function search(): void
    {
        $this->requireAuth();
        $q = trim((string) ($_GET['q'] ?? ''));
        $this->jsonSuccess((new EpgRepository())->searchChannels($q));
    }

    public function autoMatch(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $groupId = max(0, (int) ($input['group_id'] ?? 0));
        $epgStatus = trim((string) ($input['epg_status'] ?? 'all'));
        if (!in_array($epgStatus, ['all', 'coupled', 'uncoupled', 'mapped', 'unmapped', 'yes', 'no', ''], true)) {
            $epgStatus = 'all';
        }
        $activeOnly = !isset($input['active_only']) || (bool) ((int) $input['active_only']);
        $liveOnly = !isset($input['live_only']) || (bool) ((int) $input['live_only']);
        $channelIds = is_array($input['channel_ids'] ?? null)
            ? array_values(array_filter(array_map('intval', $input['channel_ids']), static fn(int $id): bool => $id > 0))
            : [];

        $result = (new EpgMatcherService())->autoMatchFiltered(
            $groupId,
            $epgStatus,
            $activeOnly,
            $liveOnly,
            $channelIds
        );

        if ($result['matched'] > 0) {
            $viewCache = new ViewCacheService();
            if ($groupId > 0) {
                $viewCache->invalidateGroupChannels($groupId);
            } else {
                $viewCache->invalidateAll();
            }
            $viewCache->invalidateDashboardStats();
        }

        $this->jsonSuccess($result);
    }
}
