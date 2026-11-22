import { BaseComponent } from '../core/BaseComponent.js';

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
                    <h2>Synchronisatie</h2>
                    <div class="btn-group">
                        <button class="btn btn-primary" data-sync="all">🔄 Nu Alles Synchroniseren</button>
                        <button class="btn" data-sync="m3u">Alleen M3U</button>
                        <button class="btn" data-sync="epg">Alleen EPG</button>
                    </div>
                </div>
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
                <h2>Recente Sync Logs</h2>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Tijd</th><th>Type</th><th>Status</th><th>Bericht</th></tr></thead>
                        <tbody>
                            ${(d.logs || []).map(l => `
                                <tr>
                                    <td>${this.escapeHtml(l.created_at)}</td>
                                    <td>${this.escapeHtml(String(l.sync_type).toUpperCase())}</td>
                                    <td><span class="badge ${l.status === 'success' ? 'badge-success' : 'badge-warning'}">${this.escapeHtml(l.status)}</span></td>
                                    <td>${this.escapeHtml(l.message)}</td>
                                </tr>
                            `).join('')}
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
                navigator.clipboard.writeText(input.value);
                this.props.toast.show('Gekopieerd naar klembord!');
            });
        });

        this.container.querySelectorAll('[data-sync]').forEach(btn => {
            btn.addEventListener('click', async () => {
                const banner = document.getElementById('syncStatusBanner');
                if (banner) {
                    banner.textContent = '⏳ Synchronisatie bezig... Dit kan enkele minuten duren.';
                    banner.classList.remove('hidden');
                }
                try {
                    await this.props.api.post('sync', { mode: btn.dataset.sync });
                    this.props.toast.show('Synchronisatie voltooid!');
                    await this.load();
                } catch (e) {
                    if (banner) banner.textContent = `✗ Fout: ${e.message}`;
                }
            });
        });
    }
}
