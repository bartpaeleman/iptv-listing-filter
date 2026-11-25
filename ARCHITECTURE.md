# ARCHITECTURE.md — Technische Architectuur & Codebase Overzicht

Dit document beschrijft de actuele technische architectuur van de **IPTV & EPG Manager** (PHP 8.2+ / MariaDB / Vanilla ES6+ JS). Het dient als directe referentie voor ontwikkelaars en AI-agents bij het voorbereiden en uitvoeren van nieuwe features.

---

## 1. Projectstructuur & Root-isolatie

Enkel `index.php` fungeert als publiek startpunt in de projectroot (`?route=dashboard|install|playlist|epg|cron|api`). Interne mappen zijn afgeschermd via `.htaccess`.

```text
/
├── index.php                  # Enige publieke entrypoint & router
├── .env                       # Secrets & DB-config (niet in git)
├── app/
│   ├── Core/                  # Database, BaseController, BaseRepository, SchemaMigrator, Env
│   ├── Security/              # Auth, Csrf, CryptoService (AES-256-GCM), LanGuard
│   ├── Repositories/          # PDO data-access per entiteit (1 class per bestand)
│   ├── Services/              # Businesslogica (M3U, XMLTV/EPG, EmojiTagService, Sync)
│   └── Controllers/           # HTTP/API-controllers (JSON responses, auth & CSRF checks)
├── assets/
│   ├── css/                   # Moderne CSS3 (Variables, Grid/Flexbox, mobile-first 44x44px touch)
│   └── js/
│       ├── core/              # BaseComponent.js, ApiClient.js, Debounce.js, Toast.js
│       ├── components/        # Herbruikbare UI-componenten (GroupList, ChannelTable, etc.)
│       ├── pages/             # Pagina-controllers (ChannelsPage, EpgPage, EmojiRulesPage, etc.)
│       └── App.js             # Hoofdapplicatie & tab-routing
├── bin/
│   └── sync.php               # CLI-script voor QNAP crontab synchronisatie
├── database/
│   ├── schema.sql             # Volledige actuele tabelstructuur (schone installatie)
│   └── update.sql             # Latest-Only Delta migratie voor bestaande installaties
├── storage/
│   └── cache/                 # Runtime JSON/M3U/EPG caches (niet in git)
└── views/                     # PHP HTML-templates (dashboard, install, login)
```

---

## 2. Backend Architectuur (PHP 8.2+ Strict)

- **Strict typing:** Elk PHP-bestand gebruikt `declare(strict_types=1);` en volledige parameter-/return-type-hints (PSR-12).
- **Database & Migraties (`app/Core/SchemaMigrator.php`):**
  - `PDO` met `ATTR_EMULATE_PREPARES => false` en `utf8mb4`.
  - `SchemaMigrator::ensureSchemaUpToDate(PDO $pdo)` controleert idempotent via `INFORMATION_SCHEMA` en een versie-flag in `settings` (`schema_integrity_v5`) of tabellen/kolommen up-to-date zijn, en voert ontbrekende `ALTER TABLE`-statements uit.
  - `database/update.sql` volgt het **Latest-Only Delta** beleid: bevat uitsluitend de idempotente SQL van de meest recente schemawijziging.
- **Repositories (`app/Repositories/`):**
  - `GroupRepository`: beheert `m3u_groups` (`original_name` blijft altijd intact als sync-sleutel; `custom_name` is optioneel en valt bij `NULL` terug op `original_name`; `allWithCounts(string $sortMode)` sorteert op `import`, `asc` of `desc`).
  - `ChannelRepository`: beheert `channels` (`stream_hash` en `tvg_name` blijven intact voor sync; `custom_name` overschrijft de weergavenaam; `reorderChannelInGroup()` hernummert kanalen binnen een groep atomisch `1..N` in een PDO-transactie).
  - `EmojiRuleRepository`: beheert `emoji_rules` (`match_type`: `country` | `keyword`).
- **Services (`app/Services/`):**
  - `EmojiTagService`: parseert ruwe groep- en kanaalnamen, herkent landcodes (bijv. `|BE|`, `NL:`, `[UK]`) en optionele keyword-regels (bijv. `netflix`, `4k`), en genereert `display_name` zonder de originele naam te muteren. Gebruikt `storage/cache/emoji_rules.json` als cache (geïnvalideerd via `EmojiTagService::invalidateRulesCache()`).
- **Controllers (`app/Controllers/`):**
  - `ChannelController`: levert `groups` (inclusief `original_name`, `custom_name`, `display_name`, `group_sort_mode`), `channels` (met `sort_by`: `number`|`name`), en verwerkt `set_group_sort`, `update_group`, `bulk_groups`, `update_channel` (incl. dynamische hernummering), `bulk_channels`.
  - `EmojiRuleController`: CRUD voor `emoji_rules` met cache-invalidatie bij elke mutatie.
  - Alle muterende endpoints (`POST`/`PUT`/`DELETE`) vereisen `$this->requireAuth()` en `$this->verifyCsrf($input)`.

---

## 3. Frontend Architectuur (Vanilla ES6+ Modules, geen build-step)

- **Component-model (`assets/js/core/BaseComponent.js`):**
  - Elke UI-component erft van `BaseComponent` met `setState(partialState)`, `render()`, `bindEvents()` en `escapeHtml()`.
- **Kanalen & Groepen (`assets/js/pages/ChannelsPage.js`):**
  - Split-view met links `GroupList` (`#groupsListRoot`) en rechts `ChannelTable` (`#channelsTableRoot`).
  - **Groepen:** ondersteunen inline `custom_name` bewerking (via `✏️`-knop) met behoud van `original_name` in de `title`-tooltip; lege invoer reset `custom_name` naar `NULL`.
  - **Kanalen:** tonen de emoji/vlag-prefix als aparte `<span>` naast het `<input class="ch-name">` veld; het invoerveld bevat uitsluitend de schone naam (zonder emoji-prefix) zodat opslaan geen dubbele emoji's in `custom_name` veroorzaakt.
  - **Bulkacties:** `ChannelTable` meldt selectiewijzigingen via `onSelectionChange(count)` aan `ChannelsPage`, die de knoppen `Selectie Aan (N)` / `Selectie Uit (N)` dynamisch bijwerkt.

---

## 4. Security & Performance Principes

- **Streaming-first:** Grote M3U- en XMLTV-bestanden worden regel-voor-regel (`fgets`) of via `XMLReader` verwerkt.
- **Secrets & Encryptie:** Gevoelige URL's en tokens worden versleuteld met AES-256-GCM (`CryptoService`).
- **XSS & CSRF:** Alle externe M3U/EPG-data wordt geëscaped (`htmlspecialchars` in PHP, `escapeHtml()` in JS).

---

## 5. Verbinding met Externe Provider (Xtream & M3U)

### 5.1 Configuratie & Opslag (`SettingsController`, `SettingsRepository`)
De applicatie ondersteunt twee bronmodi via `settings.source_mode`:
1. **`xtream` (Xtream Codes API):**
   - Slaat `xtream_host`, `xtream_username` (AES-256-GCM versleuteld), `xtream_password` (AES-256-GCM versleuteld), `xtream_output_params` (default `type=m3u_plus&output=ts`) en `xtream_epg_mode` (`xtream_xmltv` of `custom_url`) op in `settings`.
   - `XtreamUrlBuilder::buildM3uPlusUrl()` en `XtreamUrlBuilder::buildXmltvUrl()` genereren automatisch `m3u_source_url` en `epg_source_url` (beide versleuteld opgeslagen).
   - `XtreamUrlBuilder::parseFromM3uUrl()` kan omgekeerd credentials extraheren uit een geplakte `get.php` URL.
2. **`separate_urls` (Aparte M3U & EPG links):**
   - Gebruikt direct `m3u_source_url` (versleuteld in `settings`) en één of meerdere actieve rijen in `epg_sources` (gesorteerd op `priority` oplopend).

### 5.2 Validatie vóór Synchronisatie (`SchedulerService::validateSourceConfiguration`)
Vóór elke download/sync controleert `SchedulerService`:
- Bij `cacheMode === 'cache_only'`: of er minimaal één lokaal M3U- of EPG-cachebestand bestaat via `FeedCacheService::getCacheInfo()`.
- Bij `source_mode === 'xtream'`: of `xtream_host`, `xtream_username` en `xtream_password` aanwezig zijn (of een fallback `m3u_source_url`).
- Bij `source_mode === 'separate_urls'`: of `m3u_source_url` gevuld is.

### 5.3 Troubleshooten Provider-verbinding
- **Fout: "Xtream Codes API is geselecteerd, maar Server URL, Gebruikersnaam of Wachtwoord ontbreekt":** Controleer `settings` op `xtream_host`, `xtream_username`, `xtream_password`. Let op: encryptie gebruikt `APP_KEY` uit `.env`; als `APP_KEY` wijzigt, zijn oude versleutelde waarden niet meer leesbaar.
- **Netwerk-/DNS-/Timeout-fouten:** `HttpClient::downloadToFile()` en `XtreamApiService::fetchJson()` gebruiken cURL. Controleer `storage/logs/app.log` en `storage/logs/sync.log` voor exacte HTTP-statuscodes of cURL-fouten.

---

## 6. Synchroniseren met Provider & Lokale Cache

### 6.1 Sync-Orkestratie (`SchedulerService::run`, `bin/sync.php`, `DashboardController::sync`)
- **Triggers:** Handmatig via UI (`DashboardController::sync()` met `ignore_user_abort(true)` en `set_time_limit(0)`), via HTTP cron (`?route=cron&token=...`) of via QNAP CLI (`bin/sync.php`).
- **Tijdvenster (alleen bij `$force === false`):** Controleert `sync_preferred_hour` (uur van de dag) en `sync_interval_hours` t.o.v. `last_m3u_sync`.
- **Volgorde:** Eerst EPG (`EpgParserService::sync()`), daarna `Database::reconnectIfNeeded()`, en vervolgens M3U/Xtream:
  - `source_mode === 'xtream' && $cacheMode !== 'cache_only'` → **`XtreamApiService::sync()`** (JSON API-calls naar `player_api.php`).
  - `source_mode === 'separate_urls'` OF `$cacheMode === 'cache_only'` → **`M3uParserService::sync()`** (streaming M3U-bestand).
- **Voortgangsmonitoring:** `SyncProgressService` schrijft atomisch naar `storage/cache/sync_progress.json`. `DashboardController::sync()` en `syncStatus()` roepen `session_write_close()` aan zodat de UI elke `800ms` non-blocking kan pollen.

### 6.2 Lokale Bestandscache (`FeedCacheService`)
- **Opslaglocatie:** `storage/cache/` met unieke bestandsnamen (`m3u_YYYY-MM-DD_HHMMSS_<source>.m3u` en `epg_YYYY-MM-DD_HHMMSS_<source>.xml`) plus een bijbehorend `.meta.json` bestand (`filename`, `type`, `label`, `created_at`, `timestamp`, `size_bytes`, `item_count`).
- **Cache-modi (`$cacheMode`):**
  - `force_download`: downloadt altijd opnieuw naar een `.tmp` bestand en slaat atomisch op via `storeFromTempFile()` (`rename()` met `copy()+unlink()` fallback en `clearstatcache()` + 0-byte validatie).
  - `auto`: gebruikt het nieuwste cachebestand als `age_minutes < cache_ttl_minutes` (default `360` min), anders nieuwe download.
  - `cache_only`: leest uitsluitend van schijf (`$cacheFilename` of nieuwste bestand uit `getCacheInfo()`), zonder provider-contact.

### 6.3 View-Cache (`ViewCacheService`)
- Slaat voorbewerkte JSON-responses op in `storage/cache/view/` (`dashboard_stats.json`, `active_groups_*.json`, `group_channels_{id}.json`) om `COUNT(*)`- en `JOIN`-queries te vermijden bij het openen van de UI.
- Wordt automatisch geïnvalideerd via `ViewCacheService::invalidateAll()` na elke M3U-sync en via gerichte `invalidateGroupsCache()` / `invalidateGroupChannels($id)` bij groeps- of kanaalmutaties.

### 6.4 Troubleshooten Sync & Cache
- **Blijvende "running" status in UI:** Controleer of `storage/cache/sync_progress.json` vastzit op `status: "running"` na een PHP timeout/crash.
- **0-byte cachefouten of lege UI:** Controleer schrijfrechten op `storage/cache/` en `storage/cache/view/` en bekijk `storage/logs/sync.log` + `sync_logs` tabel.

---

## 7. Updaten van Lokale Groepen, Kanalen & Custom Groepen

### 7.1 Provider-Groepen & Kanalen (`M3uParserService::sync`, `XtreamApiService::sync`)
- **Schema-guard:** Elke sync start met `SchemaMigrator::ensureSchemaUpToDate($this->pdo)` zodat vereiste kolommen (`MEDIUMTEXT`, `is_custom`, `category`, enz.) aanwezig zijn.
- **Groepen (`m3u_groups`):**
  - Upsert op `original_name` (de exacte `group-title` uit de M3U-feed).
  - Groepen met `is_custom = 1` worden door de provider-sync overgeslagen.
  - Nieuwe groepen starten met `is_enabled = 0`.
  - `GroupCategoryService::classifyUnlockedGroups()` classificeert groepen met `category_locked = 0` automatisch (`Live TV`, `Sport`, `Animatie`, `TV Series`, `Film`, `Muziek`, `Adult`) op basis van `group_category_rules` en ingebouwde trefwoorden.
- **Kanalen (`channels`):**
  - Delta-herkenning via `stream_hash` (SHA-256).
  - Nieuwe kanalen worden ingevoegd met `is_enabled = 0`.
  - Bestaande kanalen worden alleen geüpdatet op provider-metadata (`tvg_id`, `tvg_name`, `tvg_logo`, `group_id`, `stream_url`, `last_seen_at`); gebruikersvelden (`custom_name`, `channel_number`, `epg_channel_id`, `is_enabled`) blijven intact.
  - Verdwenen kanalen (niet meer gezien in de huidige sync) worden verwijderd (`DELETE`).

### 7.2 Custom Groepen & Koppeltabel (`CustomGroupRepository`, `CustomGroupSyncService`)
- **Structuur:** Een custom groep staat in `m3u_groups` met `is_custom = 1` en `category_locked = 1`. Kanalen worden **niet** gedupliceerd in `channels`, maar gekoppeld via `custom_group_channels` (`custom_group_id`, `channel_id`, `sort_order`, `is_ignored`).
- **Voordeel bij sync:** Omdat `custom_group_channels.channel_id` verwijst naar `channels.id`, krijgen kanalen in een custom groep automatisch bijgewerkte `stream_url`, `tvg_logo` en `epg_channel_id` na elke provider-sync.
- **Geconverteerde groepen (`source_group_id`):** Wanneer een provider-groep wordt geconverteerd naar een custom groep, wordt de originele groep op `is_enabled = 0` gezet en blijft `source_group_id` verwijzen naar de brongroep.
- **Sync-diff (`CustomGroupSyncService`):** Na elke `M3uParserService::sync()` evalueert `evaluateAllConvertedGroups()` de verschillen tussen de brongroep en `custom_group_channels` (rekening houdend met `is_ignored = 1`) en slaat `added` / `removed` kanalen op in `m3u_groups.sync_diff_json` voor de `CustomGroupSyncBanner` in de UI.

### 7.3 Troubleshooten Groepen & Kanalen
- **Kanaal verdwijnt uit custom groep na sync:** Het brongroeprecord in `channels` is door de provider verwijderd, waardoor `ON DELETE CASCADE` op `custom_group_channels.channel_id` de koppeling heeft opgeruimd.
- **Categorie wordt steeds overschreven:** Controleer of `m3u_groups.category_locked = 1` staat voor handmatig ingedeelde groepen.

---

## 8. EPG Verwerking & Koppeling (EPG Link ↔ Origineel Kanaal ↔ Custom Groep)

### 8.1 EPG Import (`EpgParserService::sync`)
- Ondersteunt meerdere bronnen uit `epg_sources` (`is_active = 1`, gesorteerd op `priority` oplopend), of fallback naar `epg_source_url` / Xtream `xmltv.php`.
- `HttpClient::downloadToFile(..., true)` ondersteunt streaming `.xml.gz` decompressie.
- `XMLReader` leest het XMLTV-bestand streaming in:
  1. Alle `<channel id="...">` tags worden geüpsert in `epg_channels` (`epg_id`, `display_name`, `icon_url`).
  2. `loadActiveLinkedEpgIds()` haalt alle `channels.epg_channel_id` waarden op die gekoppeld zijn aan kanalen in actieve groepen. **Alleen `<programme>`-blokken voor deze gekoppelde `epg_id`'s worden geïmporteerd** in `epg_programmes` (met `UNIQUE KEY (epg_id, start_utc)` tegen duplicaten).

### 8.2 EPG Koppeling (`EpgMatcherService`)
- De koppeling wordt opgeslagen op het **originele kanaalrecord** in `channels.epg_channel_id` (een soft-reference naar `epg_channels.epg_id`, zodat de koppeling behouden blijft zelfs als `epg_channels` tijdelijk leeg is).
- **Auto-matching (`findMatch` / `autoMatchFiltered`):**
  1. Exacte match van `channels.tvg_id` op `epg_channels.epg_id` (`epg_match_type = 'tvg_id'`).
  2. Genormaliseerde naam-match (`EpgMatcherService::normalizeName()` verwijdert regio/kwaliteitstags zoals `HD`, `4K`, `NL`, `|BE|`) tussen `channels.tvg_name` en `epg_channels.display_name`.
- **Relatie met Custom Groepen:** Custom groepen delen exact dezelfde `channels.id` records via `custom_group_channels`. Een EPG-koppeling op `channels.epg_channel_id` werkt dus **direct en automatisch** voor zowel de originele groep als elke custom groep waarin dat kanaal is opgenomen.
- **Uitsluiting VOD/Series:** `EpgMappingPage` filtert standaard op `live_only = 1` en sluit groepen met `category IN ('Film', 'TV Series')` uit.

### 8.3 Troubleshooten EPG
- **EPG-kanaal bestaat in XMLTV, maar er zijn geen programma's in `epg_programmes`:** Controleer of het kanaal gekoppeld is (`channels.epg_channel_id` gevuld) én of de groep/kanaal actief is vóórdat de EPG-import draait. Draai na het koppelen opnieuw EPG-sync (eventueel vanuit lokale cache).

---

## 9. Aanbieden van Lokale Feeds & Xtream Server

### 9.1 M3U & XMLTV Endpoints (`FeedController`, `PlaylistExporterService`, `EpgExporterService`)
- **M3U Playlist:** `index.php?route=playlist&token=<OUTPUT_TOKEN>`
  - Beveiligd via `NetworkGuard::verifyLanOrToken($token)` (LAN-subnet check en/of `output_token`).
  - `PlaylistExporterService::streamPlaylist()` combineert alle actieve standaardgroepen (`is_enabled = 1 AND is_custom = 0` via `channels.group_id`) en actieve custom groepen (`is_enabled = 1 AND is_custom = 1` via `custom_group_channels`), past `ChannelNameCleanerService` toe, en stuurt `url-tvg` + `x-tvg-url` mee in `#EXTM3U`.
- **XMLTV EPG:** `index.php?route=epg&token=<OUTPUT_TOKEN>`
  - Beveiligd via `NetworkGuard::verifyLanOrToken($token)`.
  - `EpgExporterService::streamXmltv($useGzip)` ondersteunt automatische `gzip`-compressie op basis van `Accept-Encoding`.

### 9.2 Lokale Xtream Codes Server (`XtreamServerController`, `XtreamServerService`)
- **Authenticatie:** Controleert `username` en `password` tegen `settings.xtream_out_username` en `settings.xtream_out_password` (of fallback naar `xtream_username`/`xtream_password`).
- **Endpoints (via `index.php` routing):**
  - `player_api.php` (`XtreamServerController::playerApi()`): ondersteunt `get_live_categories`, `get_live_streams`, `get_vod_categories`, `get_vod_streams`, `get_vod_info`, `get_series_categories`, `get_series`, `get_series_info`, `get_short_epg`, `get_simple_data_table`.
  - `get.php` (`XtreamServerController::getPlaylist()`): levert dezelfde gecombineerde M3U-playlist als `PlaylistExporterService`.
  - `xmltv.php` (`XtreamServerController::xmltv()`): levert de XMLTV-feed via `EpgExporterService`.
  - `live/...` (`XtreamServerController::liveStream()`): routeert `stream_id` (`channels.id`) naar de achterliggende `channels.stream_url`.
- **Categorie-routering:** `XtreamServerService` splitst groepen op basis van `m3u_groups.category`:
  - **Live:** `Live TV`, `Sport`, `Animatie`, `Muziek`, `Adult`
  - **VOD:** `Film`
  - **Series:** `TV Series`

### 9.3 Troubleshooten Uitgaande Feeds
- **403 Forbidden op playlist/epg:** Client zit buiten het toegestane LAN-subnet of `token` komt niet overeen met `settings.output_token`.
- **401 Unauthorized op Xtream `player_api.php`:** Controleer `xtream_out_username` / `xtream_out_password` in Instellingen.
- **Kanalen ontbreken in client:** Controleer of zowel de groep (`m3u_groups.is_enabled = 1`) als het kanaal (`channels.is_enabled = 1`) actief zijn, en of `m3u_groups.category` juist staat voor Live vs. VOD vs. Series.
