import { BaseComponent } from '../core/BaseComponent.js';
import { ClipboardHelper } from '../core/ClipboardHelper.js';
import { SyncProgressPanel } from '../components/SyncProgressPanel.js';

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
                <div id="sync-progress-panel" class="sync-progress-panel hidden" style="margin:0.75rem 0;padding:0.75rem;border-radius:8px;background:var(--surface-2, rgba(255,255,255,0.04));border:1px solid var(--border, rgba(255,255,255,0.1));">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.4rem;font-size:0.9rem;">
                        <span id="syncProgressMsg">Synchronisatie voorbereiden...</span>
                        <span style="display:flex;gap:0.75rem;align-items:center;white-space:nowrap;">
                            <span id="syncProgressEta" class="muted" style="font-size:0.82rem;">ETA: berekenen...</span>
                            <strong id="syncProgressPercent">0%</strong>
                        </span>
                    </div>
                    <div style="width:100%;height:10px;border-radius:999px;background:var(--border, rgba(255,255,255,0.12));overflow:hidden;">
                        <div id="syncProgressFill" style="width:0%;height:100%;background:var(--primary, #3b82f6);transition:width 0.3s ease;"></div>
                    </div>
                    <pre id="syncLiveConsole" class="muted" style="margin:0.5rem 0 0 0;font-size:0.78rem;max-height:90px;overflow:auto;white-space:pre-wrap;"></pre>
                </div>
                <div id="syncStatusBanner" class="sync-banner hidden"></div>
            </div>

            <div class="card">
                <h2>LAN Client Endpoint M3U Link</h2>
                <p class="muted" style="font-size:0.85rem;margin:-0.25rem 0 0.75rem 0;">vb: (IPTVX / Kodi / VLC)</p>
                <div class="endpoint-row">
                    <label>M3U Playlist URL</label>
                    <div class="copy-group">
                        <input type="text" id="playlistUrlInput" value="${this.escapeHtml(playlistUrl)}" readonly>
                        <button class="btn copy-btn" data-copy="playlistUrlInput">Kopieer</button>
                    </div>
                </div>
                ${d.source_mode !== 'xtream' ? `
                <div class="endpoint-row">
                    <label>XMLTV EPG URL</label>
                    <div class="copy-group">
                        <input type="text" id="epgUrlInput" value="${this.escapeHtml(epgUrl)}" readonly>
                        <button class="btn copy-btn" data-copy="epgUrlInput">Kopieer</button>
                    </div>
                </div>
                ` : ''}
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

        const mount = document.getElementById('dashboardProgressMount');
        if (mount) {
            this.progressPanel = new SyncProgressPanel(mount, this.props.api);
        }

        this.container.querySelectorAll('[data-sync]').forEach(btn => {
            btn.addEventListener('click', async () => {
                document.getElementById('syncStatusBanner')?.classList.add('hidden');
                this.progressPanel?.startPolling(null, {
                    onLogs: (logs) => this.renderSyncLogs(logs),
                });
                try {
                    await this.props.api.post('sync', {
                        mode: btn.dataset.sync,
                        cache_mode: btn.dataset.cacheMode || 'force_download',
                    });
                    this.props.toast.show('Synchronisatie voltooid!');
                    await this.load();
                } catch (e) {
                    const banner = document.getElementById('syncStatusBanner');
                    if (banner) {
                        banner.textContent = `✗ Fout: ${e.message} (Tip: reeds gedownloade bestanden blijven bewaard in storage/cache/ voor herimport.)`;
                        banner.classList.remove('hidden');
                    }
                } finally {
                    this.progressPanel?.stopPolling();
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
