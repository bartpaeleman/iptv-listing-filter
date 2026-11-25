import { BaseComponent } from '../core/BaseComponent.js';
import { ClipboardHelper } from '../core/ClipboardHelper.js';
import { SyncProgressPanel } from '../components/SyncProgressPanel.js';
import { SyncLogViewer } from '../components/SyncLogViewer.js';

export class DashboardPage extends BaseComponent {
    async load() {
        const d = await this.props.api.get('dashboard');
        this.setState({ data: d });
    }

    render() {
        const d = this.state.data;
        if (!d) {
            this.container.innerHTML = '<div class="card">Laden...</div>';
            return;
        }

        const base = d.app_url || window.location.origin + window.location.pathname.replace(/\/index\.php$/, '');
        const tok = d.output_token ? `&token=${encodeURIComponent(d.output_token)}` : '';
        const playlistUrl = `${base}/index.php?route=playlist${tok}`;
        const epgUrl = `${base}/index.php?route=epg${tok}`;

        this.container.innerHTML = `
            <div class="stats-grid">
                <div class="stat-card">
                    <span class="stat-label">Actieve Kanalen</span>
                    <strong class="stat-value">${d.active_channels}</strong>
                    <span class="stat-sub">van ${d.total_channels} totaal</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Actieve Groepen</span>
                    <strong class="stat-value">${d.active_groups}</strong>
                    <span class="stat-sub">van ${d.total_groups} totaal</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">EPG Gekoppeld</span>
                    <strong class="stat-value">${d.linked_epg}</strong>
                    <span class="stat-sub">${d.epg_channels_count} EPG zenders in cache</span>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Laatste Sync (UTC)</span>
                    <strong class="stat-value small">${this.escapeHtml(d.last_m3u_sync || 'Nog nooit')}</strong>
                    <span class="stat-sub">Elke ${d.sync_interval_hours}u om ${String(d.sync_preferred_hour).padStart(2, '0')}:00</span>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Synchronisatie & Lokale Cache</h2>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-sync="all" data-cache-mode="force_download">🔄 Alles Downloaden & Sync</button>
                        <button class="btn" data-sync="all" data-cache-mode="cache_only" ${!(d.cache_status?.m3u?.exists || d.cache_status?.epg?.exists) ? 'disabled' : ''}>⚡ Laad Cache</button>
                        <button class="btn" id="openCacheManagerBtn">💾 Beheer Cache</button>
                    </div>
                </div>
                <p class="muted" style="font-size:0.85rem;margin:0 0 0.5rem 0;">
                    Lokale M3U cache: ${d.cache_status?.m3u?.exists ? `${this.escapeHtml(d.cache_status.m3u.size_formatted)} — ${d.cache_status.m3u.age_minutes} min geleden` : 'Geen'}
                    &nbsp;|&nbsp;
                    Lokale EPG cache: ${d.cache_status?.epg?.exists ? `${this.escapeHtml(d.cache_status.epg.size_formatted)} — ${d.cache_status.epg.age_minutes} min geleden` : 'Geen'}
                </p>
                <div id="dashboardProgressMount"></div>
                <div id="syncStatusBanner" class="sync-banner hidden"></div>
            </div>

            <details class="collapsible-card">
                <summary class="collapsible-card__header">
                    <span>🔗 Uitgaande Client Koppelingen (M3U, EPG &amp; Xtream Server)</span>
                    <span class="collapsible-card__chevron">▼</span>
                </summary>
                <div class="collapsible-card__body">
                    <div class="card-header" style="margin-bottom:0.5rem;">
                        <p class="muted" style="font-size:0.85rem;margin:0;">vb: (IPTVX / TiviMate / Kodi / VLC)</p>
                        ${!d.xtream_out_enabled || !d.xtream_out_username ? `<button type="button" class="btn btn-sm" id="goToXtreamSettingsBtn">⚙️ XTream Instellen</button>` : ''}
                    </div>
                    <div class="grid-2">
                        <div class="endpoint-row">
                            <label>LAN Client Endpoint M3U Link</label>
                            <div class="copy-group">
                                <input type="text" id="playlistUrlInput" value="${this.escapeHtml(playlistUrl)}" readonly>
                                <button class="btn copy-btn" data-copy="playlistUrlInput">Kopieer</button>
                                <button type="button" class="btn btn-download" data-download="playlistUrlInput" data-filename="playlist.m3u">⬇️ Download</button>
                            </div>
                        </div>
                        <div class="endpoint-row">
                            <label>XMLTV EPG Link</label>
                            <div class="copy-group">
                                <input type="text" id="epgUrlInput" value="${this.escapeHtml(epgUrl)}" readonly>
                                <button class="btn copy-btn" data-copy="epgUrlInput">Kopieer</button>
                                <button type="button" class="btn btn-download" data-download="epgUrlInput" data-filename="epg.xml">⬇️ Download</button>
                            </div>
                        </div>
                    </div>

                    <hr style="border:none;border-top:1px solid var(--border-color, #2a2f3a);margin:1rem 0;">
                    <h3 style="margin:0 0 0.5rem 0;font-size:1rem;">Xtream Codes Client Login (IPTVX / TiviMate)</h3>
                    ${d.xtream_out_enabled && d.xtream_out_username && d.xtream_out_password ? `
                    <div class="grid-2">
                        <div class="endpoint-row" style="grid-column: 1 / -1;">
                            <label>Server URL</label>
                            <div class="copy-group">
                                <input type="text" id="xtreamServerUrlInput" value="${this.escapeHtml(base.replace(/\/+$/, ''))}" readonly>
                                <button class="btn copy-btn" data-copy="xtreamServerUrlInput">Kopieer</button>
                            </div>
                        </div>
                        <div class="endpoint-row">
                            <label>Gebruikersnaam</label>
                            <div class="copy-group">
                                <input type="text" id="xtreamUserInput" value="${this.escapeHtml(d.xtream_out_username)}" readonly>
                                <button class="btn copy-btn" data-copy="xtreamUserInput">Kopieer</button>
                            </div>
                        </div>
                        <div class="endpoint-row">
                            <label>Wachtwoord</label>
                            <div class="copy-group">
                                <input type="password" id="xtreamPassInput" value="${this.escapeHtml(d.xtream_out_password)}" readonly>
                                <button type="button" class="btn" id="toggleXtreamPassBtn" style="min-width:44px;">👁️</button>
                                <button class="btn copy-btn" data-copy="xtreamPassInput">Kopieer</button>
                            </div>
                        </div>
                    </div>
                    ` : `
                    <p class="muted" style="margin:0;">Uitgaande Xtream Codes server is nog niet geactiveerd of geconfigureerd. Gebruik de instellingen hieronder om inloggegevens aan te maken.</p>
                    `}

                    <hr style="border:none;border-top:1px solid var(--border-color, #2a2f3a);margin:1rem 0;">
                    <div class="stack-form" style="gap:0.75rem;">
                        <label class="checkbox-row">
                            <input type="checkbox" id="dashEnforceLan" ${d.enforce_lan_ip ? 'checked' : ''}>
                            <span>Beperk uitgaande feeds tot lokale LAN-subnets</span>
                        </label>
                        <div class="endpoint-row">
                            <label>Uitgaand Feed Token</label>
                            <div class="copy-group">
                                <input type="text" id="dashOutputToken" value="${this.escapeHtml(d.output_token || '')}" readonly>
                                <button type="button" class="btn" id="dashRegenTokenBtn" style="min-height:44px;">Regenereer Token</button>
                            </div>
                        </div>
                        <label class="checkbox-row">
                            <input type="checkbox" id="dashXtreamOutEnabled" ${d.xtream_out_enabled ? 'checked' : ''}>
                            <span>Xtream Codes Server inschakelen</span>
                        </label>
                        <div class="grid-2">
                            <label>Uitgaande Gebruikersnaam
                                <input type="text" id="dashXtreamOutUser" placeholder="${this.escapeHtml(d.xtream_out_username_masked || 'Gebruikersnaam (laat leeg om te behouden)')}">
                            </label>
                            <label>Uitgaand Wachtwoord
                                <input type="password" id="dashXtreamOutPass" placeholder="${d.has_xtream_out_password ? '•••••••• (laat leeg om te behouden)' : 'Wachtwoord'}">
                            </label>
                        </div>
                        <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                            <button type="button" class="btn" id="dashGenXtreamOutCredsBtn" style="min-height:44px;">🔑 Genereer Sterke Inloggegevens</button>
                            <button type="button" class="btn btn-primary" id="dashSaveOutboundBtn" style="min-height:44px;">Uitgaande Instellingen Opslaan</button>
                        </div>
                    </div>
                </div>
            </details>

            <details class="collapsible-card">
                <summary class="collapsible-card__header">
                    <span>📋 Synchronisatie Logs &amp; Status</span>
                    <span class="collapsible-card__chevron">▼</span>
                </summary>
                <div class="collapsible-card__body">
                    <div class="card-header" style="margin-bottom:0.75rem;">
                        <span class="muted" style="font-size:0.85rem;">Recente synchronisaties</span>
                        <button type="button" id="btn-clear-sync-logs" class="btn btn-sm">🗑️ Log leegmaken</button>
                    </div>
                    <div id="syncLogViewerContainer"></div>
                </div>
            </details>
        `;

        this.bindEvents();
        this.loadSyncLogViewer();
    }

    bindEvents() {
        this.container.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.copy);
                if (!input) return;
                ClipboardHelper.copyText(input.value, btn, null, this.props.toast);
            });
        });

        this.container.querySelectorAll('.btn-download[data-download]').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.download);
                if (!input || !input.value) return;
                const sep = input.value.includes('?') ? '&' : '?';
                const downloadUrl = `${input.value}${sep}download=1`;
                const link = document.createElement('a');
                link.href = downloadUrl;
                if (btn.dataset.filename) {
                    link.setAttribute('download', btn.dataset.filename);
                }
                document.body.appendChild(link);
                link.click();
                link.remove();
            });
        });

        document.getElementById('toggleXtreamPassBtn')?.addEventListener('click', () => {
            const input = document.getElementById('xtreamPassInput');
            if (!input) return;
            input.type = input.type === 'password' ? 'text' : 'password';
        });

        document.getElementById('goToXtreamSettingsBtn')?.addEventListener('click', () => {
            document.querySelector('[data-page="settings"], [data-tab="settings"]')?.click();
        });

        const saveOutboundSettings = async (reload = false) => {
            await this.props.api.post('save_settings', {
                enforce_lan_ip: document.getElementById('dashEnforceLan')?.checked ?? true,
                xtream_out_enabled: document.getElementById('dashXtreamOutEnabled')?.checked ?? false,
                xtream_out_username: document.getElementById('dashXtreamOutUser')?.value || '',
                xtream_out_password: document.getElementById('dashXtreamOutPass')?.value || '',
            });
            this.props.toast.show('Uitgaande instellingen opgeslagen!');
            if (reload) {
                await this.load();
            }
        };

        document.getElementById('dashEnforceLan')?.addEventListener('change', () => saveOutboundSettings(false));
        document.getElementById('dashXtreamOutEnabled')?.addEventListener('change', () => saveOutboundSettings(true));
        document.getElementById('dashSaveOutboundBtn')?.addEventListener('click', () => saveOutboundSettings(true));

        document.getElementById('dashGenXtreamOutCredsBtn')?.addEventListener('click', () => {
            const randHex = bytes => Array.from(crypto.getRandomValues(new Uint8Array(bytes)))
                .map(b => b.toString(16).padStart(2, '0')).join('');
            const userEl = document.getElementById('dashXtreamOutUser');
            const passEl = document.getElementById('dashXtreamOutPass');
            const enabledEl = document.getElementById('dashXtreamOutEnabled');
            if (userEl) userEl.value = 'iptv_' + randHex(4);
            if (passEl) {
                passEl.type = 'text';
                passEl.value = randHex(12);
            }
            if (enabledEl) enabledEl.checked = true;
            this.props.toast.show('Nieuwe inloggegevens ingevuld. Klik op Uitgaande Instellingen Opslaan om te bewaren.');
        });

        document.getElementById('dashRegenTokenBtn')?.addEventListener('click', async () => {
            const res = await this.props.api.post('regenerate_token');
            const tokenEl = document.getElementById('dashOutputToken');
            if (tokenEl) tokenEl.value = res.output_token;
            this.props.toast.show('Nieuw token gegenereerd!');
            await this.load();
        });

        const mount = document.getElementById('dashboardProgressMount');
        if (mount) {
            this.progressPanel = new SyncProgressPanel(mount, this.props.api);
            this.progressPanel.checkActive({
                onLogs: (logs) => this.renderSyncLogs(logs),
                onComplete: async () => {
                    this.props.toast.show('Synchronisatie voltooid!');
                    await this.load();
                },
            });
        }

        this.container.querySelectorAll('[data-sync]').forEach(btn => {
            btn.addEventListener('click', async () => {
                document.getElementById('syncStatusBanner')?.classList.add('hidden');
                this.progressPanel?.startPolling(null, {
                    onLogs: (logs) => this.renderSyncLogs(logs),
                    onComplete: async () => {
                        this.props.toast.show('Synchronisatie voltooid!');
                        await this.load();
                    },
                });
                let isBackground = false;
                try {
                    const res = await this.props.api.post('sync', {
                        mode: btn.dataset.sync,
                        cache_mode: btn.dataset.cacheMode || 'force_download',
                    });
                    isBackground = Boolean(res?.background);
                    if (!isBackground) {
                        this.props.toast.show('Synchronisatie voltooid!');
                        await this.load();
                    }
                } catch (e) {
                    const banner = document.getElementById('syncStatusBanner');
                    if (banner) {
                        banner.textContent = `✗ Fout: ${e.message} (Tip: reeds gedownloade bestanden blijven bewaard in storage/cache/ voor herimport.)`;
                        banner.classList.remove('hidden');
                    }
                } finally {
                    if (!isBackground) {
                        this.progressPanel?.stopPolling();
                    }
                }
            });
        });

        document.getElementById('openCacheManagerBtn')?.addEventListener('click', () => {
            this.props.switchTab?.('cache');
        });

        document.getElementById('btn-clear-sync-logs')?.addEventListener('click', async () => {
            if (!confirm('Weet je zeker dat je alle sync-logs wilt verwijderen?')) return;
            const res = await this.props.api.post('clear_sync_logs');
            await this.props.api.post('clear_app_logs', { file: 'sync.log' });
            this.renderSyncLogs([]);
            this.props.toast.show(res?.message || 'Sync log is leeggemaakt');
        });
    }

    async loadSyncLogViewer() {
        const container = document.getElementById('syncLogViewerContainer');
        if (!container) return;
        this.syncLogViewer = new SyncLogViewer(container, { lines: [] });
        try {
            const res = await this.props.api.get('app_logs?file=sync.log&lines=100');
            this.syncLogViewer.setLines(res?.lines || []);
        } catch {
            this.syncLogViewer.setLines([]);
        }
    }

    renderSyncLogs() {
        this.loadSyncLogViewer();
    }
}
