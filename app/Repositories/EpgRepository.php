<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\BaseRepository;

final class EpgRepository extends BaseRepository
{
    protected string $table = 'epg_channels';
    protected string $primaryKey = 'epg_id';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function searchChannels(string $query, int $limit = 30): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT epg_id, display_name, icon_url
             FROM epg_channels
             WHERE display_name LIKE :q OR epg_id LIKE :q
             ORDER BY display_name ASC
             LIMIT :lim'
        );
        $stmt->bindValue(':q', '%' . $query . '%');
        $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }
}
