# AGENTS.md — Ontwikkelstandaarden & Architectuurcontracten

Dit document bevat de bindende regels voor alle AI-agents en ontwikkelaars die aan de **IPTV & EPG Manager** werken.

## 1. Tech Stack & Omgeving (QNAP NAS Target)
- **Backend:** PHP 8.2+ (`declare(strict_types=1);`), PDO MySQL/MariaDB (`utf8mb4`), `XMLReader`, `cURL`, `OpenSSL`. Geen zware frameworks of verplichte Composer-dependencies.
- **Frontend:** Semantic HTML5, Vanilla ES6+ JavaScript, moderne CSS3 (CSS Variables, Grid/Flexbox). Geen externe build-steps (Node/Webpack/Vite).
- **Streaming-first principe:** Verwerk grote M3U- en XMLTV-bestanden (`tvguide.xml`) altijd regel-voor-regel (`fgets`) of via `XMLReader`. Laad nooit volledige remote bestanden in het RAM-geheugen.

## 2. Architectuur & OOP-Standaarden
- **Root-isolatie:** Enkel `index.php` fungeert als publiek startpunt in de projectroot (`?route=dashboard|install|playlist|epg|cron|api`). Interne mappen (`app/`, `database/`, `storage/`, `bin/`, `views/`) zijn afgeschermd via `.htaccess`.
- **Strikte scheiding van verantwoordelijkheden in `app/`:** `Core/`, `Security/`, `Repositories/`, `Services/`, `Controllers/`.
- **Frontend modulariteit in `assets/js/`:** `core/`, `components/`, `pages/`, `App.js` (ES6 modules, geen build-steps).
- **Code-stijl:** PSR-12 naamgeving, expliciete type-hints op alle parameters en return-types, maximaal 1 class per bestand.

## 3. Security & Secrets Management (OWASP Top 10:2025)
- **Secrets:** `DB_PASS`, `APP_KEY` en tokens staan uitsluitend in `.env` (genegeerd in `.gitignore`). Houd `.env.example` altijd synchroon bij nieuwe configuratievariabelen.
- **Encryptie:** Gevoelige URL's (zoals geauthentiseerde IPTV M3U-links) worden in de database opgeslagen met AES-256-GCM (`CryptoService`) en in de frontend uitsluitend gemaskeerd getoond.
- **Database:** 100% PDO Prepared Statements (`PDO::ATTR_EMULATE_PREPARES => false`).
- **Auth, XSS & CSRF:** Alle muterende API-calls (`POST`/`PUT`/`DELETE`) vereisen een geldig CSRF-token. Escape alle user- en externe M3U/EPG-data met `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`. Uitgaande feeds (`playlist.php`, `epg.php`) vereisen LAN-subnet validatie en/of een geldige `output_token`.

## 4. Database & Migratiebeleid (Rolling 2-Updates)
- `database/schema.sql` bevat altijd de volledige, actuele tabellenstructuur voor schone installaties (`public/install.php`).
- `database/update.sql` bevat uitsluitend de databasewijzigingen van de **laatste 2 releases** (Rolling 2-updates beleid), idempotent geschreven (`IF NOT EXISTS`, `IF EXISTS`, kolomchecks) zodat bestaande QNAP-installaties veilig kunnen updaten zonder dataverlies.

## 5. UX & Frontend Richtlijnen
- **Mobile-first & tablet-vriendelijk (iPad/iPhone):** Interactieve knoppen, toggles en links hebben een minimale touch-target van `44x44px`.
- **Geen iOS auto-zoom:** Invoervelden (`input`, `select`, `textarea`) gebruiken minimaal `font-size: 16px`.
- **Responsieve feedback:** Gebruik debounce (`300ms`) op live zoek- en filterbalken voor kanalen en EPG-koppelingen, en toon duidelijke laad-/voortgangsstatussen bij synchronisaties.

## 6. Documentatie- & Git-Discipline
- Werk bij elke functionele wijziging of bugfix de relevante documentatie in dezelfde commit bij:
  - `CHANGELOG.md` (release-notes op basis van Keep a Changelog)
  - `DETAILEDCHANGES.md` (technische wijzigingen per bestand/class)
  - `MANUALUPDATES.md` (handmatige acties voor beheerders op de QNAP NAS, zoals nieuwe `.env` keys, crontab-aanpassingen of `update.sql` uitvoering)
  - `README.md` (bij nieuwe features, endpoints of installatiestappen)
- Voer vóór elke commit `php -l` uit op alle gewijzigde `.php` bestanden en controleer via `git status` dat `.env` en `storage/` cachebestanden niet worden meegecommit.
