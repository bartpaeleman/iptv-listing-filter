# Detailed Changes

## Gesplitste Cronjobs, Download-knoppen, M3U Prefix & Login Autofill (`feature/cronjob-split`)
- **`app/Services/CrontabService.php` & `app/Controllers/SettingsController.php`:**
  - `CrontabService::getStatus()` rapporteert beide jobs afzonderlijk onder `jobs.m3u` (`--m3u`) en `jobs.epg` (`--epg`).
  - `CrontabService::saveJob($schedule, $phpPath, $type)` en `deleteJob($type)` beheren uitsluitend de bijbehorende `--m3u` of `--epg` regel in de systeem-crontab.
  - `SettingsController::get()` en `save()` ondersteunen aparte instellingen (`sync_m3u_interval_hours`, `sync_m3u_preferred_hour`, `sync_epg_interval_hours`, `sync_epg_preferred_hour`); `crontabSave()` en `crontabDelete()` geven `type` door aan `CrontabService`.
- **`assets/js/pages/SettingsPage.js`:**
  - Selector (`📺 M3U Kanalen Cronjob` | `📅 EPG Gids Cronjob`) toegevoegd die interval/voorkeursuur, crontab-commando en interactief crontab-beheer (inclusief dynamische knoppen `toevoegen` / `bijwerken` / `wissen`) omschakelt per job.
- **`app/Services/PlaylistExporterService.php` & `app/Services/EpgExporterService.php`:**
  - `PlaylistExporterService::streamPlaylist()` selecteert `g.category` en past `formatGroupTitle()` toe (`VOD - ` voor `Film`, `Series - ` voor `TV Series`).
  - Beide exporters sturen `Content-Disposition: attachment` (`playlist.m3u` resp. `epg.xml`/`epg.xml.gz`) wanneer `$_GET['download'] === '1'`.
- **`assets/js/pages/DashboardPage.js` & `assets/css/components.css`:**
  - `⬇️ Download`-knoppen (`.btn-download`, `min-height: 44px`, teal `#0d9488`) toegevoegd naast de M3U en EPG `Kopieer`-knoppen, gekoppeld aan de actuele input-URL + `&download=1`.
- **`views/login.php`:**
  - `action="index.php?route=login"`, `id="username"` / `id="password"`, `autocomplete="username"` / `autocomplete="current-password"`, `autocorrect="off"` en bijpassende `<label for="...">` voor Bitwarden en browser-autofill.

## Automatische Groepscategorieën & Beheer (`feature/groep-tags`)
- **`database/schema.sql`, `database/update.sql` & `app/Core/SchemaMigrator.php`:**
  - Kolommen `category` (`VARCHAR(32)`) en `category_locked` (`TINYINT(1)`) toegevoegd aan `m3u_groups`, plus tabel `group_category_rules` (`schema_integrity_v7`).
- **`app/Services/GroupCategoryService.php` & `app/Services/M3uParserService.php`:**
  - `GroupCategoryService::classify()` classificeert groepen op basis van eigen `group_category_rules` en ingebouwde trefwoorden (`Sport`, `Animatie`, `TV Series`, `Film`, `Muziek`, `Adult`, fallback `Live TV`).
  - `M3uParserService::sync()` zet nieuwe groepen en kanalen standaard op `is_enabled = 0`, laat bestaande `is_enabled`-statussen en handmatig vergrendelde groepstags (`category_locked = 1`) ongemoeid, rapporteert conditioneel `$groupTagsUpdated` aan `SyncProgressService` (alleen bij `> 0`), en voert `SchemaMigrator::ensureSchemaUpToDate()`, `classifyUnlockedGroups()` en `ViewCacheService::invalidateAll()` uit na elke M3U-import.
- **`app/Repositories/GroupRepository.php`, `app/Controllers/ChannelController.php` & `index.php`:**
  - `GroupRepository` ondersteunt `categories`-filter in `searchGroups()` en `updateCategory()`.
  - Nieuwe endpoints `update_group_category` (strikte whitelist-validatie met HTTP 400), `group_category_rules`, `save_group_category_rule` en `delete_group_category_rule`.
- **`assets/js/components/GroupList.js`, `assets/js/pages/ChannelsPage.js`, `assets/js/pages/EmojiRulesPage.js` & `assets/css/components.css`:**
  - `GroupList` markeert de geselecteerde groep met `.is-selected`; `ChannelsPage` start zonder geselecteerde groep (lege kanaalstaat), reset `selectedGroupId` bij uitschakelen en biedt per groep een inklapbare `👁️ Inhoud` kanaal-preview (`.group-preview-box`).
  - Oude scope- en `Filter & Laad`-bovenbalk verwijderd; rechter groepsselectiepaneel uitgebreid met 7 togglebare categorie-filterknoppen (`Live TV`, `Sport`, `Animatie`, `TV Series`, `Film`, `Muziek`, `Adult`), aparte categorie-kolom met direct klikbare tag-dropdown (`.category-tag-select` met chevron-down, 44px touch-target) zonder losse edit-knop.
  - `EmojiRulesPage` uitgebreid met sectie **Groep-categorie Regels** met vaste `<select>`-dropdown voor alle 7 categorieën.
  - Resterende `selectedContentTypes`-referenties en `content_types`-parameters opgeschoond uit `ChannelsPage.js`.

## Load Performance, View-Cache & EPG/Channel UX (`feature/rearchitecting-cached`)
- **`app/Services/ViewCacheService.php`:**
  - Nieuwe file-based JSON view-cache in `storage/cache/view/` voor `dashboard_stats.json`, `active_groups_*.json` en `group_channels_{id}.json` met `invalidateDashboardStats()`, `invalidateGroupsCache()`, `invalidateGroupChannels()` en `invalidateAll()`.
- **`app/Repositories/GroupRepository.php` & `app/Repositories/ChannelRepository.php`:**
  - `GroupRepository::activeWithCounts()` haalt uitsluitend actieve groepen (`is_enabled = 1`) op, altijd gesorteerd op `g.original_name ASC, g.id ASC`.
  - `GroupRepository::searchGroups()` zoekt direct op `m3u_groups` (limiet 50-100, zonder `JOIN channels`) en ondersteunt `(...)`, `[...]` en `|...|` tag-syntaxis.
  - `ChannelRepository::filter()` ondersteunt extra `sort_by`-waarden (`number_desc`, `epg_status_asc|desc`, `epg_name_asc|desc`).
  - `ChannelRepository::reorderGroupBySort()` hernummert actieve kanalen van een groep atomisch (`1..N`) volgens de gekozen sortering.
- **`app/Controllers/ChannelController.php`, `app/Controllers/DashboardController.php` & `index.php`:**
  - `DashboardController::index()` gebruikt `ViewCacheService` voor de 6 `COUNT(*)`-statistieken.
  - `ChannelController::groups()` ondersteunt `scope=active|all`, `cache_only=1` en `refresh=1`.
  - Nieuwe endpoints `groups_search` (`ChannelController::searchGroups()`) en `reorder_group_channels` (`ChannelController::reorderGroupChannels()`), plus `ChannelController::setGroupSort()`.
- **`app/Services/EmojiTagService.php`:**
  - `parseName()` stopt na de eerste keyword-match (`break`) en slaat keyword-emoji's over als `clean_name` al met een emoji begint.
- **`assets/js/pages/ChannelsPage.js`, `assets/css/components.css` & `assets/js/pages/EpgMappingPage.js`:**
  - `ChannelsPage`: auto-load uit view-cache bij openen en bij klikken op scope-toggles, `Bron: ⚡ Cache` / `Bron: 🗄️ Database` toggle, `#activeScopeBadge`, `#channelsLoadProgressWrap`, `+ Groep toevoegen` verplaatst naar het rechterpaneel (`#groupAddPanel` ter vervanging van `#channelsViewContainer`) met rode toggle-status (`.btn-danger` in `components.css`), en klikbare `#` / `Naam van het kanaal` headers met `reorder_group_channels`.
  - `EpgMappingPage`: 1-rij toolbar met `#epgGroupFilter`, 10 rijen per pagina met paginatie, en sorteerbare kolomheaders met chevrons.

## Groepen & Kanalen — Stap 2 & 3: Custom Namen, Groepssortering & Dynamische Hernummering (`feature/sorting-and-numbering`)
- **`database/schema.sql`, `database/update.sql` & `app/Core/SchemaMigrator.php`:**
  - Kolom `import_order INT UNSIGNED NOT NULL DEFAULT 0` toegevoegd aan `m3u_groups` en `group_sort_mode` (`import`) toegevoegd aan `settings`.
  - `SchemaMigrator::ensureSchemaUpToDate()` voert idempotente `INFORMATION_SCHEMA`-checks uit voor `m3u_groups.custom_name` en `m3u_groups.import_order` en bumpt de integriteitsflag naar `schema_integrity_v5`.
- **`app/Repositories/GroupRepository.php`, `app/Repositories/ChannelRepository.php`, `app/Controllers/ChannelController.php` & `index.php`:**
  - `GroupRepository::allWithCounts(string $sortMode)` ondersteunt `import`, `asc` en `desc`.
  - Nieuw endpoint `set_group_sort` (`POST`, auth + CSRF) in `index.php` en `ChannelController::setGroupSort()`.
  - `ChannelRepository::filter()` ondersteunt `sort_by` (`number` | `name`).
  - `ChannelRepository::reorderChannelInGroup(int $channelId, int $newPosition)` hernummert kanalen binnen een groep atomisch (`1..N`) in een PDO-transactie; `ChannelController::updateChannel()` retourneert de bijgewerkte `items`.
  - `ChannelController::channels()` levert `clean_name` en `emoji_prefix` afzonderlijk terug.
- **`assets/js/components/GroupList.js`, `assets/js/components/ChannelTable.js` & `assets/js/pages/ChannelsPage.js`:**
  - Inline groepsnaam-bewerking (`#editGroupNameBtn`, `#inlineGroupNameInput`) en 1-klik overname (`#adoptGroupNameBtn`) verplaatst naar de kanaalheader via `updateGroupHeader()`.
  - Klikbare `Groepen`-header (`#groupSortBtn`) en `#` / `Naam` kolomheaders (`#sortChannelsByNum`, `#sortChannelsByName`).
  - `ChannelTable` scheidt `emoji_prefix` van `<input class="ch-name">` en meldt selectietellingen via `onSelectionChange`.
- **`app/Controllers/DashboardController.php`, `app/Services/PlaylistExporterService.php` & `assets/js/pages/DashboardPage.js`:**
  - `DashboardController::index()` geeft `source_mode` terug; `DashboardPage` verbergt de losse EPG-URL bij `xtream` en `PlaylistExporterService` stuurt `url-tvg="..."` naast `x-tvg-url="..."` mee in `#EXTM3U`.

## Groepen & Kanalen — Stap 1: Centrale Emoji- & Tag-database + Beheerscherm (`feature/groepen-en-kanalen`)
- **`database/schema.sql`, `database/update.sql` & `app/Core/SchemaMigrator.php`:**
  - Tabel `emoji_rules` (`id`, `match_type`, `pattern`, `emoji`, `label`, `is_active`) toegevoegd met `SchemaMigrator::ensureSchemaUpToDate()` (`schema_integrity_v3`) en automatische seeding via `EmojiTagService::seedDefaultsIfEmpty()`.
- **`app/Repositories/EmojiRuleRepository.php` & `app/Services/EmojiTagService.php`:**
  - CRUD-operaties voor `emoji_rules` en centrale parser `EmojiTagService::parseName()` / `formatGroupName()` / `formatChannelName()`.
- **`app/Controllers/EmojiRuleController.php` & `index.php`:**
  - API-endpoints `emoji_rules`, `save_emoji_rule`, `toggle_emoji_rule`, `delete_emoji_rule` met CSRF-validatie en auth-check.
- **`views/dashboard.php`, `assets/js/App.js`, `assets/js/pages/ChannelsPage.js` & `assets/js/pages/EmojiRulesPage.js`:**
  - Verborgen subpagina `#tab-emoji-rules` toegevoegd, bereikbaar via de knop `🏷️ Landen & Tags Beheren` (`#manageCountryTagsBtn`) in `ChannelsPage.js`.
  - `ChannelsPage::load()` selecteert automatisch de eerste groep voor lazy-loading van kanalen.
- **`app/Services/UiCacheService.php`, `app/Controllers/ChannelController.php`, `app/Services/M3uParserService.php`, `assets/js/components/GroupList.js` & `assets/js/components/ChannelTable.js`:**
  - `UiCacheService` cachet `ui_groups_cache.json` en `ui_channels_group_{id}.json` en invalideert automatisch bij `updateGroup()`, `bulkGroups()`, `updateChannel()`, `bulkChannels()`, `EmojiRuleController` mutaties en `M3uParserService::sync()`.
  - `GroupList.js` en `ChannelTable.js` gebruiken `display_name` / `group_display_name` uit `EmojiTagService`.

## Cachebeheer UI, Voortgangs-ETA & Xtream Bronselectie (`feature/cachecontrol`)
- **`views/dashboard.php` & `assets/js/pages/DashboardPage.js`:**
  - `Cachebeheer` tabknop uit de topbar verwijderd; `DashboardPage` toont nu naast `🔄 Alles Downloaden & Sync` enkel `⚡ Laad Cache` (`cache_mode=cache_only`) en `💾 Beheer Cache` (`#openCacheManagerBtn`).
  - `#sync-progress-panel` toegevoegd met `#syncProgressMsg`, `#syncProgressPercent`, `#syncProgressEta`, `#syncProgressFill` en `#syncLiveConsole`.
- **`app/Core/FlagRegistry.php` & `assets/js/core/FlagRegistry.js`:**
  - Centrale ISO-landcodes + IPTV-aliassen (`UK` -> `🇬🇧`, `EN` -> `🇬🇧`, `USA` -> `🇺🇸`, enz.) en `formatTagPrefix()` helper voor visuele weergave in `GroupList.js` en `ChannelTable.js`.
- **`assets/js/components/ChannelTable.js` & `assets/css/components.css`:**
  - `.toggle-switch` voor de `Aan`-kolom en `.row-selected` rij-highlight voor bulk-selectie.
  - `SyncProgressPanel` biedt een gedeelde voortgangs-UI (`startPolling()`, `stopPolling()`, `checkActive()`, ETA-formattering) voor zowel het Dashboard als Cachebeheer.
  - `CacheManagerPage` blokkeert conflicterende knoppen via `setActionsDisabled()` zolang `sync_status` actief is.
  - Slaat `started_at` op in `sync_progress.json` en berekent `eta_seconds` op basis van verstreken tijd en actueel voortgangspercentage.
- **`app/Services/XtreamUrlBuilder.php` & `app/Controllers/SettingsController.php`:**
  - `XtreamUrlBuilder::buildM3uPlusUrl()` accepteert optionele `$outputParams` (default `type=m3u_plus&output=ts`).
  - `SettingsController::get()` en `save()` ondersteunen `source_mode` (`xtream` | `separate_urls`), `xtream_host`, `xtream_username` (AES-256-GCM versleuteld), `xtream_password` (AES-256-GCM versleuteld) en `xtream_output_params`, en genereren automatisch `m3u_source_url` en `epg_source_url` bij opslaan.
- **`assets/js/pages/SettingsPage.js`:**
  - Radio-selector toegevoegd tussen Xtream Codes API en Aparte M3U & EPG links met dynamische zichtbaarheid van de bijbehorende invoervelden.
- **`app/Services/SchedulerService.php`:**
  - `validateSourceConfiguration()` toegevoegd om vóór elke download/sync te controleren of de actieve `source_mode` compleet is geconfigureerd (incl. `xtream_epg_mode`: `xtream_xmltv` of `custom_url`) of dat lokale cache bestaat bij `cache_only`.
- **`app/Controllers/DashboardController.php` & `app/Services/M3uParserService.php`:**
  - `DashboardController::sync()` schakelt `ignore_user_abort(true)` en `set_time_limit(0)` in voor onafgebroken achtergrond-uitvoering; `DashboardPage::load()` hervat automatisch `startProgressPolling()` bij een lopende sync.
  - `M3uParserService::sync()` vergelijkt binnenkomende kanalen met de huidige database-records en voert uitsluitend `INSERT`, `UPDATE` (bij gewijzigde metadata) en `DELETE` (voor verdwenen kanalen) uit.

## Architectuur
- **Autoloading:** `src/autoload.php` (PSR-4 zonder Composer/`vendor/`).
- **Configuratie & Beveiliging:**
  - `EnvLoader`: leest `.env` zonder externe packages.
  - `CryptoService`: AES-256-GCM encryptie voor gevoelige M3U/EPG bron-URLs in `settings`.
  - `AuthService`: sessie-authenticatie, CSRF-bescherming, brute-force rate limiting en `verifyLanOrToken()` voor uitgaande feeds.
- **Parsers & Exporters:**
  - `M3uParserService`: streaming `fopen`/`fgets` + `stream_hash` upsert zodat handmatige kanaalnamen, nummers en EPG-koppelingen behouden blijven.
  - `EpgParserService`: streaming `XMLReader` (ondersteunt `.xml` en `.xml.gz`), importeert alle `<channel>` tags en enkel `<programme>` records voor actief gekoppelde kanalen.
  - `PlaylistExporterService` & `EpgExporterService`: leveren schone, gefilterde feeds aan LAN-clients.
- **Installer Diagnostiek (`InstallerService`, `InstallController`, `InstallerPage.js`):**
  - `checkPrerequisites()` retourneert nu `id`, `label`, `passed`, `current_value`, `fix_title` en `fix_command` met gedetecteerde webserver-user en octale rechten.
  - `?route=install&step=recheck` biedt een asynchrone JSON-hercontrole zonder paginarefresh.
- **Cross-device Klembord (`ClipboardHelper.js`):**
  - Probeert `navigator.clipboard.writeText()` in HTTPS/secure contexts en valt terug op een iOS/Safari-compatibel tijdelijk `<textarea>` (`readOnly`, `contentEditable`, `font-size: 16px`, `setSelectionRange`, `execCommand('copy')`) voor lokale HTTP NAS-URL's.
- **Least-Privilege DB Provisioning (`InstallerService::provisionDatabaseAndUser()`):**
  - Maakt database en restricted runtime-user (`localhost` + `127.0.0.1`) aan met enkel `SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, DROP` op de doeldatabase; admin-credentials worden direct gewist en niet in `.env` opgeslagen.
- **Bidirectionele Wizard Navigatie (`InstallController`, `InstallerService`, `views/install.php`):**
  - Voltooide stappen in de stepper zijn klikbaar en stappen 2–4 bevatten een "← Vorige stap" knop.
  - Bestaande `.env` en `settings`-waarden worden vooringevuld; geheime velden worden gemarkeerd met `data-has-existing="1"` en behouden hun waarde als ze leeg blijven. `install_completed = 1` vergrendelt de wizard na stap 4.
- **Xtream Codes & Multi-Source EPG (`XtreamUrlBuilder`, `EpgSourceRepository`, `HttpClient`, `EpgParserService`):**
  - `XtreamUrlBuilder` genereert `m3u_plus` en `xmltv.php` URL's en detecteert Xtream-credentials uit geplakte `get.php` links.
  - `HttpClient` biedt expliciete DNS/HTTP-foutmeldingen en streaming `.gz` decompressie (`gzopen`/`gzread`).
  - `EpgParserService` itereert over actieve `epg_sources` op prioriteit met per-bron statuslogging.
- **Lokale Bestandscaching (`FeedCacheService`, `M3uParserService`, `EpgParserService`, `DashboardController`, `DashboardPage.js`):**
  - Atomische opslag in `storage/cache/latest_playlist.m3u` en `latest_epg.xml` met `.meta.json` en `cache_ttl_minutes`.
  - Ondersteunt `auto`, `cache_only` en `force_download` modi via UI en `bin/sync.php --use-cache`.
- **Sync Log Leegmaken & UI Error Handling (`SyncLogRepository`, `DashboardController`, `App.js`):**
  - `SyncLogRepository::clearAll()` en `DashboardController::clearSyncLogs()` (`?route=api&endpoint=clear_sync_logs`).
  - `App.js::switchTab()` vangt API/JS-fouten af en toont een leesbare foutmelding in plaats van een leeg scherm; `SettingsController::get()` vangt ontbrekende `epg_sources` tabel op.
- **Latest-Only Delta Migratie (`SchemaMigrator.php`, `database/update.sql`):**
  - `SchemaMigrator::migrateIfNeeded()` vergelijkt `hash_file('sha256', 'database/update.sql')` met `last_update_sql_hash` in `settings` en voert `update.sql` idempotent uit bij wijzigingen.
- **Cross-Device Cache Verharding (`FeedCacheService.php`):**
  - `clearstatcache(true, $path)` vóór `is_file()`/`filesize()` en 0-byte validatie na `rename()`/`copy()`+`unlink()` fallback.
- **Runtime MEDIUMTEXT Migratie & Pre-Insert Guard (`SchemaMigrator`, `M3uParserService`, `EpgParserService`, `ChannelRepository`):**
  - `SchemaMigrator::ensureSchemaUpToDate()` bevraagt `INFORMATION_SCHEMA.COLUMNS` vóór elke M3U/EPG-sync en voert direct `ALTER TABLE ... MODIFY COLUMN ... MEDIUMTEXT NULL` uit indien nodig.
  - `iconv('UTF-8', 'UTF-8//IGNORE', ...)` + 16MB/250-char guards voorkomen `SQLSTATE[22001]` definitief.
- **Real-Time Sync Voortgang & Non-Blocking Polling (`SyncProgressService`, `DashboardController`, `DashboardPage.js`):**
  - `SyncProgressService` schrijft atomisch naar `storage/cache/sync_progress.json`; `DashboardController::sync()` en `syncStatus()` roepen `session_write_close()` aan voor parallelle `800ms` polling.
  - `UNIQUE KEY uq_epg_programmes (epg_id, start_utc)` + `INSERT IGNORE` voorkomt dubbele EPG-programma's.
- **Land-Tags & Geïsoleerde M3U/EPG Cache (`CountryTagService`, `CountryTagRepository`, `ChannelRepository`, `ChannelsPage.js`):**
  - `CountryTagService` converteert ISO-2 codes naar vlag-emoji's, scant onbekende `[TAG]` prefixen en past vlaggen/landgroepen toe op bestaande kanalen.
  - `ChannelRepository::filter()` ondersteunt `country_tag_id`, `status` (`enabled`/`disabled`) en `has_epg` (`yes`/`no`).
- **Multi-File Cachebeheer (`FeedCacheService`, `CacheController`, `CacheManagerPage.js`):**
  - `FeedCacheService` bewaart downloads met unieke datum/bron-bestandsnamen + `.meta.json` (`listCacheFiles()`, `resolveFilePath()`, `renameLabel()`, `deleteBulk()`).
  - `CacheController` biedt `cache_list`, `cache_import`, `cache_rename` en `cache_delete` endpoints.
- **Safari/WebKit API & JSON Guard (`ApiClient.js`, `BaseController.php`):**
  - `ApiClient` bouwt URL's op via `new URL('index.php', window.location.href)` en leest eerst `res.text()` vóór `JSON.parse()`.
  - `BaseController::jsonSuccess()`/`jsonError()` schonen de output buffer (`ob_clean()`) op vóór JSON-headers.
- **Asset Cache-Busting & WebKit Diagnostiek (`views/*.php`, `ApiClient.js`):**
  - `?v=filemtime(...)` op `app.css`, `App.js` en `InstallerPage.js` voorkomt verouderde iPad Safari caches.
  - `ApiClient` leidt de base-URL af uit `window.location.origin + window.location.pathname` en vermeldt `endpoint (requestUrl)` bij fouten.
- **Globale API Exception Handler (`index.php`):**
  - Vangt alle `\Throwable` fouten op tijdens `?route=api`, logt naar `storage/logs/app.log` en retourneert altijd geldige JSON met bestandsnaam en regelnummer.
- **Cache Status Guard (`FeedCacheService.php`):**
  - `listCacheFiles()` forceert `$meta['filename'] = $filename` en `getCacheInfo()` vangt ongeldige/ontbrekende bestandsnamen af zonder exception.
- **Zelfherstellende SchemaMigrator (`SchemaMigrator.php`, `index.php`):**
  - `ensureSchemaUpToDate()` splitst `database/schema.sql` (na strippen van SQL-comments) en voert alle `CREATE TABLE IF NOT EXISTS` en `INSERT IGNORE` statements idempotent uit; slaat DDL over zodra `epg_sources`/`country_tags` bestaan en `schema_integrity_v2 = 1`.
  - `register_shutdown_function()` in `index.php` vangt fatale PHP-fouten op en retourneert HTTP 500 JSON.

## Custom Groepen & Kanaalbeheer
- `database/schema.sql`, `database/update.sql`, `app/Core/SchemaMigrator.php`: kolommen `is_custom`, `source_group_id`, `sync_diff_json` op `m3u_groups` en nieuwe tabel `custom_group_channels`.
- `app/Repositories/CustomGroupRepository.php`: CRUD, conversie, kanaalkoppeling, `normalizeSortOrder()`, `reorderChannelInCustomGroup()` en `reorderCustomGroupBySort()`.
- `app/Services/CustomGroupSyncService.php` & `app/Services/M3uParserService.php`: sync-diff evaluatie en bescherming van custom groepen.
- `app/Repositories/ChannelRepository.php` & `app/Controllers/ChannelController.php`: `searchCompatibleChannels()`, `custom_group_*` endpoints, `bulkChannels()`, `updateChannel()` en `groupChannelTools()` voor custom groepen.
- `assets/js/components/CustomGroupAvailableChannels.js`, `CustomGroupSyncBanner.js`, `ChannelTable.js`, `GroupList.js`, `ChannelsPage.js`, `assets/css/components.css`: UI voor custom groepen, compacte `+`/`−` icon-knoppen, en 10-rijen scrollbare database-zoeker.
