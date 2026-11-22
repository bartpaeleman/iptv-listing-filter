import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { EpgMapper } from '../components/EpgMapper.js';

export class EpgMappingPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.mapper = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        const search = document.getElementById('epgChannelSearch')?.value || '';
        const epg_status = document.getElementById('epgStatusFilter')?.value || '';
        const data = await this.props.api.get('channels', { active_only: 1, search, epg_status, limit: 150 });
        this.mapper.setState({ channels: data.items });
    }

    renderShell() {
        this.container.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <h2>EPG Zender Koppeling</h2>
                    <button class="btn btn-primary" id="autoMatchEpgBtn">✨ Auto-koppel op naam</button>
                </div>
                <div class="toolbar">
                    <input type="search" id="epgChannelSearch" placeholder="Zoek actief kanaal...">
                    <select id="epgStatusFilter">
                        <option value="">Alle statussen</option>
                        <option value="unmapped">Geen EPG</option>
                        <option value="mapped">Gekoppeld</option>
                    </select>
                </div>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Kanaal</th><th>Status</th><th>Gekoppelde EPG Zender</th><th>Actie</th></tr></thead>
                        <tbody id="epgMapperRoot"></tbody>
                    </table>
                </div>
            </div>
        `;

        this.mapper = new EpgMapper('#epgMapperRoot', {
            onPick: (channelId, channelName) => this.openPicker(channelId, channelName)
        });

        document.getElementById('epgChannelSearch')?.addEventListener('input', debounce(() => this.load(), 300));
        document.getElementById('epgStatusFilter')?.addEventListener('change', () => this.load());
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
