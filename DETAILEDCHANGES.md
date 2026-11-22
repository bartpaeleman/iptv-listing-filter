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
