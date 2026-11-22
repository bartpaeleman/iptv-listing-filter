# Detailed Changes

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
