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
        (new UiCacheService())->invalidateAll();
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
        (new UiCacheService())->invalidateAll();
        $this->jsonSuccess();
    }

    public function channels(): void
    {
        $this->requireAuth();
        $groupId = isset($_GET['group_id']) && $_GET['group_id'] !== '' ? (int) $_GET['group_id'] : null;
        $search = trim((string) ($_GET['search'] ?? ''));
        $epgStatus = (string) ($_GET['has_epg'] ?? $_GET['epg_status'] ?? '');
        $activeOnly = isset($_GET['active_only']) && $_GET['active_only'] === '1';
        $countryTagId = trim((string) ($_GET['country_tag_id'] ?? ''));
        $status = (string) ($_GET['status'] ?? 'all');
        $limit = min(200, max(10, (int) ($_GET['limit'] ?? 100)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));

        $isCacheable = $groupId !== null && $search === '' && $epgStatus === '' && !$activeOnly && $countryTagId === '' && $status === 'all' && $offset === 0;
        $cache = new UiCacheService();
        if ($isCacheable) {
            $cached = $cache->getGroupChannels($groupId);
            if ($cached !== null) {
                $this->jsonSuccess($cached);
                return;
            }
        }

        $res = (new ChannelRepository())->filter($groupId, $search, $epgStatus, $activeOnly, $limit, $offset, $countryTagId, $status);
        $emojiService = new EmojiTagService();
        $emojiService->seedDefaultsIfEmpty();

        if (isset($res['items']) && is_array($res['items'])) {
            foreach ($res['items'] as &$ch) {
                $rawCh = (string) (($ch['custom_name'] ?? '') !== '' ? $ch['custom_name'] : ($ch['tvg_name'] ?? ''));
                $ch['display_name'] = $emojiService->parseName($rawCh, false)['display_name'];
                if (isset($ch['group_name']) && (string) $ch['group_name'] !== '') {
                    $ch['group_display_name'] = $emojiService->parseName((string) $ch['group_name'], true)['display_name'];
                }
            }
            unset($ch);
        }

        if ($isCacheable) {
            $cache->saveGroupChannels($groupId, $res);
        }

        $this->jsonSuccess($res);
    }

    public function updateChannel(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        (new ChannelRepository())->updateChannel($id, $input);
        (new UiCacheService())->invalidateAll();
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
        (new UiCacheService())->invalidateAll();
        $this->jsonSuccess();
    }

    public function countryTags(): void
    {
        $this->requireAuth();
        $this->jsonSuccess((new \App\Repositories\CountryTagRepository())->allOrdered());
    }

    public function saveCountryTag(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $pattern = trim((string) ($input['tag_pattern'] ?? ''));
        $code = strtoupper(trim((string) ($input['country_code'] ?? '')));
        $name = trim((string) ($input['country_name'] ?? ''));
        $flag = trim((string) ($input['flag_emoji'] ?? ''));
        if ($flag === '' && strlen($code) === 2) {
            $flag = \App\Services\CountryTagService::isoToFlag($code);
        }
        $id = (new \App\Repositories\CountryTagRepository())->saveTag($pattern, $code, $name, $flag);
        $this->jsonSuccess(['id' => $id]);
    }

    public function deleteCountryTag(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        (new \App\Repositories\CountryTagRepository())->deleteById((int) ($input['id'] ?? 0));
        $this->jsonSuccess();
    }

    public function scanTags(): void
    {
        $this->requireAuth();
        $this->jsonSuccess((new \App\Services\CountryTagService())->scanUnmappedTags());
    }

    public function applyCountryTags(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $groupByCountry = (bool) ($input['group_by_country'] ?? false);
        $res = (new \App\Services\CountryTagService())->applyTagsToExistingChannels($groupByCountry);
        $this->jsonSuccess($res);
    }
}
