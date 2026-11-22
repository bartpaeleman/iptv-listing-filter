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
