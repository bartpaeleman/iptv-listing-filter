# Manual Updates (QNAP NAS)

Dit document beschrijft handmatige acties die beheerders op de QNAP NAS moeten uitvoeren bij een update.

## Update naar v1.1.0 (Root Front-Controller Architectuur)
1. **Webserver DocumentRoot:** Laat je QNAP Web Station / Apache VirtualHost wijzen naar de projectroot (waar `index.php` en `.htaccess` staan) in plaats van `public/`.
2. **Crontab:** Werk eventuele HTTP-cron URLs bij naar `index.php?route=cron&token=JOUW_TOKEN`. De CLI-cron (`bin/sync.php`) blijft ongewijzigd.
3. **LAN Clients (TiviMate/Kodi/VLC):** Gebruik de nieuwe URLs:
   - Playlist: `http://<nas-ip>/iptv/index.php?route=playlist&token=<OUTPUT_TOKEN>`
4. **Database Migratie:** Voer `database/update.sql` uit op bestaande installaties om `epg_sources` toe te voegen en `channels.tvg_logo` / `epg_channels.icon_url` naar `TEXT` te migreren.

## Update naar Groepssortering & Dynamische Kanaalhernummering
- `SchemaMigrator::ensureSchemaUpToDate()` voert de migratie (`m3u_groups.import_order` en `settings.group_sort_mode`) automatisch uit bij het openen van het dashboard. Indien de database-gebruiker geen `ALTER TABLE`-rechten heeft, voer dan handmatig `database/update.sql` uit als DB-admin.

## Update naar Load Performance & View-Cache (`feature/rearchitecting-cached`)
- Geen database-migratie of `.env`-wijziging vereist.
- Zorg dat `storage/cache/` schrijfbaar is voor PHP (voor de automatische aanmaak van `storage/cache/view/`).

## Update naar Uitgaande Xtream Codes Server (`feature/xtream-export`)
- **QNAP Apache / Web Station (`mod_rewrite`):** Zorg dat `mod_rewrite` actief is en `AllowOverride All` is ingeschakeld voor de projectmap, zodat `.htaccess` de standaard Xtream client-URL's (`/player_api.php`, `/get.php`, `/xmltv.php`, `/live/{user}/{pass}/{id}.{ts|m3u8}`) correct doorstuurt naar `index.php`.
- **Inloggegevens:** Configureer onder **Instellingen** de uitgaande Xtream Codes gebruikersnaam en wachtwoord (AES-256-GCM versleuteld opgeslagen) en activeer de server.

## Update naar Gesplitste M3U & EPG Cronjobs (`feature/cronjob-split`)
- Geen database-migratie of `.env`-wijziging vereist.
- **Crontab opschonen (optioneel):** Als er nog een oude gecombineerde `bin/sync.php --force` regel in `/etc/config/crontab` staat, verwijder deze eenmalig en stel via **Instellingen → ⏱️ Automatisering & Crontab** de twee aparte jobs (`📺 M3U Kanalen Cronjob` en `📅 EPG Gids Cronjob`) opnieuw in.
