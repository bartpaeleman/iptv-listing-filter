# Manual Updates (QNAP NAS)

Dit document beschrijft handmatige acties die beheerders op de QNAP NAS moeten uitvoeren bij een update.

## Update naar v1.1.0 (Root Front-Controller Architectuur)
1. **Webserver DocumentRoot:** Laat je QNAP Web Station / Apache VirtualHost wijzen naar de projectroot (waar `index.php` en `.htaccess` staan) in plaats van `public/`.
2. **Crontab:** Werk eventuele HTTP-cron URLs bij naar `index.php?route=cron&token=JOUW_TOKEN`. De CLI-cron (`bin/sync.php`) blijft ongewijzigd.
3. **LAN Clients (TiviMate/Kodi/VLC):** Gebruik de nieuwe URLs:
   - Playlist: `http://<nas-ip>/iptv/index.php?route=playlist&token=<OUTPUT_TOKEN>`
   - EPG: `http://<nas-ip>/iptv/index.php?route=epg&token=<OUTPUT_TOKEN>`
