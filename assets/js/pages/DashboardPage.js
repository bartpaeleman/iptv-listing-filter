import { BaseComponent } from '../core/BaseComponent.js';
import { ClipboardHelper } from '../core/ClipboardHelper.js';

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
                        <button class="btn" data-sync="all" data-cache-mode="cache_only" ${!(d.cache_status?.m3u?.exists || d.cache_status?.epg?.exists) ? 'disabled' : ''}>⚡ Alles uit Cache</button>
                        <button class="btn" data-sync="m3u" data-cache-mode="force_download">M3U Download</button>
                        <button class="btn" data-sync="m3u" data-cache-mode="cache_only" ${!d.cache_status?.m3u?.exists ? 'disabled' : ''}>M3U Cache</button>
                        <button class="btn" data-sync="epg" data-cache-mode="force_download">EPG Download</button>
                        <button class="btn" data-sync="epg" data-cache-mode="cache_only" ${!d.cache_status?.epg?.exists ? 'disabled' : ''}>EPG Cache</button>
                        <button class="btn" id="clearCacheBtn">🗑️ Cache legen</button>
                    </div>
                </div>
                <p class="muted" style="font-size:0.85rem;margin:0 0 0.5rem 0;">
                    Lokale M3U cache: ${d.cache_status?.m3u?.exists ? `${this.escapeHtml(d.cache_status.m3u.size_formatted)} — ${d.cache_status.m3u.age_minutes} min geleden` : 'Geen'}
                    &nbsp;|&nbsp;
                    Lokale EPG cache: ${d.cache_status?.epg?.exists ? `${this.escapeHtml(d.cache_status.epg.size_formatted)} — ${d.cache_status.epg.age_minutes} min geleden` : 'Geen'}
                </p>
                <div id="syncStatusBanner" class="sync-banner hidden"></div>
            </div>

            <div class="card">
                <h2>LAN Client Endpoints (TiviMate / Kodi / VLC)</h2>
                <div class="endpoint-row">
                    <label>M3U Playlist URL</label>
                    <div class="copy-group">
                        <input type="text" id="playlistUrlInput" value="${this.escapeHtml(playlistUrl)}" readonly>
                        <button class="btn copy-btn" data-copy="playlistUrlInput">Kopieer</button>
                    </div>
                </div>
                <div class="endpoint-row">
                    <label>XMLTV EPG URL</label>
                    <div class="copy-group">
                        <input type="text" id="epgUrlInput" value="${this.escapeHtml(epgUrl)}" readonly>
                        <button class="btn copy-btn" data-copy="epgUrlInput">Kopieer</button>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>Recente Sync Logs</h2>
                    <button type="button" id="btn-clear-sync-logs" class="btn btn-sm">🗑️ Log leegmaken</button>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Tijd</th><th>Type</th><th>Status</th><th>Bericht</th></tr></thead>
                        <tbody id="syncLogsBody">
                            ${(d.logs || []).length > 0 ? (d.logs || []).map(l => `
                                <tr>
                                    <td>${this.escapeHtml(l.created_at)}</td>
                                    <td>${this.escapeHtml(String(l.sync_type).toUpperCase())}</td>
                                    <td><span class="badge ${l.status === 'success' ? 'badge-success' : 'badge-warning'}">${this.escapeHtml(l.status)}</span></td>
                                    <td>${this.escapeHtml(l.message)}</td>
                                </tr>
                            `).join('') : '<tr><td colspan="4" class="muted">Nog geen sync-logs aanwezig</td></tr>'}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('.copy-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = document.getElementById(btn.dataset.copy);
                if (!input) return;
                ClipboardHelper.copyText(input.value, btn, null, this.props.toast);
            });
        });

        this.container.querySelectorAll('[data-sync]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const panel = document.getElementById('sync-progress-panel');
                const msgEl = document.getElementById('syncProgressMsg');
                const fillEl = document.getElementById('syncProgressFill');
                const consoleEl = document.getElementById('syncLiveConsole');
                const banner = document.getElementById('syncStatusBanner');
                panel?.classList.remove('hidden');
                banner?.classList.add('hidden');
                if (fillEl) fillEl.style.width = '5%';

                const poller = setInterval(async () => {
                    try {
                        const st = await this.props.api.get('sync_status');
                        const p = st.progress || {};
                        if (msgEl) msgEl.textContent = p.message || 'Bezig...';
                        if (fillEl) fillEl.style.width = `${p.percent || 0}%`;
                        if (consoleEl) consoleEl.textContent = (p.live_lines || []).join('\n');
                        if (st.logs) this.renderSyncLogs(st.logs);
                    } catch { /* ignore transient poll errors */ }
                }, 800);

                try {
                    await this.props.api.post('sync', {
                        mode: btn.dataset.sync,
                        cache_mode: btn.dataset.cacheMode || 'force_download',
                    });
                    this.props.toast.show('Synchronisatie voltooid!');
                    await this.load();
                } catch (e) {
                    if (banner) {
                        banner.textContent = `✗ Fout: ${e.message} (Tip: reeds gedownloade bestanden blijven bewaard in storage/cache/ voor herimport.)`;
                        banner.classList.remove('hidden');
                    }
                } finally {
                    clearInterval(poller);
                }
            });
        });

        document.getElementById('openCacheManagerBtn')?.addEventListener('click', () => {
            this.props.switchTab?.('cache');
        });

        document.getElementById('btn-clear-sync-logs')?.addEventListener('click', async () => {
            if (!confirm('Weet je zeker dat je alle sync-logs wilt verwijderen?')) return;
            const res = await this.props.api.post('clear_sync_logs');
            this.renderSyncLogs([]);
            this.props.toast.show(res?.message || 'Sync log is leeggemaakt');
        });
    }

    renderSyncLogs(logs) {
        const tbody = document.getElementById('syncLogsBody');
        if (!tbody) return;
        tbody.innerHTML = (logs || []).length > 0 ? logs.map(l => `
            <tr>
                <td>${this.escapeHtml(l.created_at)}</td>
                <td>${this.escapeHtml(String(l.sync_type).toUpperCase())}</td>
                <td><span class="badge ${l.status === 'success' ? 'badge-success' : 'badge-warning'}">${this.escapeHtml(l.status)}</span></td>
                <td>${this.escapeHtml(l.message)}</td>
            </tr>
        `).join('') : '<tr><td colspan="4" class="muted">Nog geen sync-logs aanwezig</td></tr>';
    }
}
