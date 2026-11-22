<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\BaseController;
use App\Repositories\EpgRepository;
use App\Services\EpgMatcherService;

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

        $matched = (new EpgMatcherService())->autoMatchUnmapped();
        $this->jsonSuccess(['matched' => $matched]);
    }
}
