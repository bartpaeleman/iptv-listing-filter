<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use PDO;

final class EpgExporterService
{
    private PDO $pdo;

    public function __construct(?PDO $pdo = null)
    {
        $this->pdo = $pdo ?? Database::getInstance();
    }

    public function streamXmltv(bool $useGzip = false): void
    {
        $out = static function (string $chunk) use ($useGzip): void {
            echo $useGzip ? gzencode($chunk, 6) : $chunk;
        };

        $buffer = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $buffer .= "<tv generator-info-name=\"IPTV-EPG-Manager\">\n";

        $channelStmt = $this->pdo->query(
            "SELECT DISTINCT ec.epg_id, ec.display_name, ec.icon_url
             FROM epg_channels ec
             INNER JOIN channels c ON c.epg_channel_id = ec.epg_id
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE g.is_enabled = 1 AND c.is_enabled = 1
             ORDER BY ec.display_name ASC"
        );

        $activeIds = [];
        foreach ($channelStmt->fetchAll() as $ch) {
            $id = (string) $ch['epg_id'];
            $activeIds[] = $id;
            $name = htmlspecialchars((string) $ch['display_name'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $idEsc = htmlspecialchars($id, ENT_XML1 | ENT_QUOTES, 'UTF-8');

            $buffer .= "  <channel id=\"{$idEsc}\">\n";
            $buffer .= "    <display-name>{$name}</display-name>\n";
            if (!empty($ch['icon_url'])) {
                $iconEsc = htmlspecialchars((string) $ch['icon_url'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $buffer .= "    <icon src=\"{$iconEsc}\" />\n";
            }
            $buffer .= "  </channel>\n";
        }

        if ($activeIds !== []) {
            $placeholders = implode(',', array_fill(0, count($activeIds), '?'));
            $progStmt = $this->pdo->prepare(
                "SELECT epg_id, start_utc, stop_utc, title, description, category
                 FROM epg_programmes
                 WHERE epg_id IN ({$placeholders})
                 ORDER BY epg_id ASC, start_utc ASC"
            );
            $progStmt->execute($activeIds);

            while ($prog = $progStmt->fetch()) {
                $idEsc = htmlspecialchars((string) $prog['epg_id'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $start = $this->formatXmltvTime((string) $prog['start_utc']);
                $stop = $this->formatXmltvTime((string) $prog['stop_utc']);
                $title = htmlspecialchars((string) $prog['title'], ENT_XML1 | ENT_QUOTES, 'UTF-8');

                $buffer .= "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$idEsc}\">\n";
                $buffer .= "    <title>{$title}</title>\n";
                if (!empty($prog['description'])) {
                    $desc = htmlspecialchars((string) $prog['description'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $buffer .= "    <desc>{$desc}</desc>\n";
                }
                if (!empty($prog['category'])) {
                    $cat = htmlspecialchars((string) $prog['category'], ENT_XML1 | ENT_QUOTES, 'UTF-8');
                    $buffer .= "    <category>{$cat}</category>\n";
                }
                $buffer .= "  </programme>\n";

                if (strlen($buffer) > 65536) {
                    $out($buffer);
                    $buffer = '';
                }
            }
        }

        $buffer .= "</tv>\n";
        if ($buffer !== '') {
            $out($buffer);
        }
    }

    private function formatXmltvTime(string $utcDatetime): string
    {
        $ts = strtotime($utcDatetime . ' UTC');
        return $ts !== false ? gmdate('YmdHis', $ts) . ' +0000' : '';
    }
}
