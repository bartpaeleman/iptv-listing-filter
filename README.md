# IPTV & EPG Manager (PHP 8.2+ / MariaDB / QNAP NAS)

Modulaire, objectgeoriënteerde IPTV & EPG Manager zonder externe dependencies, geoptimaliseerd voor QNAP NAS (Web Station / Apache / Nginx + PHP-CLI).

## Installatie
1. Plaats de bestanden in je webroot (bijv. `/share/Web/iptv`).
2. Zorg dat je webserver naar `public/` wijst (of open `http://<nas-ip>/iptv/public/install.php`).
3. Doorloop de 4-staps **Web Installer** (`public/install.php`):
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
0 * * * * curl -s "http://127.0.0.1/iptv/public/api.php?action=cron&token=JOUW_TOKEN" >/dev/null 2>&1
```

## LAN Client URLs (TiviMate / Kodi / VLC)
- **M3U Playlist:** `http://<nas-ip>/iptv/public/playlist.php?token=<OUTPUT_TOKEN>`
- **XMLTV EPG:** `http://<nas-ip>/iptv/public/epg.php?token=<OUTPUT_TOKEN>`

## Ontwikkeling & Updatebeleid
- Zie [`AGENTS.md`](AGENTS.md) voor de architectuurcontracten, security-standaarden en code-richtlijnen.
- **Database-updates:** Nieuwe installaties gebruiken `database/schema.sql`. Bestaande QNAP-installaties gebruiken `database/update.sql` (Rolling 2-Updates beleid) en eventuele instructies in `MANUALUPDATES.md`.

