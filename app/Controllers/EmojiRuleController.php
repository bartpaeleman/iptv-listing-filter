<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\EmojiRuleRepository;
use App\Services\EmojiTagService;

final class EmojiRuleController extends BaseController
{
    private EmojiRuleRepository $repo;

    public function __construct(?EmojiRuleRepository $repo = null)
    {
        parent::__construct();
        $this->repo = $repo ?? new EmojiRuleRepository();
    }

    public function list(): void
    {
        $this->requireAuth();
        (new EmojiTagService())->seedDefaultsIfEmpty();
        $this->jsonSuccess($this->repo->allOrdered());
    }

    public function save(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $input = $this->getJsonInput();

        $id = isset($input['id']) && (int) $input['id'] > 0 ? (int) $input['id'] : null;
        $matchType = trim((string) ($input['match_type'] ?? ''));
        $pattern = trim((string) ($input['pattern'] ?? ''));
        $emoji = trim((string) ($input['emoji'] ?? ''));
        $label = trim((string) ($input['label'] ?? ''));
        $isActive = !isset($input['is_active']) || (bool) $input['is_active'];

        if (!in_array($matchType, ['country', 'keyword'], true)) {
            $this->jsonError('Ongeldig regeltype (kies country of keyword).', 422);
            return;
        }

        if ($pattern === '' || $emoji === '') {
            $this->jsonError('Patroon en emoji zijn verplicht.', 422);
            return;
        }

        if ($matchType === 'country') {
            $pattern = strtoupper($pattern);
        } else {
            $pattern = mb_strtolower($pattern, 'UTF-8');
        }

        $savedId = $this->repo->saveRule(
            $matchType,
            $pattern,
            $emoji,
            $label !== '' ? $label : null,
            $isActive,
            $id
        );

        (new EmojiTagService())->invalidateRulesCache();
        $this->jsonSuccess(['id' => $savedId]);
    }

    public function toggle(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $input = $this->getJsonInput();

        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Ongeldig regel-ID.', 422);
            return;
        }

        $isActive = (bool) ($input['is_active'] ?? true);
        $this->repo->toggleActive($id, $isActive);
        (new EmojiTagService())->invalidateRulesCache();
        $this->jsonSuccess();
    }

    public function delete(): void
    {
        $this->requireAuth();
        $this->verifyCsrf();
        $input = $this->getJsonInput();

        $id = (int) ($input['id'] ?? 0);
        if ($id <= 0) {
            $this->jsonError('Ongeldig regel-ID.', 422);
            return;
        }

        $this->repo->deleteById($id);
        (new EmojiTagService())->invalidateRulesCache();
        $this->jsonSuccess();
    }
}
