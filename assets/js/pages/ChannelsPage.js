import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { GroupList } from '../components/GroupList.js';
import { ChannelTable } from '../components/ChannelTable.js';
import { GroupChannelToolsBar } from '../components/GroupChannelToolsBar.js';

export class ChannelsPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.selectedGroupId = null;
        this.channelSearch = '';
        this.groupSortMode = 'asc';
        this.channelSortBy = 'number';
        this.isAddGroupMode = false;
        this.selectedGroupCategories = new Set();
        try {
            this.groupChannelSortMap = JSON.parse(localStorage.getItem('iptv_group_channel_sort') || '{}');
        } catch {
            this.groupChannelSortMap = {};
        }
        this.hasLoadedData = false;
        this.groupList = null;
        this.channelTable = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        await this.loadFilteredData();
    }

    async loadFilteredData() {
        const [groupsRes, tags] = await Promise.all([
            this.props.api.get('groups', { scope: 'active', refresh: 1 }),
            this.props.api.get('country_tags'),
        ]);
        this.hasLoadedData = true;
        this.groups = Array.isArray(groupsRes) ? groupsRes : (groupsRes?.items || []);
        this.countryTags = tags || [];
        this.populateCountryFilter();
        this.groupList.setState({ groups: this.groups, selectedGroupId: this.selectedGroupId, countryTags: this.countryTags });
        this.updateGroupHeader();
        if (this.selectedGroupId && !this.isAddGroupMode) {
            await this.loadChannels();
        } else if (!this.isAddGroupMode) {
            this.renderSelectGroupPrompt();
        }
    }

    renderSelectGroupPrompt() {
        const channelsRoot = document.getElementById('channelsTableRoot');
        if (channelsRoot) {
            channelsRoot.innerHTML = `<tr><td colspan="4" class="muted" style="padding:2rem;text-align:center;">Selecteer een actieve groep links om de kanalen te laden, of klik op <strong>+ Groep toevoegen</strong> om extra groepen op te zoeken.</td></tr>`;
        }
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
        if (!this.hasLoadedData) {
            return;
        }
        if (!this.selectedGroupId && !this.channelSearch.trim()) {
            this.renderSelectGroupPrompt();
            return;
        }
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
        this.updateChannelsTableHeader();
        this.channelTable.setState({
            channels: data.items,
            countryTags: this.countryTags || [],
            isAllChannelsView: !this.selectedGroupId,
        });
    }

    async searchAndRenderAddGroups() {
        const resultsEl = document.getElementById('groupAddResults');
        if (!resultsEl) return;
        const q = (document.getElementById('groupAddSearchInput')?.value || '').trim();
        const status = document.getElementById('groupAddStatusFilter')?.value || 'disabled';
        const categories = Array.from(this.selectedGroupCategories).join(',');

        resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Zoeken...</div>`;
        const items = await this.props.api.get('groups_search', {
            q,
            status,
            categories,
            limit: 100,
        });

        if (!Array.isArray(items) || items.length === 0) {
            resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Geen groepen gevonden.</div>`;
            return;
        }

        const catOptions = ['Live TV', 'Sport', 'Animatie', 'TV Series', 'Film', 'Muziek', 'Adult'];
        resultsEl.innerHTML = `
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Groep</th>
                        <th style="width:170px;">Categorie</th>
                        <th style="width:120px;text-align:right;">Actie</th>
                    </tr>
                </thead>
                <tbody>
                    ${items.map(g => {
                        const name = this.escapeHtml(g.display_name || g.custom_name || g.original_name || '');
                        const enabled = Number(g.is_enabled) === 1;
                        const cat = this.escapeHtml(g.category || 'Live TV');
                        return `
                            <tr>
                                <td>${name}</td>
                                <td>
                                    <select class="category-tag-select" data-group-id="${g.id}">
                                        ${catOptions.map(c => `<option value="${c}" ${c === (g.category || 'Live TV') ? 'selected' : ''}>${c}</option>`).join('')}
                                    </select>
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <button type="button" class="btn btn-sm group-preview-btn" data-action="preview-group" data-id="${g.id}" style="min-height:44px;margin-right:0.25rem;">👁️ Inhoud</button>
                                    <button type="button" class="btn btn-sm ${enabled ? '' : 'btn-primary'} group-add-toggle-btn" data-id="${g.id}" data-enable="${enabled ? '0' : '1'}" style="min-height:44px;">
                                        ${enabled ? 'Actief ✓' : '+ Activeer'}
                                    </button>
                                </td>
                            </tr>
                            <tr class="group-preview-row hidden" data-preview-for="${g.id}">
                                <td colspan="3">
                                    <div class="group-preview-box" data-preview-box="${g.id}">Laden...</div>
                                </td>
                            </tr>
                        `;
                    }).join('')}
                </tbody>
            </table>
        `;

        resultsEl.querySelectorAll('.group-preview-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id);
                const row = resultsEl.querySelector(`.group-preview-row[data-preview-for="${id}"]`);
                const box = resultsEl.querySelector(`[data-preview-box="${id}"]`);
                if (!row || !box) return;
                if (!row.classList.contains('hidden')) {
                    row.classList.add('hidden');
                    return;
                }
                row.classList.remove('hidden');
                box.innerHTML = '<span class="muted">Kanalen laden...</span>';
                const data = await this.props.api.get('channels', { group_id: id, limit: 100 });
                const chList = data?.items || [];
                if (chList.length === 0) {
                    box.innerHTML = '<span class="muted">Geen kanalen in deze groep.</span>';
                    return;
                }
                box.innerHTML = chList.map(c => {
                    const chName = this.escapeHtml(c.display_name || c.custom_name || c.tvg_name || '');
                    const on = Number(c.is_enabled) === 1;
                    return `<div style="display:flex;justify-content:space-between;padding:0.15rem 0;border-bottom:1px solid rgba(255,255,255,0.05);"><span>${chName}</span><span class="muted">${on ? '✅ Aan' : '⬜ Uit'}</span></div>`;
                }).join('');
            });
        });

        resultsEl.querySelectorAll('.category-tag-select').forEach(sel => {
            sel.addEventListener('change', async () => {
                const id = Number(sel.dataset.groupId);
                const category = sel.value;
                await this.props.api.post('update_group_category', { id, category, lock: true });
                this.props.toast?.show(`Categorie gewijzigd naar ${category}`);
                await this.loadFilteredData();
                await this.searchAndRenderAddGroups();
            });
        });

        resultsEl.querySelectorAll('.group-add-toggle-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id);
                const enable = Number(btn.dataset.enable);
                await this.props.api.post('update_group', { id, is_enabled: enable });
                this.props.toast?.show(enable ? 'Groep geactiveerd en toegevoegd' : 'Groep gedeactiveerd');
                await this.loadFilteredData();
                await this.searchAndRenderAddGroups();
            });
        });
    }

    renderSelectGroupPrompt() {
        const channelsRoot = document.getElementById('channelsTableRoot');
        if (channelsRoot) {
            channelsRoot.innerHTML = `<tr><td colspan="4" class="muted" style="padding:2rem;text-align:center;">Selecteer een actieve groep links om de kanalen te laden, of klik op <strong>+ Groep toevoegen</strong> om extra groepen op te zoeken.</td></tr>`;
        }
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
        if (!this.hasLoadedData) {
            return;
        }
        if (!this.selectedGroupId && !this.channelSearch.trim()) {
            this.renderSelectGroupPrompt();
            return;
        }
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
        this.updateChannelsTableHeader();
        this.channelTable.setState({
            channels: data.items,
            countryTags: this.countryTags || [],
            isAllChannelsView: !this.selectedGroupId,
        });
    }

    renderShell() {
        this.container.innerHTML = `
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary" id="openGroupSearchBtn" style="min-height:44px;">+ Groep toevoegen</button>
                        </div>
                    </div>
                    <input type="search" id="groupSearchInput" placeholder="Filter actieve groepen..." style="font-size:16px;">
                    <div id="groupsListRoot" class="groups-list"></div>
                </aside>
                <div class="card channels-pane">
                    <div id="channelsViewContainer">
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
                    <div id="groupChannelToolsMount"></div>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead id="channelsTableHead">
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
                    <div id="groupAddPanel" class="hidden">
                        <div class="card-header">
                            <h2>Groep toevoegen</h2>
                        </div>
                        <div class="cat-filter-bar" id="groupCategoryFilterBar">
                            <button type="button" class="cat-filter-btn" data-cat="Live TV">Live TV</button>
                            <button type="button" class="cat-filter-btn" data-cat="Sport">Sport</button>
                            <button type="button" class="cat-filter-btn" data-cat="Animatie">Animatie</button>
                            <button type="button" class="cat-filter-btn" data-cat="TV Series">TV Series</button>
                            <button type="button" class="cat-filter-btn" data-cat="Film">Film</button>
                            <button type="button" class="cat-filter-btn" data-cat="Muziek">Muziek</button>
                            <button type="button" class="cat-filter-btn" data-cat="Adult">Adult</button>
                        </div>
                        <div class="toolbar" style="display:flex;gap:0.5rem;align-items:center;flex-wrap:wrap;margin-bottom:0.75rem;">
                            <input type="search" id="groupAddSearchInput" placeholder="Zoek groep (bv. BE, NL, [UK], Sport)..." style="flex:1;min-width:180px;font-size:16px;min-height:44px;">
                            <select id="groupAddStatusFilter" style="font-size:16px;min-height:44px;">
                                <option value="all">Alle</option>
                                <option value="disabled" selected>Alleen inactief</option>
                                <option value="enabled">Alleen actief</option>
                            </select>
                        </div>
                        <div id="groupAddResults" style="max-height:60vh;overflow-y:auto;"></div>
                    </div>
                </div>
            </div>
        `;

        this.groupList = new GroupList('#groupsListRoot', {
            onSelect: (id, name) => {
                this.selectedGroupId = id;
                this.channelSortBy = 'number';
                this.updateGroupHeader(name);
                this.groupList.setState({ selectedGroupId: id });
                const toolsBar = new GroupChannelToolsBar('#groupChannelToolsMount', {
                    api: this.props.api,
                    toast: this.props.toast,
                    groupId: id,
                    onUpdated: (res) => {
                        this.channelTable.setState({ items: res.items || [], total: res.total || 0 });
                    },
                });
                toolsBar.render();
                this.loadChannels();
            },
            onToggle: async (id, checked) => {
                await this.props.api.post('update_group', { id, is_enabled: checked ? 1 : 0 });
                if (!checked && Number(id) === Number(this.selectedGroupId)) {
                    this.selectedGroupId = null;
                }
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

        document.getElementById('openGroupSearchBtn')?.addEventListener('click', () => {
            this.isAddGroupMode = !this.isAddGroupMode;
            const btn = document.getElementById('openGroupSearchBtn');
            const panel = document.getElementById('groupAddPanel');
            const channelsView = document.getElementById('channelsViewContainer');
            if (btn) {
                btn.classList.toggle('btn-danger', this.isAddGroupMode);
                btn.classList.toggle('btn-primary', !this.isAddGroupMode);
            }
            if (panel) panel.classList.toggle('hidden', !this.isAddGroupMode);
            if (channelsView) channelsView.classList.toggle('hidden', this.isAddGroupMode);

            if (this.isAddGroupMode) {
                document.getElementById('groupAddSearchInput')?.focus();
                this.searchAndRenderAddGroups();
            } else if (this.selectedGroupId) {
                this.loadChannels();
            }
        });

        document.getElementById('groupAddSearchInput')?.addEventListener('input', debounce(() => {
            this.searchAndRenderAddGroups();
        }, 300));

        document.getElementById('groupAddStatusFilter')?.addEventListener('change', () => {
            this.searchAndRenderAddGroups();
        });

        document.querySelectorAll('#groupCategoryFilterBar .cat-filter-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const cat = btn.dataset.cat;
                if (!cat) return;
                if (this.selectedGroupCategories.has(cat)) {
                    this.selectedGroupCategories.delete(cat);
                    btn.classList.remove('active');
                } else {
                    this.selectedGroupCategories.add(cat);
                    btn.classList.add('active');
                }
                this.searchAndRenderAddGroups();
            });
        });

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

        document.getElementById('loadSourceToggleBtn')?.addEventListener('click', e => {
            this.loadFromDb = !this.loadFromDb;
            const btn = e.currentTarget;
            btn.textContent = this.loadFromDb ? 'Bron: 🗄️ Database' : 'Bron: ⚡ Cache';
            btn.classList.toggle('btn-warning', this.loadFromDb);
        });

        document.getElementById('applyContentTypeFilterBtn')?.addEventListener('click', () => {
            this.selectedGroupId = null;
            this.loadFilteredData();
        });

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



    updateChannelsTableHeader() {
        const thead = document.getElementById('channelsTableHead');
        if (!thead) return;
        const numIcon = this.channelSortBy === 'number' ? '▲' : (this.channelSortBy === 'number_desc' ? '▼' : '⇅');
        const nameIcon = this.channelSortBy === 'name_asc' ? '▲' : (this.channelSortBy === 'name_desc' ? '▼' : '⇅');
        const numColor = (this.channelSortBy === 'number' || this.channelSortBy === 'number_desc') ? 'color:var(--accent,#38bdf8);' : '';
        const nameColor = (this.channelSortBy === 'name_asc' || this.channelSortBy === 'name_desc') ? 'color:var(--accent,#38bdf8);' : '';

        if (!this.selectedGroupId) {
            thead.innerHTML = `
                <tr>
                    <th style="width:72px;">Aan/Uit</th>
                    <th class="ch-sort-th" data-sort="number" style="width:90px;cursor:pointer;user-select:none;${numColor}"># <span style="font-size:0.75rem;">${numIcon}</span></th>
                    <th class="ch-sort-th" data-sort="name" style="cursor:pointer;user-select:none;${nameColor}">Naam van het kanaal <span style="font-size:0.75rem;">${nameIcon}</span></th>
                </tr>`;
        } else {
            thead.innerHTML = `
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="selectAllChannelsCheckbox" title="Selecteer alle zichtbare kanalen"></th>
                    <th style="width:72px;">Aan/Uit</th>
                    <th class="ch-sort-th" data-sort="number" style="width:90px;cursor:pointer;user-select:none;${numColor}"># <span style="font-size:0.75rem;">${numIcon}</span></th>
                    <th class="ch-sort-th" data-sort="name" style="cursor:pointer;user-select:none;${nameColor}">Naam van het kanaal <span style="font-size:0.75rem;">${nameIcon}</span></th>
                    <th>Groep</th>
                </tr>`;
        }

        thead.querySelectorAll('.ch-sort-th').forEach(th => {
            th.addEventListener('click', async () => {
                const key = th.dataset.sort;
                if (key === 'number') {
                    this.channelSortBy = this.channelSortBy === 'number' ? 'number_desc' : 'number';
                } else if (key === 'name') {
                    this.channelSortBy = this.channelSortBy === 'name_asc' ? 'name_desc' : 'name_asc';
                }
                if (this.selectedGroupId) {
                    this.groupChannelSortMap[String(this.selectedGroupId)] = this.channelSortBy;
                    try {
                        localStorage.setItem('iptv_group_channel_sort', JSON.stringify(this.groupChannelSortMap));
                    } catch {}
                    await this.props.api.post('reorder_group_channels', {
                        group_id: this.selectedGroupId,
                        sort_by: this.channelSortBy,
                    });
                }
                await this.loadChannels();
            });
        });
    }
}
