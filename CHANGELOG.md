# Changelog

## [Unreleased] - feature/channel-tools-and-clean-export
### Toegevoegd
- **Groep Kanaal-tools (`GroupChannelToolsBar.js`, `ChannelController::groupChannelTools()`, `ChannelRepository`):** Kwaliteitsfilter (Aan/Uit voor `SD`, `HD`, `FHD`, `UHD`, `4K`, `HEVC`, `RAW`), commentaarregels (`###`) uitschakelen en Zoek & Vervang op kanaalnamen binnen een geselecteerde groep.
- **Kanaalnaam Opschoning (`ChannelNameCleanerService.php`):** Automatisch strippen van video-kwaliteitstags (`SD`, `HD`, `FHD`, `UHD`, `4K`, `8K`, `HEVC`, `H.265`, `RAW`) bij export in M3U (`PlaylistExporterService.php`) en Xtream (`XtreamServerService.php`), zonder de originele `tvg_name` in de database te wijzigen.

## [Unreleased] - feature/xtream-export
### Toegevoegd
- **Uitgaande Xtream Codes API Server (`XtreamServerService.php`, `XtreamServerController.php`):** Volledige ondersteuning voor `player_api.php` (auth, `get_live_categories`, `get_live_streams`, `get_short_epg`, `get_simple_data_table`), `get.php`, `xmltv.php` en `/live/{user}/{pass}/{id}.{ts|m3u8}` redirects.
- **Dashboard Xtream Codes Client Login (`DashboardPage.js`, `DashboardController.php`):** Dedicated kaart met Server URL, Gebruikersnaam, Wachtwoord (gemaskeerd + toggle) en kopieerknoppen.
- **Instellingen Uitgaande Xtream Credentials (`SettingsPage.js`, `SettingsController.php`):** Beheer van `xtream_out_enabled`, `xtream_out_username` en `xtream_out_password` (AES-256-GCM versleuteld) met 1-klik generator voor sterke inloggegevens.
### Gewijzigd
- **M3U & XMLTV EPG Matching voor IPTVX (`PlaylistExporterService.php`):** Absolute `url-tvg` en `x-tvg-url` in `#EXTM3U` header op basis van `HTTP_HOST`/scheme.

## [Unreleased] - feature/rearchitecting-cached
### Toegevoegd
- **View-Cache (`ViewCacheService.php`):** Lichtgewicht JSON view-cache in `storage/cache/view/` voor dashboard-statistieken, actieve groepen per `content_types`-combinatie en kanalen per `group_id`, met gerichte invalidatie bij mutaties.
- **On-demand Groepszoeker (`+ Groep toevoegen`):** Visuele tussenstap in `ChannelsPage.js` gekoppeld aan `groups_search` (`GroupRepository::searchGroups`), inclusief tag-zoeksyntaxis met `(...)`, `[...]` of `|...|` (bijv. `[BE]`, `(NL)`).
- **Bron-toggle & Laadvoortgang (`ChannelsPage.js`):** Toggle-knop `Bron: ⚡ Cache` / `Bron: 🗄️ Database` naast **Filter & Laad**, dynamische scope-badge (`#activeScopeBadge`) en voortgangsbalk met verstreken tijd.
- **EPG Editor Verbeteringen (`EpgMappingPage.js`):** Actieve groepen-filter (`#epgGroupFilter`), 1-rij inline filters, max 10 rijen per pagina met paginatie en sorteerbare kolomheaders (ASC/DESC met chevron).
- **Kanaalsortering met Volgnummer-persistentie:** Klikbare `#` en `Naam van het kanaal` headers met chevron (`reorder_group_channels`), die actieve kanalen atomisch hernummeren (`1..N`) in de database; bij groepswissel wordt sortering gereset naar `number` (ASC).
### Gewijzigd
- **Groepen altijd alfabetisch ASC:** Groepssorteerknop en `Alles Aan`/`Alles Uit` verwijderd; actieve groepen en zoekresultaten sorteren altijd op `original_name ASC`.
- **Emoji-limiet (`EmojiTagService.php`):** Maximaal 1 keyword-emoji per naam (totaal max 2 tags incl. landvlag), en geen extra keyword-emoji als een naam al met een emoji begint.

## [Unreleased] - feature/sorting-and-numbering
### Toegevoegd
- **Stap 2 — Custom Groep- & Kanaalnamen + Bulk-UI (`ChannelsPage.js`, `ChannelTable.js`, `ChannelController.php`):**
  - Inline groepsnaam bewerken (`✏️`) en 1-klik weergavenaam overnemen (`❐`) direct in de kanaalheader boven de kanaaltabel (leeg laten = reset naar originele sync-naam).
  - Dynamische selectieteller op bulk-knoppen (`Selectie Aan (N)` / `Selectie Uit (N)`).
- **Stap 3 — Groepssortering & Dynamische Kanaalhernummering (`GroupRepository`, `ChannelRepository`, `ChannelController`, `ChannelsPage.js`):**
  - Groepssortering op `Import` (`import_order ASC`), `A-Z` of `Z-A` via klikbare `Groepen`-header (`set_group_sort`), persistent opgeslagen in `settings.group_sort_mode`.
  - Klikbare `#` en `Naam` kolomheaders in de kanaaltabel voor sortering op kanaalnummer of alfabetische naam.
  - Dynamische hernummering (`ChannelRepository::reorderChannelInGroup()` binnen PDO-transactie): wijzigen van een kanaalnummer hernummert alle kanalen in die groep direct aaneensluitend (`1..N`) zonder dubbele nummers.
### Gewijzigd
- **Kanaalnaam-weergave (`ChannelTable.js`, `ChannelController.php`):** Vlag-/emoji-prefix wordt als aparte `<span>` naast het `<input class="ch-name">` veld getoond, zodat opslaan geen dubbele emoji's in `custom_name` schrijft.
- **Dashboard LAN Client Endpoint (`DashboardPage.js`, `DashboardController.php`, `PlaylistExporterService.php`):** Kaarttitel bijgewerkt naar `LAN Client Endpoint M3U Link` (`vb: (IPTVX / Kodi / VLC)`), aparte `XMLTV EPG URL` verborgen bij `source_mode = xtream`, en `url-tvg="..."` toegevoegd aan de `#EXTM3U`-header.
- **Documentatie:** `ARCHITECTURE.md` toegevoegd en gekoppeld vanuit `README.md`.

## [Unreleased] - feature/groepen-en-kanalen
### Toegevoegd
- **Stap 1 — Centrale Emoji- & Tag-database + Beheerscherm (`emoji_rules`, `EmojiTagService`, `EmojiRuleController`, `EmojiRulesPage.js`):**
  - Idempotente database-tabel `emoji_rules` toegevoegd met automatische vulling van alle ISO 3166-1 alpha-2 landcodes, IPTV-aliassen (`UK`, `EN`, `USA`, `AR`, enz.), standaard fallback-icoon (`🏷️`) en categorie-trefwoorden (`🎬`, `📺`, `🏎️`, `❤️🔥`).
  - Centrale `EmojiTagService` toegevoegd voor het parsen van ruwe groep- en kanaalnamen (landtags `|BE|`, `BE|`, `|BE` + categorie-emoji's) zonder de originele databasenaam te wijzigen.
  - Apart beheerscherm **🏷️ Landen & Tags Beheren** (`EmojiRulesPage.js`) gekoppeld aan de knop op *Groepen & Kanalen* (niet in het hoofdmenu), inclusief toevoegen, bewerken, activeren/deactiveren, verwijderen, snelle emoji-kiezer en live zoekfunctie.
### Gewijzigd
- **Groepen & Kanalen Performance & Emoji-weergave (`UiCacheService`, `ChannelController`, `ChannelsPage.js`, `GroupList.js`, `ChannelTable.js`):**
  - Event-listener voor `#manageCountryTagsBtn` gekoppeld aan `#tab-emoji-rules`.
  - `ChannelController::groups()` en `ChannelController::channels()` passen `EmojiTagService::parseName()` toe zodat landvlaggen én categorie-emoji's (`🎬`, `📺`, `🏎️`, `❤️🔥`) zichtbaar zijn in groepenlijst, groepskoptekst en kanaaltabel.
  - `UiCacheService` toegevoegd (`storage/cache/ui_groups_cache.json` en `ui_channels_group_{id}.json`) met lazy-loading van de eerste groep en automatische cache-invalidatie bij sync, groep-/kanaalwijzigingen en emoji-regelmutaties.

## [Unreleased] - feature/cachecontrol
### Gewijzigd
- **Dashboard Synchronisatie & Lokale Cache (`DashboardPage.js`, `views/dashboard.php`, `SyncProgressService.php`):**
  - De 6 losse sync/cache-knoppen vervangen door **⚡ Laad Cache** (herimporteert alle lokale M3U/EPG cachebestanden) en **💾 Beheer Cache** (navigeert direct naar Cachebeheer).
  - Dubbele **Cachebeheer**-knop uit de bovenste navigatiebalk verwijderd.
  - Dynamisch voortgangspaneel (`#sync-progress-panel`) toegevoegd met percentage, statusbalk, actuele actie en berekende ETA (`eta_seconds`).
- **Bronnen & Cron: Xtream Codes & Bronselectie (`SettingsPage.js`, `SettingsController.php`, `XtreamUrlBuilder.php`, `SchedulerService.php`):**
  - Radio-selector toegevoegd voor bronmodus: **Xtream Codes API** (`xtream` met `Server URL`, `Gebruikersnaam`, `Wachtwoord`, optionele output-parameters en keuze tussen standaard `/xmltv.php` of een eigen aangepaste EPG-URL) of **Aparte M3U & EPG links** (`separate_urls`).
  - `SchedulerService::run()` valideert vooraf of de actieve bronmodus volledig is geconfigureerd (of dat lokale cachebestanden aanwezig zijn bij `cache_only`) en genereert automatisch beide Xtream-URL's.
- **Groepen & Kanalen UI & Centrale Vlaggen-Registry (`FlagRegistry.php`, `FlagRegistry.js`, `ChannelTable.js`, `GroupList.js`, `ChannelsPage.js`):**
  - Centrale ISO 3166-1 alpha-2 + IPTV-aliassen registry (`FlagRegistry`) aangemaakt voor zowel backend als frontend.
  - Land-/prefix-tags (`|BE|`, `NL|`, `|VIP|`, enz.) worden in de groepen- en kanaallijst visueel omgezet naar vlag-emoji's of `🏷️` zonder de databasedata te wijzigen.
  - Kolom `Aan` vervangen door een visuele toggle-switch, en bulk-selectie voorzien van rij-highlight en duidelijke tooltip/label (`☑️`).
  - Voortgangsbalk, actuele actie en ETA-berekening geëxtraheerd naar `SyncProgressPanel.js` en geïntegreerd op zowel het Dashboard als **Beheer Cache** (`CacheManagerPage`).
  - **Beheer Cache** controleert bij het openen direct `sync_status`, hervat lopende achtergrond-syncs naadloos en blokkeert conflicterende actieknoppen tot de sync/import is afgerond.
  - `DashboardController::sync()` gebruikt `ignore_user_abort(true)` en `set_time_limit(0)` zodat synchronisaties doorlopen bij paginawissel; `DashboardPage::load()` herstelt automatisch de voortgangs-UI bij terugkeer.
  - `M3uParserService::sync()` voert echte delta-vergelijking uit (`INSERT` nieuwe, `UPDATE` enkel gewijzigde, `DELETE` verwijderde kanalen).

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
- `EpgParserService::sync()` valt automatisch terug op `XtreamUrlBuilder` bij lege `epg_sources` (en gooit expliciete fout bij 0 bronnen); `CacheManagerPage` uitgebreid met centrale actie-toolbar.
- `Class "CacheController" not found` opgelost via `use App\Controllers\CacheController;` in `index.php` en namespace-safe FQCN-resolving in `Router.php`.

## [1.0.0] - 2026-11-22
### Toegevoegd
- Volledige OOP backend in PHP 8.2+ (`src/Config`, `src/Database`, `src/Security`, `src/Repositories`, `src/Services`).
- MariaDB/MySQL schema (`database/schema.sql`) met `users`, `settings`, `m3u_groups`, `channels`, `epg_channels`, `epg_programmes`, `sync_logs`.
- Streaming M3U parser (`M3uParserService`) en streaming XMLReader EPG parser (`EpgParserService`).
- Gefilterde M3U & XMLTV (met gzip) exporters (`PlaylistExporterService`, `EpgExporterService`).
- 4-staps Web Installer (`public/install.php`) met automatische vergrendeling.
- Responsive dark/light-ready Admin Dashboard (`public/index.php`, `app.css`, `app.js`).
- CLI sync entrypoint (`bin/sync.php`) en JSON REST/Cron API (`public/api.php`).
