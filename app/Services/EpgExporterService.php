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
        @set_time_limit(0);
        @ini_set('display_errors', '0');

        if (($_GET['download'] ?? '') === '1' && !headers_sent()) {
            $filename = $useGzip ? 'epg.xml.gz' : 'epg.xml';
            header('Content-Disposition: attachment; filename="' . $filename . '"');
        }

        $deflateCtx = ($useGzip && function_exists('deflate_init'))
            ? deflate_init(ZLIB_ENCODING_GZIP, ['level' => 6])
            : false;

        $out = static function (string $chunk) use ($deflateCtx): void {
            if ($deflateCtx !== false) {
                $compressed = deflate_add($deflateCtx, $chunk, ZLIB_NO_FLUSH);
                if ($compressed !== false && $compressed !== '') {
                    echo $compressed;
                }
            } else {
                echo $chunk;
            }
        };

        $buffer = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $buffer .= "<tv generator-info-name=\"IPTV-EPG-Manager\">\n";

        $channelStmt = $this->pdo->query(
            "SELECT DISTINCT ec.epg_id, ec.display_name, ec.icon_url
             FROM epg_channels ec
             INNER JOIN channels c ON COALESCE(NULLIF(TRIM(c.epg_channel_id), ''), NULLIF(TRIM(c.tvg_id), '')) = ec.epg_id
             INNER JOIN m3u_groups g ON g.id = c.group_id
             WHERE c.is_enabled = 1
               AND (
                   (g.is_custom = 0 AND g.is_enabled = 1)
                   OR EXISTS (
                       SELECT 1 FROM custom_group_channels cgc
                       INNER JOIN m3u_groups cg ON cg.id = cgc.custom_group_id
                       WHERE cgc.channel_id = c.id AND cgc.is_ignored = 0 AND cg.is_custom = 1 AND cg.is_enabled = 1
                   )
               )
             ORDER BY ec.display_name ASC"
        );

        $activeIds = [];
        foreach ($channelStmt->fetchAll() as $ch) {
            $id = (string) $ch['epg_id'];
            $activeIds[] = $id;
            $name = $this->escapeXml((string) $ch['display_name']);
            $idEsc = $this->escapeXml($id);

            $buffer .= "  <channel id=\"{$idEsc}\">\n";
            $buffer .= "    <display-name>{$name}</display-name>\n";
            if (!empty($ch['icon_url'])) {
                $iconEsc = $this->escapeXml((string) $ch['icon_url']);
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
                $start = $this->formatXmltvTime((string) $prog['start_utc']);
                $stop = $this->formatXmltvTime((string) $prog['stop_utc']);
                if ($start === '' || $stop === '') {
                    continue;
                }

                $idEsc = $this->escapeXml((string) $prog['epg_id']);
                $title = $this->escapeXml((string) $prog['title']);

                $buffer .= "  <programme start=\"{$start}\" stop=\"{$stop}\" channel=\"{$idEsc}\">\n";
                $buffer .= "    <title>{$title}</title>\n";
                if (!empty($prog['description'])) {
                    $desc = $this->escapeXml((string) $prog['description']);
                    $buffer .= "    <desc>{$desc}</desc>\n";
                }
                if (!empty($prog['category'])) {
                    $cat = $this->escapeXml((string) $prog['category']);
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
        if ($deflateCtx !== false) {
            $tail = deflate_add($deflateCtx, '', ZLIB_FINISH);
            if ($tail !== false && $tail !== '') {
                echo $tail;
            }
        }
    }

    private function escapeXml(string $value): string
    {
        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $value);
        if ($clean === false) {
            $clean = $value;
        }
        $clean = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean) ?? $clean;
        return htmlspecialchars($clean, ENT_XML1 | ENT_SUBSTITUTE | ENT_QUOTES, 'UTF-8');
    }

    private function formatXmltvTime(string $utcDatetime): string
    {
        $ts = strtotime($utcDatetime . ' UTC');
        return $ts !== false ? gmdate('YmdHis', $ts) . ' +0000' : '';
    }
}
