# Changelog

## [1.1.0] - 2026-11-22
### Toegevoegd
- Uitgebreide prerequisite-diagnostiek in de Web Installer (`InstallerService::checkPrerequisites()`) met kopieerbare QNAP/Linux herstelcommando's en asynchrone "Opnieuw controleren" knop (`?route=install&step=recheck`).
- Least-Privilege DB provisioning in de Web Installer (`InstallerService::provisionDatabaseAndUser()`): maakt een dedicated restricted runtime-gebruiker (`iptv_user`) aan zonder root-credentials in `.env` op te slaan.
- Bidirectionele stapnavigatie ("← Vorige stap" + klikbare voltooide stappen in de stepper) en idempotente her-invoer met `install_completed` flag in de Web Installer.
- **Xtream Codes & Multi-Source EPG:** Automatische `m3u_plus` + `xmltv.php` URL-builder (`XtreamUrlBuilder`), `epg_sources` fallback-tabel met prioriteit, streaming `.xml.gz` decompressie en expliciete DNS/HTTP-foutafhandeling in `HttpClient`.
- **Lokale Bestandscaching (`FeedCacheService`):** Slaat gedownloade M3U/EPG bestanden lokaal op in `storage/cache/` (`cache_ttl_minutes`, default 360 min) met UI- en CLI-opties (`--use-cache`) voor snelle herimport zonder provider-download.
- **Sync Log Leegmaken:** Knop **"Log leegmaken"** (`#btn-clear-sync-logs`) op het dashboard gekoppeld aan `?route=api&endpoint=clear_sync_logs`.
- **Latest-Only Delta Migratiebeleid (`SchemaMigrator`):** `database/update.sql` bevat voortaan uitsluitend de laatste schema-wijziging; `SchemaMigrator` voert deze automatisch uit bij een gewijzigde SHA-256 hash (`last_update_sql_hash`).
- **Real-Time Sync Voortgang & Deduplicatie (`SyncProgressService`):** Atomische voortgangsrapportage (`sync_progress.json`), non-blocking `?route=api&endpoint=sync_status` polling (`session_write_close()`), live console-output en `UNIQUE KEY uq_epg_programmes`.
- **Geïsoleerde M3U/EPG Cache & Land-Tags (`CountryTagService`):** Aparte M3U/EPG cache- & downloadknoppen op het Dashboard, `country_tags` tabel met vlag-emoji's, tag-scanner, landgroepering en uitgebreide filters (`country_tag_id`, `status`, `has_epg`) op Groepen & Kanalen.
- **Multi-File Cachebeheer (`CacheController`, `CacheManagerPage`):** Slaat downloads op met unieke bestandsnamen (`m3u_YYYY-MM-DD_HHMMSS_<bron>.m3u` / `epg_...xml`) en `.meta.json` (`label`, `item_count`), plus een apart **Cachebeheer**-tabblad voor herimport, label-aanpassing en bulk-verwijdering.
### Opgelost
- Cross-device `ClipboardHelper` met iPadOS/Safari HTTP-fallback (`execCommand('copy')` op tijdelijk `<textarea>` zonder auto-zoom) voor alle kopieerknoppen.
- `SQLSTATE[22001]` bij lange `tvg_logo` / `icon_url` URL's opgelost door kolommen te verruimen naar `TEXT NULL` en M3U/EPG metadata veilig af te kappen (`sanitizeString()` / `sanitizeText()`).
- Leeg scherm op tabblad Bronnen/EPG opgelost via `try/catch` foutafhandeling in `App.js::switchTab()` en graceful fallback in `SettingsController::get()`.
- Cross-device cache-opslag in `FeedCacheService` verhard (`clearstatcache`, `rename` -> `copy`+`unlink` fallback en 0-byte validatie).
- Runtime `MEDIUMTEXT` kolom-check (`SchemaMigrator::ensureSchemaUpToDate()`) vóór elke M3U/EPG-sync + harde pre-insert `iconv`/lengte-guard op `tvg_logo` en `VARCHAR(255)` velden.
- Safari/WebKit `"The string did not match the expected pattern"` fout opgelost via `URL` + `URLSearchParams` en two-step JSON parsing in `ApiClient.js`, plus `ob_clean()` guard in `BaseController`.
- Automatische `filemtime` cache-busting toegevoegd op CSS/JS assets in `views/layout.php`, `views/dashboard.php` en `views/install.php` voor iPad Safari, plus endpoint/URL-diagnostiek in `ApiClient.js`.
- Globale `Throwable` JSON exception-handler toegevoegd aan `index.php` (`?route=api`) met stacktrace-logging naar `storage/logs/app.log`.
- `FeedCacheService::getCacheInfo()` en `listCacheFiles()` gefixt zodat ontbrekende/legacy `.meta.json` `filename` keys nooit een exception gooien op het dashboard.
- `SQLSTATE[42S02]` (`epg_sources` / `country_tags` ontbreekt) opgelost door `SchemaMigrator::ensureSchemaUpToDate()` alle `CREATE TABLE IF NOT EXISTS` en `INSERT IGNORE` statements uit `database/schema.sql` automatisch te laten uitvoeren.
- Safari `"Load failed"` opgelost via snelle `schema_integrity_v2` check in `SchemaMigrator` (geen zware DDL bij elke API-call), SQL-comment stripping en een fatal-error JSON shutdown handler in `index.php`.
- Globale `error` / `unhandledrejection` toast-melding toegevoegd in `App.js` en 250-kanalen batch-transacties + `512M`/`set_time_limit(0)` limieten in `M3uParserService` & `EpgParserService` voor grote (98+ MB) bestanden.

## [1.0.0] - 2026-11-22
### Toegevoegd
- Volledige OOP backend in PHP 8.2+ (`src/Config`, `src/Database`, `src/Security`, `src/Repositories`, `src/Services`).
- MariaDB/MySQL schema (`database/schema.sql`) met `users`, `settings`, `m3u_groups`, `channels`, `epg_channels`, `epg_programmes`, `sync_logs`.
- Streaming M3U parser (`M3uParserService`) en streaming XMLReader EPG parser (`EpgParserService`).
- Gefilterde M3U & XMLTV (met gzip) exporters (`PlaylistExporterService`, `EpgExporterService`).
- 4-staps Web Installer (`public/install.php`) met automatische vergrendeling.
- Responsive dark/light-ready Admin Dashboard (`public/index.php`, `app.css`, `app.js`).
- CLI sync entrypoint (`bin/sync.php`) en JSON REST/Cron API (`public/api.php`).
