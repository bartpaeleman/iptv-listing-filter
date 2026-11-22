<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\ChannelRepository;
use App\Repositories\GroupRepository;

final class ChannelController extends BaseController
{
    public function groups(): void
    {
        $this->requireAuth();
        $this->jsonSuccess((new GroupRepository())->allWithCounts());
    }

    public function updateGroup(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        $isEnabled = isset($input['is_enabled']) ? (int) $input['is_enabled'] : null;
        $customName = array_key_exists('custom_name', $input) ? trim((string) $input['custom_name']) : null;
        $sortOrder = isset($input['sort_order']) ? (int) $input['sort_order'] : null;

        (new GroupRepository())->updateGroup($id, $isEnabled, $customName, $sortOrder);
        $this->jsonSuccess();
    }

    public function bulkGroups(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $enable = (bool) ((int) ($input['is_enabled'] ?? 1));
        $ids = is_array($input['ids'] ?? null) ? array_map('intval', $input['ids']) : [];
        (new GroupRepository())->bulkSetEnabled($enable, $ids);
        $this->jsonSuccess();
    }

    public function channels(): void
    {
        $this->requireAuth();
        $groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int) $_GET['group_id'] : null;
        $search = trim((string) ($_GET['search'] ?? ''));
        $epgStatus = (string) ($_GET['epg_status'] ?? '');
        $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
        $limit = min(200, max(10, (int) ($_GET['limit'] ?? 100)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $res = (new ChannelRepository())->filter($groupId, $search, $epgStatus, $activeOnly, $limit, $offset);
        $this->jsonSuccess($res);
    }

    public function updateChannel(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        (new ChannelRepository())->updateChannel($id, $input);
        $this->jsonSuccess();
    }

    public function bulkChannels(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $enable = (bool) ((int) ($input['is_enabled'] ?? 1));
        $ids = is_array($input['ids'] ?? null) ? array_map('intval', $input['ids']) : [];
        (new ChannelRepository())->bulkSetEnabled($enable, $ids);
        $this->jsonSuccess();
    }
}
