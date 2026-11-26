# IPTV & EPG Manager (PHP 8.2+ / MariaDB / QNAP NAS)

Modulaire, objectgeoriënteerde IPTV & EPG Manager zonder externe dependencies, geoptimaliseerd voor QNAP NAS (Web Station / Apache / Nginx + PHP-CLI).

## Installatie
1. Plaats de bestanden in je webroot (bijv. `/share/Web/iptv`).
2. Zorg dat je webserver naar de projectroot wijst (`index.php` + `.htaccess`).
3. Open `http://<nas-ip>/iptv/index.php?route=install` en doorloop de 4-staps **Web Installer**:
   - **Systeemcontrole:** PHP >= 8.2, `pdo_mysql`, `xmlreader`, `curl`, `openssl`, schrijfrechten.
   - **Database & Secrets:** Maakt `.env` aan met een 64-char hex `APP_KEY` en importeert `database/schema.sql`.
   - **Admin Account:** Maakt de eerste beheerder aan.
   - **Bronnen & Planning:** Slaat M3U & EPG URLs versleuteld (AES-256-GCM) op.

## QNAP Crontab Setup
Voeg een uurlijkse cronjob toe in `/etc/config/crontab` (en herlaad met `crontab /etc/config/crontab && /etc/init.d/crond.sh restart`):

```bash
# Optie A: QNAP Apache PHP-CLI
0 * * * * /mnt/ext/opt/apache/bin/php /share/Web/iptv/bin/sync.php >/dev/null 2>&1

# Optie B: Entware PHP-CLI
0 * * * * /opt/bin/php /share/Web/iptv/bin/sync.php >/dev/null 2>&1

# Optie C: HTTP Fallback via curl
0 * * * * curl -s "http://127.0.0.1/iptv/index.php?route=cron&token=JOUW_TOKEN" >/dev/null 2>&1
```

## LAN Client URLs (TiviMate / Kodi / VLC)
- **M3U Playlist:** `http://<nas-ip>/iptv/index.php?route=playlist&token=<OUTPUT_TOKEN>`
- **XMLTV EPG:** `http://<nas-ip>/iptv/index.php?route=epg&token=<OUTPUT_TOKEN>`

## Ontwikkeling & Updatebeleid
- Zie [`ARCHITECTURE.md`](ARCHITECTURE.md) voor het gedetailleerde technische architectuuroverzicht van backend, database en frontend.
- Zie [`AGENTS.md`](AGENTS.md) voor de architectuurcontracten, security-standaarden en code-richtlijnen.
- **Database-updates:** Nieuwe installaties gebruiken `database/schema.sql`. Bestaande QNAP-installaties gebruiken `database/update.sql` (Latest-Only Delta beleid) en eventuele instructies in `MANUALUPDATES.md`.


## Recente Functies
- **EPG-LINKS Toggle & Compacte Groepsheader:** Per groep instelbaar via de groene/neutrale `EPG-LINKS` knop in Groepen & Kanalen.
- **EPG Zender Koppeling:** Start met een lege groepsselectie, filtert strikt op `Live TV` en `show_in_epg = 1`, en markeert `close_match`-suggesties met een directe `✓ Bevestig`-knop.
- **QNAP Crontab via SSH:** Schrijft duplicaatvrij naar `/etc/config/crontab` via OpenSSH `SSH_ASKPASS` + PHP `hex2bin` met directe statusfeedback.
