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

        const cronCmd = `0 * * * * /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php >/dev/null 2>&1`;

        const sourceMode = s.source_mode === 'separate_urls' ? 'separate_urls' : 'xtream';

        this.container.innerHTML = `
            <div class="card">
                <h2>Bronnen & Automatisering</h2>
                <form id="settingsForm" class="stack-form">
                    <div class="source-mode-selector" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                        <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                            <input type="radio" name="source_mode" value="xtream" ${sourceMode === 'xtream' ? 'checked' : ''}>
                            <span><strong>Xtream Codes API</strong> (M3U + EPG automatisch)</span>
                        </label>
                        <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                            <input type="radio" name="source_mode" value="separate_urls" ${sourceMode === 'separate_urls' ? 'checked' : ''}>
                            <span><strong>Aparte M3U & EPG links</strong></span>
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

                    <div class="grid-2">
                        <label>Sync Interval (uren)
                            <input type="number" id="settingIntervalHours" value="${s.sync_interval_hours}" min="1" max="720" required>
                        </label>
                        <label>Voorkeursuur (0-23)
                            <input type="number" id="settingPreferredHour" value="${s.sync_preferred_hour}" min="0" max="23" required>
                        </label>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" id="settingEnforceLan" ${s.enforce_lan_ip ? 'checked' : ''}>
                        <span>Beperk uitgaande feeds tot lokale LAN-subnets</span>
                    </label>
                    <button type="submit" class="btn btn-primary">Instellingen Opslaan</button>
                </form>
            </div>

            <div class="card">
                <h2>Beveiliging & QNAP Crontab</h2>
                <div class="endpoint-row">
                    <label>Uitgaand Feed Token</label>
                    <div class="copy-group">
                        <input type="text" id="settingOutputToken" value="${this.escapeHtml(s.output_token)}" readonly>
                        <button class="btn" id="regenTokenBtn">Regenereer Token</button>
                    </div>
                </div>
                <div class="endpoint-row" style="margin-top:1rem;">
                    <label>QNAP Crontab (elke uur controleren)</label>
                    <div class="copy-group">
                        <input type="text" id="qnapCronInput" value="${this.escapeHtml(cronCmd)}" readonly>
                        <button class="btn" id="copyCronBtn">Kopieer</button>
                    </div>
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
            });
            this.props.toast.show('Instellingen opgeslagen!');
            this.load();
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
    }
}
