import { BaseComponent } from '../core/BaseComponent.js';

export class CacheManagerPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.filterType = 'all';
    }

    async load() {
        const data = await this.props.api.get('cache_list');
        this.setState({ data });
    }

    render() {
        const d = this.state.data;
        if (!d) {
            this.container.innerHTML = '<div class="card">Laden...</div>';
            return;
        }

        const files = (d.files || []).filter(f => this.filterType === 'all' || f.type === this.filterType);

        this.container.innerHTML = `
            <div class="stats-grid" style="margin-bottom:1.25rem;">
                <div class="stat-card">
                    <span class="stat-label">M3U Cachebestanden</span>
                    <strong class="stat-value">${d.m3u_count}</strong>
                </div>
                <div class="stat-card">
                    <span class="stat-label">EPG Cachebestanden</span>
                    <strong class="stat-value">${d.epg_count}</strong>
                </div>
                <div class="stat-card">
                    <span class="stat-label">Totale Schijfruimte</span>
                    <strong class="stat-value">${this.escapeHtml(d.total_size_formatted)}</strong>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <div class="btn-group">
                        <button type="button" class="btn btn-sm ${this.filterType === 'all' ? 'btn-primary' : ''}" data-cfilter="all">Alle bestanden</button>
                        <button type="button" class="btn btn-sm ${this.filterType === 'm3u' ? 'btn-primary' : ''}" data-cfilter="m3u">M3U Playlists</button>
                        <button type="button" class="btn btn-sm ${this.filterType === 'epg' ? 'btn-primary' : ''}" data-cfilter="epg">EPG Guides</button>
                    </div>
                    <button type="button" id="bulkDeleteCacheBtn" class="btn btn-sm">🗑️ Geselecteerde wissen</button>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th><input type="checkbox" id="selectAllCache"></th>
                                <th>Type</th>
                                <th>Label & Bestandsnaam</th>
                                <th>Datum (UTC)</th>
                                <th>Grootte</th>
                                <th>Items</th>
                                <th>Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${files.length > 0 ? files.map(f => `
                                <tr data-fn="${this.escapeHtml(f.filename)}">
                                    <td><input type="checkbox" class="cache-select"></td>
                                    <td><span class="badge ${f.type === 'epg' ? 'badge-warning' : 'badge-success'}">${this.escapeHtml(String(f.type).toUpperCase())}</span></td>
                                    <td>
                                        <input type="text" class="cache-label-input" value="${this.escapeHtml(f.label || f.filename)}" style="margin-bottom:0.2rem;">
                                        <div class="muted" style="font-size:0.75rem;">${this.escapeHtml(f.filename)}</div>
                                    </td>
                                    <td>${this.escapeHtml(f.created_at || '-')}</td>
                                    <td>${this.escapeHtml(f.size_formatted || '-')}</td>
                                    <td>${f.item_count !== null && f.item_count !== undefined ? f.item_count : '-'}</td>
                                    <td class="btn-group">
                                        <button type="button" class="btn btn-sm btn-primary import-cache-btn">Importeer in DB</button>
                                        <button type="button" class="btn btn-sm del-cache-btn">Verwijderen</button>
                                    </td>
                                </tr>
                            `).join('') : '<tr><td colspan="7" class="muted">Geen cachebestanden gevonden.</td></tr>'}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('[data-cfilter]').forEach(btn => {
            btn.addEventListener('click', () => {
                this.filterType = btn.dataset.cfilter;
                this.render();
            });
        });

        document.getElementById('selectAllCache')?.addEventListener('change', ev => {
            this.container.querySelectorAll('.cache-select').forEach(cb => { cb.checked = ev.target.checked; });
        });

        this.container.querySelectorAll('.cache-label-input').forEach(input => {
            input.addEventListener('change', async () => {
                const fn = input.closest('tr')?.dataset.fn;
                if (!fn) return;
                await this.props.api.post('cache_rename', { filename: fn, label: input.value });
                this.props.toast.show('Label opgeslagen');
            });
        });

        this.container.querySelectorAll('.import-cache-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const fn = btn.closest('tr')?.dataset.fn;
                if (!fn) return;
                btn.disabled = true;
                btn.textContent = '⏳...';
                try {
                    await this.props.api.post('cache_import', { filename: fn });
                    this.props.toast.show(`Import van ${fn} voltooid!`);
                    this.load();
                } catch {
                    this.load();
                }
            });
        });

        this.container.querySelectorAll('.del-cache-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const fn = btn.closest('tr')?.dataset.fn;
                if (!fn || !confirm(`Weet je zeker dat je ${fn} wilt verwijderen?`)) return;
                await this.props.api.post('cache_delete', { filenames: [fn] });
                this.props.toast.show('Cachebestand verwijderd');
                this.load();
            });
        });

        document.getElementById('bulkDeleteCacheBtn')?.addEventListener('click', async () => {
            const fns = Array.from(this.container.querySelectorAll('tbody tr'))
                .filter(tr => tr.querySelector('.cache-select')?.checked)
                .map(tr => tr.dataset.fn);
            if (fns.length === 0) return this.props.toast.show('Selecteer eerst bestanden.');
            if (!confirm(`${fns.length} cachebestanden verwijderen?`)) return;
            await this.props.api.post('cache_delete', { filenames: fns });
            this.props.toast.show('Geselecteerde bestanden verwijderd');
            this.load();
        });
    }
}
