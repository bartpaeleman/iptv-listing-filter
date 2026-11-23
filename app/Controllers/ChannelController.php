<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\ChannelRepository;
use App\Repositories\GroupRepository;
use App\Services\EmojiTagService;
use App\Services\ViewCacheService;

final class ChannelController extends BaseController
{
    public function groups(): void
    {
        $this->requireAuth();
        $contentTypes = $this->parseContentTypes((string) ($_GET['content_types'] ?? ''));
        $scope = (string) ($_GET['scope'] ?? 'active');
        $cacheOnly = isset($_GET['cache_only']) && $_GET['cache_only'] === '1';
        $forceRefresh = isset($_GET['refresh']) && $_GET['refresh'] === '1';
        $viewCache = new ViewCacheService();

        if ($scope === 'active' && !$forceRefresh) {
            $cached = $viewCache->getActiveGroups('import', $contentTypes);
            if ($cached !== null) {
                $this->jsonSuccess($cached);
                return;
            }
        }

        if ($cacheOnly) {
            $this->jsonSuccess([]);
            return;
        }

        $emojiService = new EmojiTagService();
        $emojiService->seedDefaultsIfEmpty();

        $repo = new GroupRepository();
        $groups = $scope === 'all'
            ? $repo->allWithCounts('import', $contentTypes)
            : $repo->activeWithCounts('import', $contentTypes);

        foreach ($groups as &$g) {
            $rawName = (string) (($g['custom_name'] ?? '') !== '' ? $g['custom_name'] : ($g['original_name'] ?? ''));
            $g['display_name'] = $emojiService->parseName($rawName, true)['display_name'];
        }
        unset($g);

        if ($scope === 'active') {
            $viewCache->setActiveGroups('import', $contentTypes, $groups);
        }

        $this->jsonSuccess($groups);
    }

    public function searchGroups(): void
    {
        $this->requireAuth();
        $query = trim((string) ($_GET['q'] ?? ''));
        $contentTypes = $this->parseContentTypes((string) ($_GET['content_types'] ?? ''));
        $status = in_array($_GET['status'] ?? 'disabled', ['disabled', 'enabled', 'all'], true)
            ? (string) $_GET['status']
            : 'disabled';
        $limit = min(200, max(1, (int) ($_GET['limit'] ?? 100)));
        $offset = max(0, (int) ($_GET['offset'] ?? 0));
        $rawCats = trim((string) ($_GET['categories'] ?? ''));
        $categories = $rawCats !== '' ? array_values(array_intersect(
            array_map('trim', explode(',', $rawCats)),
            \App\Services\GroupCategoryService::CATEGORIES
        )) : [];

        $result = (new GroupRepository())->searchGroups($query, $contentTypes, $status, $limit, $offset, $categories);
        $emojiService = new EmojiTagService();
        $emojiService->seedDefaultsIfEmpty();

        foreach ($result['items'] as &$g) {
            $rawName = (string) (($g['custom_name'] ?? '') !== '' ? $g['custom_name'] : ($g['original_name'] ?? ''));
            $parsed = $emojiService->parseName($rawName, true);
            $g['display_name'] = $parsed['display_name'];
            $g['emoji_prefix'] = $parsed['emoji_prefix'] ?? '';
        }
        unset($g);

        $this->jsonSuccess([
            'items' => $result['items'],
            'has_more' => $result['has_more'],
            'next_offset' => $result['next_offset'],
        ]);
    }

    public function setGroupSort(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $mode = in_array($input['mode'] ?? 'import', ['import', 'asc', 'desc'], true)
            ? (string) $input['mode']
            : 'import';

        (new \App\Repositories\SettingsRepository())->set('group_sort_mode', $mode);
        $this->jsonSuccess(['group_sort_mode' => $mode]);
    }

    public function updateGroupCategory(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        $category = trim((string) ($input['category'] ?? ''));
        $lock = array_key_exists('lock', $input) ? (bool) $input['lock'] : true;
        if (!in_array($category, \App\Services\GroupCategoryService::CATEGORIES, true)) {
            $this->jsonError('Ongeldige categorie.', 400);
            return;
        }
        if ($id > 0) {
            (new GroupRepository())->updateCategory($id, $category, $lock);
            (new ViewCacheService())->invalidateAll();
        }
        $this->jsonSuccess(['id' => $id, 'category' => $category, 'category_locked' => $lock ? 1 : 0]);
    }

    public function groupCategoryRules(): void
    {
        $this->requireAuth();
        $pdo = \App\Core\Database::getInstance();
        $rows = $pdo->query('SELECT id, pattern, category, is_active FROM group_category_rules ORDER BY category ASC, pattern ASC')->fetchAll();
        $this->jsonSuccess($rows);
    }

    public function saveGroupCategoryRule(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $pattern = trim((string) ($input['pattern'] ?? ''));
        $category = trim((string) ($input['category'] ?? 'Live TV'));
        if ($pattern === '' || !in_array($category, \App\Services\GroupCategoryService::CATEGORIES, true)) {
            $this->jsonError('Ongeldig patroon of categorie.', 422);
            return;
        }
        $pdo = \App\Core\Database::getInstance();
        $stmt = $pdo->prepare(
            'INSERT INTO group_category_rules (pattern, category, is_active) VALUES (:p, :c, 1)
             ON DUPLICATE KEY UPDATE category = VALUES(category), is_active = 1'
        );
        $stmt->execute([':p' => $pattern, ':c' => $category]);
        (new \App\Services\GroupCategoryService())->classifyUnlockedGroups($pdo);
        (new ViewCacheService())->invalidateAll();
        $this->jsonSuccess();
    }

    public function deleteGroupCategoryRule(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        if ($id > 0) {
            $pdo = \App\Core\Database::getInstance();
            $stmt = $pdo->prepare('DELETE FROM group_category_rules WHERE id = :id');
            $stmt->execute([':id' => $id]);
            (new \App\Services\GroupCategoryService())->classifyUnlockedGroups($pdo);
            (new ViewCacheService())->invalidateAll();
        }
        $this->jsonSuccess();
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
        $viewCache = new ViewCacheService();
        $viewCache->invalidateGroupsCache();
        $viewCache->invalidateGroupChannels($id);
        $viewCache->invalidateDashboardStats();
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
        $viewCache = new ViewCacheService();
        $viewCache->invalidateGroupsCache();
        $viewCache->invalidateDashboardStats();
        if ($ids !== []) {
            foreach ($ids as $gid) {
                $viewCache->invalidateGroupChannels((int) $gid);
            }
        } else {
            $viewCache->invalidateAll();
        }
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
        $sortBy = in_array(
            $_GET['sort_by'] ?? 'number',
            ['number', 'number_desc', 'name_asc', 'name_desc', 'import', 'epg_status_asc', 'epg_status_desc', 'epg_name_asc', 'epg_name_desc'],
            true
        ) ? (string) $_GET['sort_by'] : 'number';
        $contentTypes = $this->parseContentTypes((string) ($_GET['content_types'] ?? ''));
        $liveOnly = isset($_GET['live_only']) && $_GET['live_only'] === '1';

        $isDefaultGroupView = ($groupId !== null && $groupId > 0 && $search === '' && $epgStatus === '' && !$activeOnly && $countryTagId === '' && $status === 'all' && $sortBy === 'number' && $offset === 0 && $limit >= 150 && !$liveOnly);
        $viewCache = new ViewCacheService();
        if ($isDefaultGroupView) {
            $cached = $viewCache->getGroupChannels($groupId);
            if ($cached !== null) {
                $this->jsonSuccess($cached);
                return;
            }
        }

        $channelRepo = new ChannelRepository();
        if ($groupId !== null && $groupId > 0) {
            $channelRepo->normalizeActiveGroupNumbers($groupId);
        }

        $res = $channelRepo->filter($groupId, $search, $epgStatus, $activeOnly, $limit, $offset, $countryTagId, $status, $sortBy, $contentTypes, $liveOnly);
        $emojiService = new EmojiTagService();
        $emojiService->seedDefaultsIfEmpty();

        if (isset($res['items']) && is_array($res['items'])) {
            foreach ($res['items'] as &$ch) {
                $rawCh = (string) (($ch['custom_name'] ?? '') !== '' ? $ch['custom_name'] : ($ch['tvg_name'] ?? ''));
                $parsed = $emojiService->parseName($rawCh, false);
                $ch['display_name'] = $parsed['display_name'];
                $ch['clean_name'] = $parsed['clean_name'];
                $ch['emoji_prefix'] = $parsed['country_emoji'] ?? '';
                if (isset($ch['group_name']) && (string) $ch['group_name'] !== '') {
                    $ch['group_display_name'] = $emojiService->parseName((string) $ch['group_name'], true)['display_name'];
                }
            }
            unset($ch);
        }

        if ($isDefaultGroupView) {
            $viewCache->setGroupChannels($groupId, $res);
        }

        $this->jsonSuccess($res);
    }

    public function updateChannel(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $id = (int) ($input['id'] ?? 0);
        $repo = new ChannelRepository();

        if (array_key_exists('channel_number', $input) && $input['channel_number'] !== '' && $input['channel_number'] !== null) {
            $reordered = $repo->reorderChannelInGroup($id, (int) $input['channel_number']);
            $emojiService = new EmojiTagService();
            foreach ($reordered as &$ch) {
                $rawCh = (string) (($ch['custom_name'] ?? '') !== '' ? $ch['custom_name'] : ($ch['tvg_name'] ?? ''));
                $parsed = $emojiService->parseName($rawCh, false);
                $ch['display_name'] = $parsed['display_name'];
                $ch['clean_name'] = $parsed['clean_name'];
                $ch['emoji_prefix'] = $parsed['country_emoji'] ?? '';
                if (isset($ch['group_name']) && (string) $ch['group_name'] !== '') {
                    $ch['group_display_name'] = $emojiService->parseName((string) $ch['group_name'], true)['display_name'];
                }
            }
            unset($ch);
            $viewCache = new ViewCacheService();
            $viewCache->invalidateAll();
            $this->jsonSuccess(['items' => $reordered]);
            return;
        }

        $repo->updateChannel($id, $input);
        $chRow = $repo->findById($id);
        $viewCache = new ViewCacheService();
        if ($chRow !== null && !empty($chRow['group_id'])) {
            $gid = (int) $chRow['group_id'];
            if (array_key_exists('is_enabled', $input)) {
                $repo->normalizeActiveGroupNumbers($gid);
            }
            $viewCache->invalidateGroupChannels($gid);
        }
        $viewCache->invalidateGroupsCache();
        $viewCache->invalidateDashboardStats();
        $this->jsonSuccess();
    }

    public function bulkChannels(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $enable = (bool) ((int) ($input['is_enabled'] ?? 1));
        $ids = is_array($input['ids'] ?? null) ? array_map('intval', $input['ids']) : [];
        $groupId = isset($input['group_id']) && $input['group_id'] !== '' && $input['group_id'] !== null ? (int) $input['group_id'] : null;
        $search = trim((string) ($input['search'] ?? ''));

        $repo = new ChannelRepository();
        $repo->bulkSetEnabled($enable, $ids, $groupId, $search);
        $viewCache = new ViewCacheService();
        if ($groupId !== null && $groupId > 0) {
            $repo->normalizeActiveGroupNumbers($groupId);
            $viewCache->invalidateGroupChannels($groupId);
        } else {
            $viewCache->invalidateAll();
        }
        $viewCache->invalidateGroupsCache();
        $viewCache->invalidateDashboardStats();
        $this->jsonSuccess();
    }

    public function reorderGroupChannels(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $groupId = (int) ($input['group_id'] ?? 0);
        $sortBy = in_array($input['sort_by'] ?? 'number', ['number', 'number_desc', 'name_asc', 'name_desc'], true)
            ? (string) $input['sort_by']
            : 'number';

        if ($groupId > 0) {
            (new ChannelRepository())->reorderGroupBySort($groupId, $sortBy);
            (new ViewCacheService())->invalidateGroupChannels($groupId);
        }
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

    public function groupChannelTools(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $groupId = (int) ($input['group_id'] ?? 0);
        $mode = (string) ($input['mode'] ?? '');
        if ($groupId <= 0) {
            $this->jsonError('Ongeldige groep.', 400);
        }

        $repo = new ChannelRepository();
        $affected = 0;

        if ($mode === 'quality') {
            $quality = strtoupper(trim((string) ($input['quality'] ?? '')));
            $allowedQualities = ['SD', 'HD', 'FHD', 'UHD', '4K', 'HEVC', 'RAW'];
            if (!in_array($quality, $allowedQualities, true)) {
                $this->jsonError('Ongeldige kwaliteit.', 400);
            }
            $enabled = ((int) ($input['enabled'] ?? 0)) === 1 ? 1 : 0;
            $channels = $repo->filter($groupId, '', '', false, 5000, 0, '', 'all', 'number')['items'];
            $matchingIds = [];
            foreach ($channels as $ch) {
                $effectiveName = ($ch['custom_name'] !== null && $ch['custom_name'] !== '')
                    ? (string) $ch['custom_name']
                    : (string) ($ch['tvg_name'] ?? '');
                if (\App\Services\ChannelNameCleanerService::matchesQuality($effectiveName, $quality)) {
                    $matchingIds[] = (int) $ch['id'];
                }
            }
            $affected = $repo->bulkSetEnabledByIds($groupId, $matchingIds, $enabled);
        } elseif ($mode === 'disable_comments') {
            $channels = $repo->filter($groupId, '', '', false, 5000, 0, '', 'all', 'number')['items'];
            $matchingIds = [];
            foreach ($channels as $ch) {
                $effectiveName = ($ch['custom_name'] !== null && $ch['custom_name'] !== '')
                    ? (string) $ch['custom_name']
                    : (string) ($ch['tvg_name'] ?? '');
                if (\App\Services\ChannelNameCleanerService::isCommentLine($effectiveName)) {
                    $matchingIds[] = (int) $ch['id'];
                }
            }
            $affected = $repo->bulkSetEnabledByIds($groupId, $matchingIds, 0);
        } elseif ($mode === 'search_replace') {
            $search = (string) ($input['search'] ?? '');
            if ($search === '') {
                $this->jsonError('Zoektekst mag niet leeg zijn.', 400);
            }
            $replace = (string) ($input['replace'] ?? '');
            $caseSensitive = ((int) ($input['case_sensitive'] ?? 0)) === 1;
            $affected = $repo->bulkReplaceNameInGroup($groupId, $search, $replace, $caseSensitive);
        } elseif ($mode === 'adopt_all_display_names') {
            $channels = $repo->filter($groupId, '', '', false, 5000, 0, '', 'all', 'number')['items'];
            $emojiService = new \App\Services\EmojiTagService();
            $emojiService->seedDefaultsIfEmpty();
            foreach ($channels as $ch) {
                if ((int) ($ch['is_enabled'] ?? 0) !== 1) {
                    continue;
                }
                $tvgName = (string) ($ch['tvg_name'] ?? '');
                $parsed = $emojiService->parseName($tvgName, false);
                $displayName = trim((string) ($parsed['display_name'] ?? ''));
                $emoji = (string) ($parsed['emoji_prefix'] ?? '');
                if ($displayName !== '' && $displayName !== $tvgName) {
                    if ($emoji !== '' && str_starts_with($tvgName, $emoji) && str_starts_with($displayName, $emoji . ' ' . $emoji)) {
                        $displayName = trim(substr($displayName, strlen($emoji)));
                    }
                    $repo->updateChannel((int) $ch['id'], ['custom_name' => $displayName]);
                    $affected++;
                }
            }
        } elseif ($mode === 'reset_all_custom_names') {
            $channels = $repo->filter($groupId, '', '', false, 5000, 0, '', 'all', 'number')['items'];
            foreach ($channels as $ch) {
                if ((int) ($ch['is_enabled'] ?? 0) !== 1) {
                    continue;
                }
                if ($ch['custom_name'] !== null && $ch['custom_name'] !== '') {
                    $repo->updateChannel((int) $ch['id'], ['custom_name' => null]);
                    $affected++;
                }
            }
        } else {
            $this->jsonError('Ongeldige modus.', 400);
        }

        (new \App\Services\ViewCacheService())->invalidateGroupChannels($groupId);

        $refreshed = $repo->filter($groupId, '', '', false, 200, 0, '', 'all', 'number');
        $this->jsonSuccess([
            'affected' => $affected,
            'items' => $refreshed['items'],
            'total' => $refreshed['total'],
        ]);
    }

    public function createCustomGroup(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $name = (string) ($input['name'] ?? '');
        $category = (string) ($input['category'] ?? 'Live TV');

        try {
            $repo = new \App\Repositories\CustomGroupRepository();
            $group = $repo->createCustomGroup($name, $category);
            (new ViewCacheService())->invalidateAll();
            $this->jsonSuccess($group);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function convertToCustomGroup(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $sourceGroupId = (int) ($input['source_group_id'] ?? 0);
        if ($sourceGroupId <= 0) {
            $this->jsonError('Ongeldige brongroep.', 400);
        }

        try {
            $repo = new \App\Repositories\CustomGroupRepository();
            $group = $repo->convertProviderGroup($sourceGroupId);
            (new ViewCacheService())->invalidateAll();
            $this->jsonSuccess($group);
        } catch (\Throwable $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function deleteCustomGroup(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $customGroupId = (int) ($input['custom_group_id'] ?? 0);
        $restoreSource = (bool) ($input['restore_source'] ?? true);
        if ($customGroupId <= 0) {
            $this->jsonError('Ongeldige custom groep.', 400);
        }

        try {
            $repo = new \App\Repositories\CustomGroupRepository();
            $repo->deleteCustomGroup($customGroupId, $restoreSource);
            (new ViewCacheService())->invalidateAll();
            $this->jsonSuccess(['deleted' => true]);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function customGroupAddChannels(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $customGroupId = (int) ($input['custom_group_id'] ?? 0);
        $channelIds = is_array($input['channel_ids'] ?? null) ? $input['channel_ids'] : [];
        if ($customGroupId <= 0) {
            $this->jsonError('Ongeldige custom groep.', 400);
        }

        try {
            $repo = new \App\Repositories\CustomGroupRepository();
            $added = $repo->addChannels($customGroupId, $channelIds);
            (new ViewCacheService())->invalidateAll();
            $this->jsonSuccess([
                'added' => $added,
                'member_ids' => $repo->getChannelIdsInCustomGroup($customGroupId),
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->jsonError($e->getMessage(), 400);
        }
    }

    public function customGroupRemoveChannels(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $customGroupId = (int) ($input['custom_group_id'] ?? 0);
        $channelIds = is_array($input['channel_ids'] ?? null) ? $input['channel_ids'] : [];
        if ($customGroupId <= 0) {
            $this->jsonError('Ongeldige custom groep.', 400);
        }

        $repo = new \App\Repositories\CustomGroupRepository();
        $repo->removeChannels($customGroupId, $channelIds);
        (new ViewCacheService())->invalidateAll();
        $this->jsonSuccess(['removed' => true]);
    }

    public function customGroupResolveSync(): void
    {
        $this->requireAuth();
        $input = $this->getJsonInput();
        $this->verifyCsrf($input);

        $customGroupId = (int) ($input['custom_group_id'] ?? 0);
        if ($customGroupId <= 0) {
            $this->jsonError('Ongeldige custom groep.', 400);
        }

        $addIds = is_array($input['add_ids'] ?? null) ? $input['add_ids'] : [];
        $ignoreAddIds = is_array($input['ignore_add_ids'] ?? null) ? $input['ignore_add_ids'] : [];
        $removeIds = is_array($input['remove_ids'] ?? null) ? $input['remove_ids'] : [];

        $syncService = new \App\Services\CustomGroupSyncService();
        $syncService->resolveSyncDiff($customGroupId, $addIds, $ignoreAddIds, $removeIds);
        (new ViewCacheService())->invalidateAll();

        $group = (new \App\Repositories\CustomGroupRepository())->findById($customGroupId);
        $this->jsonSuccess([
            'resolved' => true,
            'sync_diff_json' => $group['sync_diff_json'] ?? null,
        ]);
    }

    public function customGroupMemberIds(): void
    {
        $this->requireAuth();
        $customGroupId = (int) ($_GET['custom_group_id'] ?? 0);
        if ($customGroupId <= 0) {
            $this->jsonSuccess(['member_ids' => []]);
            return;
        }

        $repo = new \App\Repositories\CustomGroupRepository();
        $this->jsonSuccess([
            'member_ids' => $repo->getChannelIdsInCustomGroup($customGroupId),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function parseContentTypes(string $raw): array
    {
        if (trim($raw) === '') {
            return [];
        }
        $allowed = ['live', 'movie', 'series'];
        $parts = array_map('trim', explode(',', $raw));
        return array_values(array_unique(array_filter(
            $parts,
            static fn (string $v): bool => in_array($v, $allowed, true)
        )));
    }
}
