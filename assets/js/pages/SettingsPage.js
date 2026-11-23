import { BaseComponent } from '../core/BaseComponent.js';

export class SettingsPage extends BaseComponent {
    async load() {
        const s = await this.props.api.get('get_settings');
        this.setState({ settings: s });
    }

    render() {
        const s = this.state.settings;
        if (!s) {
            this.container.innerHTML = '<div class="card">Laden...</div>';
            return;
        }

        const base = window.location.origin + window.location.pathname.replace(/\/index\.php$/, '');
        const tok = s.output_token ? `&token=${encodeURIComponent(s.output_token)}` : '';
        const playlistUrl = `${base}/index.php?route=playlist${tok}`;
        const epgUrl = `${base}/index.php?route=epg${tok}`;
        const initCron = this.buildCronSchedule(Number(s.sync_interval_hours || 72), Number(s.sync_preferred_hour || 3));
        const cronCmd = `${initCron.expr} /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php >/dev/null 2>&1`;

        const sourceMode = s.source_mode === 'separate_urls' ? 'separate_urls' : 'xtream';

        this.container.innerHTML = `
            <form id="settingsForm" class="stack-form">
                <details class="collapsible-card">
                    <summary class="collapsible-card__header">
                        <span>📡 Bronnen &amp; Import</span>
                        <span class="collapsible-card__chevron">▼</span>
                    </summary>
                    <div class="collapsible-card__body stack-form">
                        <div class="source-mode-selector" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                            <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                <input type="radio" name="source_mode" value="xtream" ${sourceMode === 'xtream' ? 'checked' : ''}>
                                <span><strong>Xtream Codes API</strong> (M3U + EPG automatisch)</span>
                            </label>
                            <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                <input type="radio" name="source_mode" value="separate_urls" ${sourceMode === 'separate_urls' ? 'checked' : ''}>
                                <span><strong>Aparte M3U &amp; EPG links</strong></span>
                            </label>
                        </div>

                        <div id="xtreamFields" class="${sourceMode === 'xtream' ? '' : 'hidden'}" style="display:flex;flex-direction:column;gap:0.75rem;">
                            <label>Server URL
                                <input type="url" id="settingXtreamHost" value="${this.escapeHtml(s.xtream_host || '')}" placeholder="https://example.com:8080">
                            </label>
                            <div class="grid-2">
                                <label>Gebruikersnaam
                                    <input type="text" id="settingXtreamUser" placeholder="${this.escapeHtml(s.xtream_username_masked || 'Gebruikersnaam (laat leeg om te behouden)')}">
                                </label>
                                <label>Wachtwoord
                                    <input type="password" id="settingXtreamPass" placeholder="${s.xtream_password_set ? '•••••••• (laat leeg om te behouden)' : 'Wachtwoord'}">
                                </label>
                            </div>
                            <label>Output-parameters (optioneel)
                                <input type="text" id="settingXtreamOutputParams" value="${this.escapeHtml(s.xtream_output_params || 'type=m3u_plus&output=ts')}" placeholder="type=m3u_plus&output=ts">
                            </label>
                            <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.25rem;">
                                <span style="font-size:0.9rem;font-weight:600;">EPG-bron binnen Xtream</span>
                                <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                                    <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                        <input type="radio" name="xtream_epg_mode" value="xtream_xmltv" ${(s.xtream_epg_mode || 'xtream_xmltv') === 'xtream_xmltv' ? 'checked' : ''}>
                                        <span>Standaard Xtream XMLTV (<code>/xmltv.php</code>)</span>
                                    </label>
                                    <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                        <input type="radio" name="xtream_epg_mode" value="custom_url" ${s.xtream_epg_mode === 'custom_url' ? 'checked' : ''}>
                                        <span>Eigen aangepaste EPG-URL</span>
                                    </label>
                                </div>
                                <div id="xtreamCustomEpgWrap" class="${s.xtream_epg_mode === 'custom_url' ? '' : 'hidden'}">
                                    <label>Aangepaste EPG Bron URL (laat leeg om huidige te behouden)
                                        <input type="url" id="settingXtreamCustomEpgUrl" placeholder="${this.escapeHtml(s.xtream_custom_epg_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml')}">
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="separateUrlFields" class="${sourceMode === 'separate_urls' ? '' : 'hidden'}" style="display:flex;flex-direction:column;gap:0.75rem;">
                            <label>M3U Bron URL (laat leeg om huidige te behouden)
                                <input type="url" id="settingM3uUrl" placeholder="${this.escapeHtml(s.m3u_source_url_masked || 'https://...')}">
                            </label>
                            <label>EPG Bron URL (laat leeg om huidige te behouden)
                                <input type="url" id="settingEpgUrl" placeholder="${this.escapeHtml(s.epg_source_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml')}">
                            </label>
                        </div>
                    </div>
                </details>

                <details class="collapsible-card">
                    <summary class="collapsible-card__header">
                        <span>⏱️ Automatisering &amp; QNAP Crontab</span>
                        <span class="collapsible-card__chevron">▼</span>
                    </summary>
                    <div class="collapsible-card__body stack-form">
                        <div class="grid-2">
                            <label>Sync Interval (uren)
                                <input type="number" id="settingIntervalHours" value="${s.sync_interval_hours}" min="1" max="720" required>
                            </label>
                            <label>Voorkeursuur (0-23)
                                <input type="number" id="settingPreferredHour" value="${s.sync_preferred_hour}" min="0" max="23" required>
                            </label>
                        </div>
                        <div class="endpoint-row" style="margin-top:0.5rem;">
                            <label>QNAP Crontab (<span id="qnapCronLabel">${this.escapeHtml(initCron.label)}</span>)</label>
                            <div class="copy-group">
                                <input type="text" id="qnapCronInput" value="${this.escapeHtml(cronCmd)}" readonly>
                                <button type="button" class="btn" id="copyCronBtn">Kopieer</button>
                            </div>
                        </div>
                    </div>
                </details>

                <details class="collapsible-card">
                    <summary class="collapsible-card__header">
                        <span>🔗 Uitgaande Feeds &amp; Xtream Server (IPTVX / TiviMate)</span>
                        <span class="collapsible-card__chevron">▼</span>
                    </summary>
                    <div class="collapsible-card__body stack-form">
                        <label class="checkbox-row">
                            <input type="checkbox" id="settingEnforceLan" ${s.enforce_lan_ip ? 'checked' : ''}>
                            <span>Beperk uitgaande feeds tot lokale LAN-subnets</span>
                        </label>
                        <div class="endpoint-row">
                            <label>Uitgaand Feed Token</label>
                            <div class="copy-group">
                                <input type="text" id="settingOutputToken" value="${this.escapeHtml(s.output_token)}" readonly>
                                <button type="button" class="btn" id="regenTokenBtn">Regenereer Token</button>
                            </div>
                        </div>
                        <div class="grid-2">
                            <div class="endpoint-row">
                                <label>M3U Playlist URL</label>
                                <div class="copy-group">
                                    <input type="text" id="settingPlaylistUrl" value="${this.escapeHtml(playlistUrl)}" readonly>
                                    <button type="button" class="btn copy-setting-btn" data-copy="settingPlaylistUrl">Kopieer</button>
                                </div>
                            </div>
                            <div class="endpoint-row">
                                <label>XMLTV EPG URL</label>
                                <div class="copy-group">
                                    <input type="text" id="settingEpgUrlOut" value="${this.escapeHtml(epgUrl)}" readonly>
                                    <button type="button" class="btn copy-setting-btn" data-copy="settingEpgUrlOut">Kopieer</button>
                                </div>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border-color, #2a2f3a);margin:0.5rem 0;">
                        <h3 style="margin:0;font-size:1.05rem;">Uitgaande Xtream Codes Server (IPTVX / TiviMate)</h3>
                        <label class="checkbox-row">
                            <input type="checkbox" id="settingXtreamOutEnabled" ${s.xtream_out_enabled ? 'checked' : ''}>
                            <span>Activeer uitgaande Xtream Codes API server</span>
                        </label>
                        <div class="endpoint-row">
                            <label>Server URL</label>
                            <div class="copy-group">
                                <input type="text" id="settingXtreamServerUrl" value="${this.escapeHtml(base.replace(/\/+$/, ''))}" readonly>
                                <button type="button" class="btn copy-setting-btn" data-copy="settingXtreamServerUrl">Kopieer</button>
                            </div>
                        </div>
                        <div class="grid-2">
                            <label>Uitgaande Gebruikersnaam
                                <input type="text" id="settingXtreamOutUser" placeholder="${this.escapeHtml(s.xtream_out_username_masked || 'Gebruikersnaam (laat leeg om te behouden)')}">
                            </label>
                            <label>Uitgaand Wachtwoord
                                <input type="password" id="settingXtreamOutPass" placeholder="${s.xtream_out_password_set ? '•••••••• (laat leeg om te behouden)' : 'Wachtwoord'}">
                            </label>
                        </div>
                        <div>
                            <button type="button" class="btn" id="genXtreamOutCredsBtn" style="min-height:44px;">🔑 Genereer Sterke Inloggegevens</button>
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-primary" style="min-height:44px;">Instellingen Opslaan</button>
            </form>

            <div class="card" style="margin-top:1rem;">
                <h2>Database Onderhoud</h2>
                <p class="muted" style="margin-bottom:1rem;">Wis geïmporteerde M3U- of EPG-tabellen veilig zonder instellingen, gebruikers, emoji-regels of EPG-bronnen te verwijderen.</p>
                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <button type="button" class="btn btn-danger" id="clearM3uTablesBtn" style="min-height:44px;">🗑️ Wis M3U Kanalen &amp; Groepen</button>
                    <button type="button" class="btn btn-danger" id="clearEpgTablesBtn" style="min-height:44px;">🗑️ Wis EPG Data</button>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('input[name="source_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const mode = this.container.querySelector('input[name="source_mode"]:checked')?.value || 'xtream';
                document.getElementById('xtreamFields')?.classList.toggle('hidden', mode !== 'xtream');
                document.getElementById('separateUrlFields')?.classList.toggle('hidden', mode !== 'separate_urls');
            });
        });

        this.container.querySelectorAll('input[name="xtream_epg_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const epgMode = this.container.querySelector('input[name="xtream_epg_mode"]:checked')?.value || 'xtream_xmltv';
                document.getElementById('xtreamCustomEpgWrap')?.classList.toggle('hidden', epgMode !== 'custom_url');
            });
        });

        document.getElementById('settingsForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            const sourceMode = this.container.querySelector('input[name="source_mode"]:checked')?.value || 'xtream';
            const xtreamEpgMode = this.container.querySelector('input[name="xtream_epg_mode"]:checked')?.value || 'xtream_xmltv';
            await this.props.api.post('save_settings', {
                source_mode: sourceMode,
                xtream_host: document.getElementById('settingXtreamHost')?.value || '',
                xtream_username: document.getElementById('settingXtreamUser')?.value || '',
                xtream_password: document.getElementById('settingXtreamPass')?.value || '',
                xtream_output_params: document.getElementById('settingXtreamOutputParams')?.value || 'type=m3u_plus&output=ts',
                xtream_epg_mode: xtreamEpgMode,
                xtream_custom_epg_url: document.getElementById('settingXtreamCustomEpgUrl')?.value || '',
                m3u_source_url: document.getElementById('settingM3uUrl')?.value || '',
                epg_source_url: document.getElementById('settingEpgUrl')?.value || '',
                sync_interval_hours: Number(document.getElementById('settingIntervalHours').value),
                sync_preferred_hour: Number(document.getElementById('settingPreferredHour').value),
                enforce_lan_ip: document.getElementById('settingEnforceLan').checked,
                xtream_out_enabled: document.getElementById('settingXtreamOutEnabled')?.checked || false,
                xtream_out_username: document.getElementById('settingXtreamOutUser')?.value || '',
                xtream_out_password: document.getElementById('settingXtreamOutPass')?.value || '',
            });
            this.props.toast.show('Instellingen opgeslagen!');
            this.load();
        });

        document.getElementById('genXtreamOutCredsBtn')?.addEventListener('click', () => {
            const randHex = bytes => Array.from(crypto.getRandomValues(new Uint8Array(bytes)))
                .map(b => b.toString(16).padStart(2, '0')).join('');
            const userEl = document.getElementById('settingXtreamOutUser');
            const passEl = document.getElementById('settingXtreamOutPass');
            const enabledEl = document.getElementById('settingXtreamOutEnabled');
            if (userEl) userEl.value = 'iptv_' + randHex(4);
            if (passEl) {
                passEl.type = 'text';
                passEl.value = randHex(12);
            }
            if (enabledEl) enabledEl.checked = true;
            this.props.toast.show('Nieuwe inloggegevens ingevuld. Klik op Instellingen Opslaan om te bewaren.');
        });

        document.getElementById('regenTokenBtn')?.addEventListener('click', async () => {
            const res = await this.props.api.post('regenerate_token');
            document.getElementById('settingOutputToken').value = res.output_token;
            this.props.toast.show('Nieuw token gegenereerd!');
        });

        document.getElementById('copyCronBtn')?.addEventListener('click', ev => {
            const input = document.getElementById('qnapCronInput');
            if (!input) return;
            ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
        });

        this.container.querySelectorAll('.copy-setting-btn').forEach(btn => {
            btn.addEventListener('click', ev => {
                const input = document.getElementById(btn.dataset.copy);
                if (!input) return;
                ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
            });
        });

        const updateCronPreview = () => {
            const interval = Math.max(1, parseInt(document.getElementById('settingIntervalHours')?.value || '72', 10) || 1);
            const hour = Math.max(0, Math.min(23, parseInt(document.getElementById('settingPreferredHour')?.value || '3', 10) || 0));
            const cron = this.buildCronSchedule(interval, hour);
            const labelEl = document.getElementById('qnapCronLabel');
            const inputEl = document.getElementById('qnapCronInput');
            if (labelEl) labelEl.textContent = cron.label;
            if (inputEl) inputEl.value = `${cron.expr} /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php >/dev/null 2>&1`;
        };
        document.getElementById('settingIntervalHours')?.addEventListener('input', updateCronPreview);
        document.getElementById('settingIntervalHours')?.addEventListener('change', updateCronPreview);
        document.getElementById('settingPreferredHour')?.addEventListener('input', updateCronPreview);
        document.getElementById('settingPreferredHour')?.addEventListener('change', updateCronPreview);

        document.getElementById('addEpgSourceForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_epg_source', {
                name: document.getElementById('newEpgName').value,
                url: document.getElementById('newEpgUrl').value,
                priority: Number(document.getElementById('newEpgPrio').value),
            });
            this.props.toast.show('EPG bron toegevoegd!');
            this.load();
        });

        this.container.querySelectorAll('.epg-src-toggle').forEach(cb => {
            cb.addEventListener('change', async () => {
                await this.props.api.post('toggle_epg_source', { id: Number(cb.dataset.id), is_enabled: cb.checked });
                this.props.toast.show('EPG bron bijgewerkt');
            });
        });

        this.container.querySelectorAll('.test-epg-src-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                btn.textContent = '⏳...';
                try {
                    const stats = await this.props.api.post('test_epg_source', { id: Number(btn.dataset.id) });
                    this.props.toast.show(`OK: ${stats.epg_channels} zenders`);
                    this.load();
                } catch {
                    this.load();
                }
            });
        });

        this.container.querySelectorAll('.del-epg-src-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('delete_epg_source', { id: Number(btn.dataset.id) });
                this.props.toast.show('EPG bron verwijderd');
                this.load();
            });
        });

        document.getElementById('clearM3uTablesBtn')?.addEventListener('click', () => {
            this.props.modal.confirm(
                'M3U Kanalen & Groepen wissen',
                'Weet je zeker dat je alle kanalen en groepen wilt wissen? Instellingen, gebruikers, emoji-regels, EPG-bronnen en EPG-data blijven behouden.',
                async () => {
                    const res = await this.props.api.post('clear_db_tables', { scope: 'm3u' });
                    this.props.toast.show(res.message || 'M3U data gewist!');
                    this.load();
                }
            );
        });

        document.getElementById('clearEpgTablesBtn')?.addEventListener('click', () => {
            this.props.modal.confirm(
                'EPG Data wissen',
                'Weet je zeker dat je alle EPG-programma\'s en EPG-zenders wilt wissen? Kanalen, groepen, instellingen, gebruikers, emoji-regels en EPG-bronnen blijven behouden.',
                async () => {
                    const res = await this.props.api.post('clear_db_tables', { scope: 'epg' });
                    this.props.toast.show(res.message || 'EPG data gewist!');
                    this.load();
                }
            );
        });
    }

    buildCronSchedule(interval, hour) {
        const hStr = String(hour).padStart(2, '0');
        if (interval <= 1) {
            return { expr: '0 * * * *', label: 'elke uur' };
        }
        if (interval < 24) {
            return { expr: `0 */${interval} * * *`, label: `elke ${interval} uur` };
        }
        if (interval === 24) {
            return { expr: `0 ${hour} * * *`, label: `dagelijks om ${hStr}:00` };
        }
        const days = Math.max(1, Math.round(interval / 24));
        return {
            expr: `0 ${hour} */${days} * *`,
            label: `elke ${days} dagen (${interval}u) om ${hStr}:00`,
        };
    }
}
