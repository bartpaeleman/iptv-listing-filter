import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { GroupList } from '../components/GroupList.js';
import { ChannelTable } from '../components/ChannelTable.js';

export class ChannelsPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.selectedGroupId = null;
        this.channelSearch = '';
        this.groupSortMode = 'import';
        this.channelSortBy = 'number';
        this.groupList = null;
        this.channelTable = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        const [groupsRes, tags] = await Promise.all([
            this.props.api.get('groups'),
            this.props.api.get('country_tags'),
        ]);
        this.groups = Array.isArray(groupsRes) ? groupsRes : (groupsRes?.items || []);
        this.groupSortMode = groupsRes?.group_sort_mode || this.groupSortMode || 'import';
        this.countryTags = tags || [];
        this.populateCountryFilter();
        this.updateGroupSortButton();
        this.groupList.setState({ groups: this.groups, selectedGroupId: this.selectedGroupId, countryTags: this.countryTags });
        this.updateGroupHeader();
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
            sort_by: this.channelSortBy,
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
                        <div id="groupHeaderTitleWrap" style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                            <h2 id="selectedGroupTitle">Alle Kanalen</h2>
                            <div id="inlineGroupEditWrap" class="hidden" style="display:inline-flex;align-items:center;gap:0.35rem;flex-wrap:wrap;">
                                <input type="text" id="inlineGroupNameInput" placeholder="Custom groepsnaam (leeg = origineel)" style="font-size:16px;min-height:44px;min-width:180px;">
                                <button type="button" class="btn btn-sm btn-primary" id="inlineGroupSaveBtn" title="Opslaan (Enter)" style="min-height:44px;min-width:44px;">✔️</button>
                                <button type="button" class="btn btn-sm" id="inlineGroupCancelBtn" title="Annuleren (Escape)" style="min-height:44px;min-width:44px;">✖️</button>
                            </div>
                        </div>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm hidden" id="adoptGroupNameBtn" title="Huidige weergavenaam (incl. vlag/emoji) opslaan als custom naam">❐</button>
                            <button type="button" class="btn btn-sm hidden" id="editGroupNameBtn" title="Groepsnaam aanpassen">✏️</button>
                            <button class="btn btn-sm" id="manageCountryTagsBtn">🏷️ Landen & Tags Beheren</button>
                            <button class="btn btn-sm" id="bulkEnableChannelsBtn">Alles Aan</button>
                            <button class="btn btn-sm" id="bulkDisableChannelsBtn">Alles Uit</button>
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
                                    <th>Aan</th>
                                    <th id="sortChannelsByNum" style="cursor:pointer;user-select:none;" title="Sorteer op kanaalnummer">#</th>
                                    <th id="sortChannelsByName" style="cursor:pointer;user-select:none;" title="Klik om te sorteren: A-Z / Z-A / Import">Naam (Origineel / Custom) <span id="channelNameSortBadge" class="muted" style="font-size:0.8rem;font-weight:normal;"></span></th>
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
                this.updateGroupHeader(name);
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
            },
            onSelectionChange: count => {
                const enableBtn = document.getElementById('bulkEnableChannelsBtn');
                const disableBtn = document.getElementById('bulkDisableChannelsBtn');
                if (enableBtn) enableBtn.textContent = count > 0 ? `Selectie Aan (${count})` : 'Selectie Aan';
                if (disableBtn) disableBtn.textContent = count > 0 ? `Selectie Uit (${count})` : 'Selectie Uit';
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

        const bulkChannels = async enable => {
            await this.props.api.post('bulk_channels', {
                is_enabled: enable ? 1 : 0,
                group_id: this.selectedGroupId,
                search: this.channelSearch,
            });
            this.props.toast.show(enable ? 'Alle kanalen ingeschakeld' : 'Alle kanalen uitgeschakeld');
            this.loadChannels();
        };
        document.getElementById('bulkEnableChannelsBtn')?.addEventListener('click', () => bulkChannels(true));
        document.getElementById('bulkDisableChannelsBtn')?.addEventListener('click', () => bulkChannels(false));
        document.getElementById('manageCountryTagsBtn')?.addEventListener('click', () => this.openCountryModal());

        const titleEl = document.getElementById('selectedGroupTitle');
        const editWrap = document.getElementById('inlineGroupEditWrap');
        const inlineInput = document.getElementById('inlineGroupNameInput');

        const cancelInlineEdit = () => {
            editWrap?.classList.add('hidden');
            titleEl?.classList.remove('hidden');
        };

        const saveHeaderGroupName = async newName => {
            if (!this.selectedGroupId) return;
            await this.props.api.post('update_group', { id: this.selectedGroupId, custom_name: newName });
            this.props.toast.show(newName ? 'Groepsnaam bijgewerkt' : 'Groepsnaam hersteld naar origineel');
            cancelInlineEdit();
            await this.load();
            const updated = (this.groups || []).find(g => Number(g.id) === Number(this.selectedGroupId));
            if (updated && titleEl) {
                titleEl.textContent = updated.display_name || updated.custom_name || updated.original_name;
            }
        };

        document.getElementById('editGroupNameBtn')?.addEventListener('click', () => {
            if (!this.selectedGroupId) return;
            const grp = (this.groups || []).find(g => Number(g.id) === Number(this.selectedGroupId));
            if (inlineInput) {
                inlineInput.value = grp?.custom_name || grp?.original_name || grp?.name || '';
            }
            titleEl?.classList.add('hidden');
            editWrap?.classList.remove('hidden');
            inlineInput?.focus();
            inlineInput?.select();
        });

        document.getElementById('inlineGroupSaveBtn')?.addEventListener('click', () => {
            saveHeaderGroupName((inlineInput?.value || '').trim());
        });

        document.getElementById('inlineGroupCancelBtn')?.addEventListener('click', cancelInlineEdit);

        inlineInput?.addEventListener('keydown', ev => {
            if (ev.key === 'Enter') {
                ev.preventDefault();
                saveHeaderGroupName((inlineInput.value || '').trim());
            } else if (ev.key === 'Escape') {
                ev.preventDefault();
                cancelInlineEdit();
            }
        });

        document.getElementById('adoptGroupNameBtn')?.addEventListener('click', () => {
            if (!this.selectedGroupId) return;
            const grp = (this.groups || []).find(g => Number(g.id) === Number(this.selectedGroupId));
            const display = grp?.display_name || titleEl?.textContent || '';
            saveHeaderGroupName(display.trim());
        });

        document.getElementById('groupSortBtn')?.addEventListener('click', async () => {
            const order = ['import', 'asc', 'desc'];
            const nextMode = order[(order.indexOf(this.groupSortMode) + 1) % order.length];
            const res = await this.props.api.post('set_group_sort', { mode: nextMode });
            this.groups = Array.isArray(res) ? res : (res?.items || []);
            this.groupSortMode = res?.group_sort_mode || nextMode;
            this.updateGroupSortButton();
            this.groupList.setState({ groups: this.groups, selectedGroupId: this.selectedGroupId });
        });

        document.getElementById('sortChannelsByNum')?.addEventListener('click', () => {
            this.channelSortBy = 'number';
            const badge = document.getElementById('channelNameSortBadge');
            if (badge) badge.textContent = '';
            this.loadChannels();
        });

        document.getElementById('sortChannelsByName')?.addEventListener('click', () => {
            const order = ['name_asc', 'name_desc', 'import'];
            const idx = order.indexOf(this.channelSortBy);
            this.channelSortBy = order[(idx + 1) % order.length];
            const badge = document.getElementById('channelNameSortBadge');
            if (badge) {
                const labels = { name_asc: '↑', name_desc: '↓', import: '⇅' };
                badge.textContent = labels[this.channelSortBy] || '';
            }
            this.loadChannels();
        });
    }

    updateGroupSortButton() {
        const badge = document.getElementById('groupSortBadge');
        if (!badge) return;
        const labels = {
            import: '⇅ Import',
            asc: '↑ A-Z',
            desc: '↓ Z-A',
        };
        badge.textContent = labels[this.groupSortMode] || '⇅ Import';
    }

    updateGroupHeader(fallbackName = '') {
        const titleEl = document.getElementById('selectedGroupTitle');
        const editWrap = document.getElementById('inlineGroupEditWrap');
        const adoptBtn = document.getElementById('adoptGroupNameBtn');
        const editBtn = document.getElementById('editGroupNameBtn');

        const hasGroup = this.selectedGroupId !== null && this.selectedGroupId !== undefined;
        const grp = hasGroup ? (this.groups || []).find(g => Number(g.id) === Number(this.selectedGroupId)) : null;

        if (titleEl) {
            titleEl.textContent = hasGroup
                ? (grp?.display_name || grp?.custom_name || grp?.original_name || fallbackName || 'Groep')
                : 'Alle Kanalen';
            titleEl.classList.remove('hidden');
        }
        editWrap?.classList.add('hidden');
        adoptBtn?.classList.toggle('hidden', !hasGroup);
        editBtn?.classList.toggle('hidden', !hasGroup);
    }

    async openCountryModal() {
        this.props.switchTab('emoji-rules');
    }
}
