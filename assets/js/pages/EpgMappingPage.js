import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { EpgMapper } from '../components/EpgMapper.js';

export class EpgMappingPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.mapper = null;
        this.currentPage = 1;
        this.pageSize = 10;
        this.totalItems = 0;
        this.groupsLoaded = false;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        if (!this.groupsLoaded) {
            await this.loadActiveGroups();
        }
        const search = document.getElementById('epgChannelSearch')?.value || '';
        const epg_status = document.getElementById('epgStatusFilter')?.value || '';
        const group_id = document.getElementById('epgGroupFilter')?.value || '';
        const offset = (this.currentPage - 1) * this.pageSize;

        const params = {
            active_only: 1,
            search,
            epg_status,
            limit: this.pageSize,
            offset,
        };
        if (group_id) params.group_id = group_id;

        const data = await this.props.api.get('channels', params);
        this.totalItems = Number(data.total || 0);
        this.mapper.setState({ channels: data.items || [] });
        this.renderPagination();
    }

    async loadActiveGroups() {
        const sel = document.getElementById('epgGroupFilter');
        if (!sel) return;
        const groups = await this.props.api.get('groups', { scope: 'active' });
        const list = Array.isArray(groups) ? groups : (groups?.items || []);
        const cur = sel.value;
        sel.innerHTML = `<option value="">Alle actieve groepen</option>` + list.map(g => {
            const label = this.escapeHtml(g.display_name || g.custom_name || g.original_name || '');
            return `<option value="${g.id}">${label}</option>`;
        }).join('');
        sel.value = cur;
        this.groupsLoaded = true;
    }

    renderPagination() {
        const wrap = document.getElementById('epgPagination');
        if (!wrap) return;
        const totalPages = Math.max(1, Math.ceil(this.totalItems / this.pageSize));
        if (this.currentPage > totalPages) {
            this.currentPage = totalPages;
        }
        wrap.innerHTML = `
            <button type="button" class="btn btn-sm" id="epgPrevPageBtn" ${this.currentPage <= 1 ? 'disabled' : ''} style="min-height:44px;padding:0 1rem;">← Vorige</button>
            <span class="muted" style="font-size:0.9rem;">Pagina <strong>${this.currentPage}</strong> van <strong>${totalPages}</strong> (${this.totalItems} kanalen)</span>
            <button type="button" class="btn btn-sm" id="epgNextPageBtn" ${this.currentPage >= totalPages ? 'disabled' : ''} style="min-height:44px;padding:0 1rem;">Volgende →</button>
        `;
        document.getElementById('epgPrevPageBtn')?.addEventListener('click', () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.load();
            }
        });
        document.getElementById('epgNextPageBtn')?.addEventListener('click', () => {
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.load();
            }
        });
    }

    renderShell() {
        this.container.innerHTML = `
            <style>
                .epg-mapping-table th, .epg-mapping-table td { padding: 0.4rem 0.65rem; }
            </style>
            <div class="card">
                <div class="card-header">
                    <h2>EPG Zender Koppeling</h2>
                    <button class="btn btn-primary" id="autoMatchEpgBtn" style="min-height:44px;">✨ Auto-koppel op naam</button>
                </div>
                <div class="toolbar" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;">
                    <input type="search" id="epgChannelSearch" placeholder="Zoek actief kanaal..." style="font-size:16px;min-height:44px;">
                    <select id="epgGroupFilter" style="font-size:16px;min-height:44px;max-width:260px;">
                        <option value="">Alle actieve groepen</option>
                    </select>
                    <select id="epgStatusFilter" style="font-size:16px;min-height:44px;">
                        <option value="">Alle statussen</option>
                        <option value="unmapped">Geen EPG</option>
                        <option value="mapped">Gekoppeld</option>
                    </select>
                </div>
                <div class="table-wrap">
                    <table class="data-table epg-mapping-table">
                        <thead><tr><th>Kanaal</th><th>Status</th><th>Gekoppelde EPG Zender</th><th>Actie</th></tr></thead>
                        <tbody id="epgMapperRoot"></tbody>
                    </table>
                </div>
                <div id="epgPagination" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding-top:0.75rem;flex-wrap:wrap;"></div>
            </div>
        `;

        this.mapper = new EpgMapper('#epgMapperRoot', {
            onPick: (channelId, channelName) => this.openPicker(channelId, channelName)
        });

        document.getElementById('epgChannelSearch')?.addEventListener('input', debounce(() => {
            this.currentPage = 1;
            this.load();
        }, 300));
        document.getElementById('epgGroupFilter')?.addEventListener('change', () => {
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('epgStatusFilter')?.addEventListener('change', () => {
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('autoMatchEpgBtn')?.addEventListener('click', async () => {
            const res = await this.props.api.post('auto_match_epg');
            this.props.toast.show(`${res.matched} kanalen automatisch gekoppeld!`);
            this.load();
        });
    }

    async openPicker(channelId, channelName) {
        this.props.modal.open(
            `Kies EPG Zender voor ${channelName}`,
            `<input type="search" id="epgModalSearch" placeholder="Typ zendernaam (bv. NPO 1, VRT 1)...">
             <div id="epgModalResults" class="epg-results"></div>`
        );

        const search = async q => {
            const items = await this.props.api.get('epg_search', { q });
            const box = document.getElementById('epgModalResults');
            if (!box) return;
            box.innerHTML = `<div class="epg-option" data-epgid=""><em>Geen EPG (ontkoppelen)</em></div>` + items.map(i => `
                <div class="epg-option" data-epgid="${this.escapeHtml(i.epg_id)}">
                    ${i.icon_url ? `<img src="${this.escapeHtml(i.icon_url)}" alt="">` : ''}
                    <div>
                        <div><strong>${this.escapeHtml(i.display_name)}</strong></div>
                        <div class="muted" style="font-size:0.8rem;">${this.escapeHtml(i.epg_id)}</div>
                    </div>
                </div>
            `).join('');

            box.querySelectorAll('.epg-option').forEach(opt => {
                opt.addEventListener('click', async () => {
                    await this.props.api.post('update_channel', { id: channelId, epg_channel_id: opt.dataset.epgid });
                    this.props.modal.close();
                    this.props.toast.show('EPG koppeling opgeslagen');
                    this.load();
                });
            });
        };

        document.getElementById('epgModalSearch')?.addEventListener('input', debounce(e => search(e.target.value), 250));
        await search('');
    }
}
