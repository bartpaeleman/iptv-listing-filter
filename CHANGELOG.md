# Changelog

## [1.1.0] - 2026-11-22
### Toegevoegd
- Uitgebreide prerequisite-diagnostiek in de Web Installer (`InstallerService::checkPrerequisites()`) met kopieerbare QNAP/Linux herstelcommando's en asynchrone "Opnieuw controleren" knop (`?route=install&step=recheck`).
- Least-Privilege DB provisioning in de Web Installer (`InstallerService::provisionDatabaseAndUser()`): maakt een dedicated restricted runtime-gebruiker (`iptv_user`) aan zonder root-credentials in `.env` op te slaan.
### Opgelost
- Cross-device `ClipboardHelper` met iPadOS/Safari HTTP-fallback (`execCommand('copy')` op tijdelijk `<textarea>` zonder auto-zoom) voor alle kopieerknoppen.

## [1.0.0] - 2026-11-22
### Toegevoegd
- Volledige OOP backend in PHP 8.2+ (`src/Config`, `src/Database`, `src/Security`, `src/Repositories`, `src/Services`).
- MariaDB/MySQL schema (`database/schema.sql`) met `users`, `settings`, `m3u_groups`, `channels`, `epg_channels`, `epg_programmes`, `sync_logs`.
- Streaming M3U parser (`M3uParserService`) en streaming XMLReader EPG parser (`EpgParserService`).
- Gefilterde M3U & XMLTV (met gzip) exporters (`PlaylistExporterService`, `EpgExporterService`).
- 4-staps Web Installer (`public/install.php`) met automatische vergrendeling.
- Responsive dark/light-ready Admin Dashboard (`public/index.php`, `app.css`, `app.js`).
- CLI sync entrypoint (`bin/sync.php`) en JSON REST/Cron API (`public/api.php`).
