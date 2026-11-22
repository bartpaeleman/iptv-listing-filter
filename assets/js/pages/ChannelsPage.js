import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { GroupList } from '../components/GroupList.js';
import { ChannelTable } from '../components/ChannelTable.js';

export class ChannelsPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.selectedGroupId = null;
        this.channelSearch = '';
        this.groupList = null;
        this.channelTable = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        const [groups, tags] = await Promise.all([
            this.props.api.get('groups'),
            this.props.api.get('country_tags'),
        ]);
        this.countryTags = tags || [];
        this.populateCountryFilter();
        this.groupList.setState({ groups, selectedGroupId: this.selectedGroupId, countryTags: this.countryTags });
        await this.loadChannels();
    }

    populateCountryFilter() {
        const sel = document.getElementById('channelCountryFilter');
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = `<option value="">Alle landen/tags</option><option value="untagged">Zonder land-tag</option>`
            + (this.countryTags || []).map(t => `<option value="${t.id}">${this.escapeHtml(t.flag_emoji)} ${this.escapeHtml(t.country_name)} (${this.escapeHtml(t.tag_pattern)})</option>`).join('');
        sel.value = cur;
    }

    async loadChannels() {
        const params = {
            search: this.channelSearch,
            country_tag_id: document.getElementById('channelCountryFilter')?.value || '',
            status: document.getElementById('channelStatusFilter')?.value || 'all',
            has_epg: document.getElementById('channelEpgFilter')?.value || 'all',
            limit: 150,
        };
        if (this.selectedGroupId) params.group_id = this.selectedGroupId;
        const data = await this.props.api.get('channels', params);
        this.channelTable.setState({ channels: data.items, countryTags: this.countryTags || [] });
    }

    renderShell() {
        this.container.innerHTML = `
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm" id="enableAllGroupsBtn">Alles Aan</button>
                            <button class="btn btn-sm" id="disableAllGroupsBtn">Alles Uit</button>
                        </div>
                    </div>
                    <input type="search" id="groupSearchInput" placeholder="Filter groepen...">
                    <div id="groupsListRoot" class="groups-list"></div>
                </aside>
                <div class="card channels-pane">
                    <div class="card-header">
                        <h2 id="selectedGroupTitle">Alle Kanalen</h2>
                        <div class="btn-group">
                            <button class="btn btn-sm" id="manageCountryTagsBtn">🏷️ Landen & Tags Beheren</button>
                            <button class="btn btn-sm" id="bulkEnableChannelsBtn">Selectie Aan</button>
                            <button class="btn btn-sm" id="bulkDisableChannelsBtn">Selectie Uit</button>
                        </div>
                    </div>
                    <div class="toolbar" style="flex-wrap:wrap;">
                        <input type="search" id="channelSearchInput" placeholder="Zoek kanaalnaam..." style="flex:2;min-width:180px;">
                        <select id="channelCountryFilter" style="flex:1;min-width:140px;"><option value="">Alle landen/tags</option></select>
                        <select id="channelStatusFilter" style="flex:1;min-width:120px;">
                            <option value="all">Alle statussen</option>
                            <option value="enabled">Actief</option>
                            <option value="disabled">Uitgeschakeld</option>
                        </select>
                        <select id="channelEpgFilter" style="flex:1;min-width:120px;">
                            <option value="all">Alle EPG</option>
                            <option value="yes">Met EPG</option>
                            <option value="no">Zonder EPG</option>
                        </select>
                    </div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th title="Selecteer/deselecteer alle zichtbare kanalen voor bulkacties" style="white-space:nowrap;">
                                        <label style="display:inline-flex;align-items:center;gap:0.25rem;cursor:pointer;">
                                            <input type="checkbox" id="selectAllChannels" title="Selecteer/deselecteer alle zichtbare kanalen voor bulkacties">
                                            <span style="font-size:0.8rem;">☑️</span>
                                        </label>
                                    </th>
                                    <th>Aan</th>
                                    <th>#</th>
                                    <th>Naam (Origineel / Custom)</th>
                                    <th>Groep</th>
                                </tr>
                            </thead>
                            <tbody id="channelsTableRoot"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        `;

        this.groupList = new GroupList('#groupsListRoot', {
            onSelect: (id, name) => {
                this.selectedGroupId = id;
                document.getElementById('selectedGroupTitle').textContent = name;
                this.groupList.setState({ selectedGroupId: id });
                this.loadChannels();
            },
            onToggle: async (id, checked) => {
                await this.props.api.post('update_group', { id, is_enabled: checked ? 1 : 0 });
                this.props.toast.show('Groep bijgewerkt');
                this.load();
            }
        });

        this.channelTable = new ChannelTable('#channelsTableRoot', {
            onUpdate: async (id, payload) => {
                await this.props.api.post('update_channel', { id, ...payload });
                this.props.toast.show('Kanaal bijgewerkt');
            }
        });

        document.getElementById('groupSearchInput')?.addEventListener('input', debounce(e => {
            this.groupList.setState({ filter: e.target.value });
        }, 200));

        document.getElementById('channelSearchInput')?.addEventListener('input', debounce(e => {
            this.channelSearch = e.target.value;
            this.loadChannels();
        }, 300));

        document.getElementById('enableAllGroupsBtn')?.addEventListener('click', async () => {
            await this.props.api.post('bulk_groups', { is_enabled: 1 });
            this.load();
        });

        document.getElementById('disableAllGroupsBtn')?.addEventListener('click', async () => {
            await this.props.api.post('bulk_groups', { is_enabled: 0 });
            this.load();
        });

        document.getElementById('selectAllChannels')?.addEventListener('change', ev => {
            document.querySelectorAll('#channelsTableRoot tr').forEach(tr => {
                const cb = tr.querySelector('.ch-select');
                if (cb) cb.checked = ev.target.checked;
                tr.classList.toggle('row-selected', ev.target.checked);
            });
            this.updateBulkButtons(this.channelTable?.getSelectedIds().length || 0);
        });

        const bulkChannels = async enable => {
            const ids = this.channelTable.getSelectedIds();
            if (ids.length === 0) return this.props.toast.show('Selecteer eerst kanalen.');
            await this.props.api.post('bulk_channels', { ids, is_enabled: enable ? 1 : 0 });
            this.loadChannels();
        };
        document.getElementById('bulkEnableChannelsBtn')?.addEventListener('click', () => bulkChannels(true));
        document.getElementById('bulkDisableChannelsBtn')?.addEventListener('click', () => bulkChannels(false));
    }

    async openCountryModal() {
        const unmapped = await this.props.api.get('scan_tags');
        const tagsHtml = (this.countryTags || []).map(t => `
            <div style="display:flex;justify-content:space-between;align-items:center;padding:0.4rem 0;border-bottom:1px solid var(--border);">
                <span>${this.escapeHtml(t.flag_emoji)} <strong>${this.escapeHtml(t.tag_pattern)}</strong> — ${this.escapeHtml(t.country_name)} (${this.escapeHtml(t.country_code)})</span>
                <button type="button" class="btn btn-sm del-tag-btn" data-id="${t.id}">✕</button>
            </div>
        `).join('');

        this.props.modal.open('Landen & Tags Beheren', `
            <div style="margin-bottom:1rem;">
                ${unmapped.length > 0 ? `<p class="muted" style="font-size:0.85rem;">Onbekende tags gevonden: <code>${unmapped.map(u => this.escapeHtml(u)).join(', ')}</code></p>` : ''}
                <form id="addTagForm" class="grid-2" style="margin-bottom:0.75rem;">
                    <input type="text" id="newTagPattern" placeholder="Tag (bv. [PT])" required>
                    <input type="text" id="newTagCode" placeholder="ISO-2 (bv. PT)" maxlength="2" required>
                    <input type="text" id="newTagName" placeholder="Landnaam (bv. Portugal)" required>
                    <button type="submit" class="btn btn-primary">+ Tag Opslaan</button>
                </form>
                <div style="max-height:180px;overflow-y:auto;">${tagsHtml}</div>
            </div>
            <div class="btn-group">
                <button type="button" id="applyFlagsBtn" class="btn btn-primary">🏳️ Vlaggen toepassen op namen</button>
                <button type="button" id="groupByCountryBtn" class="btn">📁 Groepeer kanalen per land</button>
            </div>
        `);

        document.getElementById('addTagForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_country_tag', {
                tag_pattern: document.getElementById('newTagPattern').value,
                country_code: document.getElementById('newTagCode').value,
                country_name: document.getElementById('newTagName').value,
            });
            this.props.toast.show('Tag opgeslagen!');
            await this.load();
            this.openCountryModal();
        });

        document.querySelectorAll('.del-tag-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('delete_country_tag', { id: Number(btn.dataset.id) });
                await this.load();
                this.openCountryModal();
            });
        });

        document.getElementById('applyFlagsBtn')?.addEventListener('click', async () => {
            const res = await this.props.api.post('apply_country_tags', { group_by_country: false });
            this.props.toast.show(`${res.updated} kanalen van vlaggen voorzien!`);
            this.props.modal.close();
            this.load();
        });

        document.getElementById('groupByCountryBtn')?.addEventListener('click', async () => {
            if (!confirm('Weet je zeker dat je gekoppelde kanalen wilt verplaatsen naar landgroepen?')) return;
            const res = await this.props.api.post('apply_country_tags', { group_by_country: true });
            this.props.toast.show(`${res.grouped} kanalen gegroepeerd per land!`);
            this.props.modal.close();
            this.load();
        });
    }
}
