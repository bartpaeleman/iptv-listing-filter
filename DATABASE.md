# DATABASE.md — Databasestructuur, Relaties & Applicatielogica

Dit document beschrijft het volledige MariaDB/MySQL-databaseschema van de **IPTV & EPG Manager** (`utf8mb4`), inclusief alle tabellen, primaire en vreemde sleutels, relaties, indexen en de directe koppeling met de backend-services en frontend-functionaliteit.

---

## 1. Overzicht & Architectuurprincipes

- **Database Engine:** MariaDB / MySQL (`InnoDB`, `utf8mb4_unicode_ci`).
- **Prepared Statements:** Alle queries gebruiken PDO met `PDO::ATTR_EMULATE_PREPARES => false`.
- **Migratiebeleid (`SchemaMigrator`):**
  - `database/schema.sql` bevat de volledige actuele structuur voor schone installaties.
  - `database/update.sql` volgt het **Latest-Only Delta** beleid en wordt automatisch uitgevoerd wanneer de SHA-256 hash (`settings.last_update_sql_hash`) wijzigt.
  - `SchemaMigrator::ensureSchemaUpToDate()` controleert daarnaast idempotent via `INFORMATION_SCHEMA` en versie-flags in `settings` (`schema_integrity_v*`) of alle vereiste tabellen en kolommen aanwezig zijn.
- **Sync-veiligheid:** Originele provider-sleutels (`m3u_groups.original_name`, `channels.stream_hash`, `channels.tvg_name`) blijven bij elke M3U/Xtream-synchronisatie intact, zodat gebruikersaanpassingen (`is_enabled`, `custom_name`, `channel_number`, `epg_channel_id`, `category_locked` en `custom_group_channels`) behouden blijven.

---

## 2. Entiteit-Relatie Diagram (ERD)

```text
+-----------------------+          +---------------------------+
|      m3u_groups       | 1      * |         channels          |
|-----------------------|--------->|---------------------------|
| PK id                 |          | PK id                     |
| UQ original_name      |          | FK group_id -> m3u_groups |
|    custom_name        |          | UQ stream_hash            |
|    category           |          |    tvg_name / custom_name |
|    category_locked    |          |    channel_number         |
|    import_order       |          |    epg_channel_id (soft)  |
|    is_custom          |          |    is_enabled             |
| FK source_group_id ---+--+       +-------------+-------------+
|    sync_diff_json     |  |                     |
|    is_enabled         |  |                     | *
+-----------+-----------+  |       +-------------+-------------+
            ^              |       |   custom_group_channels   |
            | 1            |       |---------------------------|
            +--------------+       | PK id                     |
            |                      | FK custom_group_id (1) ---+
            +----------------------+ FK channel_id (*)         |
                                   |    sort_order             |
                                   |    is_ignored             |
                                   +---------------------------+

+-----------------------+          +---------------------------+
|     epg_channels      | 1      * |      epg_programmes       |
|-----------------------|--------->|---------------------------|
| PK id                 |  (epg_id)| PK id                     |
| UQ epg_id             |          |    epg_id                 |
|    display_name       |          |    title / description    |
|    icon_url           |          |    start_utc / stop_utc   |
+-----------------------+          | UQ (epg_id, start_utc)    |
                                   +---------------------------+

+-----------------------+   +-----------------------+   +-----------------------+
|      epg_sources      |   |      emoji_rules      |   | group_category_rules  |
|-----------------------|   |-----------------------|   |-----------------------|
| PK id                 |   | PK id                 |   | PK id                 |
|    name / url         |   |    match_type         |   |    pattern            |
|    priority           |   |    pattern / emoji    |   |    category           |
|    is_active          |   |    is_active          |   |    is_active          |
+-----------------------+   +-----------------------+   +-----------------------+

+-----------------------+   +-----------------------+   +-----------------------+
|         users         |   |       settings        |   |       sync_logs       |
|-----------------------|   |-----------------------|   |-----------------------|
| PK id                 |   | PK setting_key        |   | PK id                 |
| UQ username           |   |    setting_value      |   |    sync_type / status |
|    password_hash      |   |    is_encrypted       |   |    items_processed    |
+-----------------------+   +-----------------------+   +-----------------------+
```

---

## 3. Tabelspecificaties & Functionaliteit

### 3.1 `m3u_groups` (Provider- & Custom Groepen)
Bewaart zowel de groepen die uit de M3U/Xtream-bron worden geïmporteerd als door de gebruiker aangemaakte of geconverteerde custom groepen.

| Kolom | Type | Constraints / Default | Beschrijving & Applicatielogica |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Unieke interne groeps-ID. |
| `original_name` | `VARCHAR(255)` | `NOT NULL, UNIQUE` | Exacte `group-title` uit de provider-feed (bij custom groepen een unieke `custom_<uniqid>` sleutel). Wordt nooit overschreven door de gebruiker. |
| `custom_name` | `VARCHAR(255)` | `NULL` | Optionele eigen weergavenaam. Bij `NULL` valt de UI en export terug op `original_name`. |
| `category` | `VARCHAR(32)` | `NOT NULL DEFAULT 'Live TV'` | Content-classificatie: `Live TV`, `Sport`, `Animatie`, `Muziek`, `Adult` (Live streams), `Film` (VOD) of `TV Series` (Series). |
| `category_locked` | `TINYINT(1)` | `NOT NULL DEFAULT 0` | `1` = handmatig gekozen categorie (of custom groep); `GroupCategoryService` overschrijft deze niet tijdens sync. |
| `import_order` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Oorspronkelijke volgorde uit de M3U-playlist. |
| `is_custom` | `TINYINT(1)` | `NOT NULL DEFAULT 0` | `0` = standaard provider-groep; `1` = zelfgemaakte of geconverteerde custom groep. |
| `source_group_id` | `INT UNSIGNED` | `NULL` | Verwijst naar `m3u_groups.id` van de originele provider-groep wanneer die groep is geconverteerd naar een custom groep. |
| `sync_diff_json` | `MEDIUMTEXT` | `NULL` | JSON-overzicht (`added` / `removed` kanalen) dat door `CustomGroupSyncService` wordt gevuld na een provider-sync bij geconverteerde groepen. |
| `is_enabled` | `TINYINT(1)` | `NOT NULL DEFAULT 0` | `1` = actief en meegenomen in M3U- en Xtream-exports. Nieuwe provider-groepen starten standaard op `0`. |
| `created_at` / `updated_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Tijdstempels van aanmaak en laatste wijziging. |

* **Koppeling met functionaliteit:**
  - **Groepen & Kanalen (`ChannelsPage`):** Links staan de actieve groepen (`is_enabled = 1`); rechts in *Lijst groepen* staan de provider-groepen (`is_custom = 0`) om te activeren of in te zien.
  - **Xtream Server (`XtreamServerService`):** Routeert groepen op basis van `category` naar Live TV (`Live TV`, `Sport`, `Animatie`, `Muziek`, `Adult`), VOD (`Film`) of Series (`TV Series`).
  - **EPG Koppeling (`EpgMappingPage`):** Sluit groepen met `category IN ('Film', 'TV Series')` automatisch uit (`live_only = 1`).

---

### 3.2 `channels` (Kanaal- & Streamrecords)
Bevat alle streams uit de provider-feed.

| Kolom | Type | Constraints / Default | Beschrijving & Applicatielogica |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Unieke kanaal-ID (tevens `stream_id` in de uitgaande Xtream API). |
| `group_id` | `INT UNSIGNED` | `NOT NULL, FK -> m3u_groups(id)` | Verwijst naar de oorspronkelijke provider-groep (`ON DELETE CASCADE`). |
| `stream_hash` | `CHAR(64)` | `NOT NULL, UNIQUE` | SHA-256 hash voor delta-herkenning tijdens `M3uParserService::sync()`. |
| `tvg_id` | `VARCHAR(255)` | `NULL` | Originele `tvg-id` uit de M3U-feed. |
| `tvg_name` | `VARCHAR(255)` | `NOT NULL` | Originele kanaalnaam van de provider (bevat vaak regio- en kwaliteitstags zoals `BE|`, `HD`, `4K`). |
| `custom_name` | `VARCHAR(255)` | `NULL` | Optionele eigen naam (via inline edit of *Zoek & Vervang*). Bij `NULL` wordt `tvg_name` gebruikt. |
| `tvg_logo` | `MEDIUMTEXT` | `NULL` | URL naar het zenderlogo (`MEDIUMTEXT` voorkomt truncatie bij lange CDN-URL's). |
| `stream_url` | `MEDIUMTEXT` | `NOT NULL` | Bron-URL van de stream bij de provider. |
| `channel_number` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Volgnummer (`1..N`) binnen de provider-groep (`ChannelRepository::reorderChannelInGroup()`). |
| `epg_channel_id` | `VARCHAR(255)` | `NULL, INDEX` | Gekoppelde XMLTV kanaal-ID (soft-reference naar `epg_channels.epg_id`, blijft behouden als EPG tijdelijk leeg is). |
| `is_enabled` | `TINYINT(1)` | `NOT NULL DEFAULT 0` | `1` = actief kanaal; `0` = uitgeschakeld. |
| `created_at` / `updated_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Tijdstempels. |

---

### 3.3 `custom_group_channels` (Koppeltabel voor Custom Groepen)
Koppelt bestaande kanalen uit `channels` aan één of meerdere custom groepen in `m3u_groups` (`is_custom = 1`), zonder het originele kanaalrecord te dupliceren of te verplaatsen.

| Kolom | Type | Constraints / Default | Beschrijving & Applicatielogica |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Unieke koppelings-ID. |
| `custom_group_id` | `INT UNSIGNED` | `NOT NULL, FK -> m3u_groups(id)` | Verwijst naar de custom groep (`ON DELETE CASCADE`). |
| `channel_id` | `INT UNSIGNED` | `NOT NULL, FK -> channels(id)` | Verwijst naar het gekoppelde kanaal (`ON DELETE CASCADE`). |
| `sort_order` | `INT UNSIGNED` | `NOT NULL DEFAULT 0` | Groep-specifiek volgnummer (`1..N`), volledig onafhankelijk van `channels.channel_number` in de originele groep. |
| `is_ignored` | `TINYINT(1)` | `NOT NULL DEFAULT 0` | `1` = nieuw bronkanaal dat door de gebruiker is genegeerd bij een `CustomGroupSyncService` melding. |
| `added_at` | `DATETIME` | `CURRENT_TIMESTAMP` | Moment van toevoegen aan de custom groep. |

* **Unieke index:** `UNIQUE KEY uq_custom_group_channel (custom_group_id, channel_id)` voorkomt dat een kanaal tweemaal in dezelfde custom groep wordt geplaatst.
* **Voordeel bij sync:** Omdat `channel_id` verwijst naar `channels.id`, profiteert elk kanaal in een custom groep automatisch van bijgewerkte `stream_url`, `tvg_logo` en `epg_channel_id` na een M3U/EPG-sync.

---

### 3.4 `epg_channels` & `epg_programmes` (XMLTV Gidsdata)

#### `epg_channels`
| Kolom | Type | Constraints / Default | Beschrijving |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Interne ID. |
| `epg_id` | `VARCHAR(255)` | `NOT NULL, UNIQUE` | XMLTV `<channel id="...">` identifier. |
| `display_name` | `VARCHAR(255)` | `NOT NULL` | Leesbare zendernaam uit de XMLTV-feed (gebruikt door `EpgMatcherService` voor auto-koppeling op naam). |
| `icon_url` | `MEDIUMTEXT` | `NULL` | Optioneel EPG-icoon. |

#### `epg_programmes`
| Kolom | Type | Constraints / Default | Beschrijving |
| :--- | :--- | :--- | :--- |
| `id` | `BIGINT UNSIGNED` | `PK, AUTO_INCREMENT` | Programma-ID. |
| `epg_id` | `VARCHAR(255)` | `NOT NULL, INDEX` | Koppeling met `epg_channels.epg_id` en `channels.epg_channel_id`. |
| `title` | `VARCHAR(255)` | `NOT NULL` | Programmatitel. |
| `sub_title` | `VARCHAR(255)` | `NULL` | Afleveringstitel / ondertitel. |
| `description` | `MEDIUMTEXT` | `NULL` | Korte inhoud. |
| `start_utc` | `DATETIME` | `NOT NULL` | Starttijd in UTC. |
| `stop_utc` | `DATETIME` | `NOT NULL` | Eindtijd in UTC. |

* **Performance & Deduplicatie:** `UNIQUE KEY uq_epg_programmes (epg_id, start_utc)` voorkomt dubbele programma's bij multi-source EPG-imports; `EpgParserService` importeert bovendien uitsluitend `<programme>`-blokken voor `epg_id`'s die daadwerkelijk aan actieve kanalen gekoppeld zijn.

---

### 3.5 `epg_sources` (Multi-Source XMLTV Bronnen)
| Kolom | Type | Constraints / Default | Beschrijving |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Bron-ID. |
| `name` | `VARCHAR(120)` | `NOT NULL` | Herkenbare naam van de EPG-bron. |
| `url` | `TEXT` | `NOT NULL` | XMLTV-URL (`.xml` of `.xml.gz`). |
| `priority` | `INT UNSIGNED` | `NOT NULL DEFAULT 10` | Importvolgorde (oplopend). |
| `is_active` | `TINYINT(1)` | `NOT NULL DEFAULT 1` | `1` = actief meenemen bij EPG-synchronisatie. |

---

### 3.6 `emoji_rules` & `group_category_rules` (Classificatie & Weergavetags)

#### `emoji_rules` (Landvlaggen & Keyword-emoji's)
| Kolom | Type | Constraints / Default | Beschrijving |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Regel-ID. |
| `match_type` | `ENUM('country','keyword')` | `NOT NULL` | `country` matcht landprefixen (`|BE|`, `NL:`, `[UK]`); `keyword` matcht trefwoorden (`4K`, `SPORT`). |
| `pattern` | `VARCHAR(64)` | `NOT NULL` | Zoekpatroon of ISO-2/IPTV landcode. |
| `emoji` | `VARCHAR(32)` | `NOT NULL` | Weergegeven vlag of icoon. |
| `label` | `VARCHAR(120)` | `NOT NULL` | Omschrijving in het beheerscherm (*Landen & Tags*). |
| `is_active` | `TINYINT(1)` | `NOT NULL DEFAULT 1` | `1` = actief. |

#### `group_category_rules` (Automatische Groepsclassificatie)
| Kolom | Type | Constraints / Default | Beschrijving |
| :--- | :--- | :--- | :--- |
| `id` | `INT UNSIGNED` | `PK, AUTO_INCREMENT` | Regel-ID. |
| `pattern` | `VARCHAR(120)` | `NOT NULL` | Trefwoord in de groepsnaam (bijv. `VOD`, `SERIES`, `SPORT`). |
| `category` | `VARCHAR(32)` | `NOT NULL` | Toe te kennen categorie (`Live TV`, `Sport`, `Animatie`, `TV Series`, `Film`, `Muziek`, `Adult`). |
| `is_active` | `TINYINT(1)` | `NOT NULL DEFAULT 1` | `1` = actief tijdens `GroupCategoryService::classify()`. |

---

### 3.7 Systeem-, Beveiligings- & Loggingtabellen

1. **`users`:** Beheerdersaccounts (`id`, `username` UNIQUE, `password_hash` via Argon2id/Bcrypt, `created_at`).
2. **`settings`:** Sleutel-waarde configuratie (`setting_key` PK, `setting_value`, `is_encrypted`). Gevoelige waarden (`m3u_source_url`, `epg_source_url`, `xtream_username`, `xtream_password`, `xtream_out_password`, `output_token`) worden met **AES-256-GCM** (`CryptoService`) versleuteld opgeslagen. Bevat tevens schema-integriteitsflags (`schema_integrity_v*`, `last_update_sql_hash`, `group_sort_mode`).
3. **`sync_logs`:** Historiek van handmatige en cron-synchronisaties (`id`, `sync_type` [`m3u`|`epg`|`full`], `status` [`success`|`error`], `items_processed`, `message`, `started_at`, `finished_at`).

---

## 4. Samenvatting van Data-stromen & Relaties in de App

1. **Provider Import (`M3uParserService::sync`):**
   - Leest M3U-regels streaming in -> upsert `m3u_groups` op `original_name` (slaat groepen met `is_custom = 1` over; classificeert groepen met `category_locked = 0` via `GroupCategoryService`).
   - Upsert `channels` op `stream_hash` (`INSERT` nieuw met `is_enabled = 0`, `UPDATE` gewijzigde metadata zonder `custom_name`/`channel_number`/`epg_channel_id` te overschrijven, `DELETE` verdwenen kanalen).
   - Evalueert geconverteerde custom groepen (`source_group_id IS NOT NULL`) via `CustomGroupSyncService` en werkt `sync_diff_json` bij.
2. **Custom Groepen & Kanaalbeheer (`CustomGroupRepository` & `ChannelRepository`):**
   - Een nieuwe custom groep krijgt `is_custom = 1, category_locked = 1`.
   - Bij conversie van een provider-groep (`convertProviderGroup`) wordt de originele groep op `is_enabled = 0` gezet, alle kanalen gekopieerd naar `custom_group_channels`, en blijft `source_group_id` gekoppeld voor toekomstige sync-meldingen.
   - Toevoegen van extra kanalen aan een custom groep dwingt strikte compatibiliteit af tussen `m3u_groups.category` van doel- en brongroep (`Live` <-> `Live`, `Film` <-> `Film`, `TV Series` <-> `TV Series`).
3. **Uitgaande Feeds (`PlaylistExporterService` & `XtreamServerService`):**
   - Combineert alle actieve standaardgroepen (`is_enabled = 1 AND is_custom = 0` via `channels.group_id`) met alle actieve custom groepen (`is_enabled = 1 AND is_custom = 1` via `custom_group_channels`), past `ChannelNameCleanerService` toe bij export, en levert M3U- en Xtream-streams uit op één uniforme `stream_id` (`channels.id`).

## Recente Schema-uitbreidingen (`schema_integrity_v10`)
- `m3u_groups.show_in_epg` (`TINYINT(1) NOT NULL DEFAULT 1`): bepaalt of een groep wordt getoond in EPG Zender Koppeling (`0` voor `Film` en `TV Series`).
- `channels.epg_match_type` (`ENUM('none', 'auto', 'close_match', 'manual')`): ondersteunt onbevestigde `close_match`-suggesties naast `auto` en `manual`.
