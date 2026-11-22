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
        this.selectedContentTypes = new Set(['live']);
        this.hasLoadedData = false;
        this.loadFromDb = false;
        this.groupList = null;
        this.channelTable = null;
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        if (!this.hasLoadedData) {
            const loadedFromCache = await this.tryAutoLoadFromCache();
            if (!loadedFromCache) {
                this.renderPreloadPrompt();
            }
            return;
        }
        await this.loadFilteredData();
    }

    async tryAutoLoadFromCache() {
        if (this.selectedContentTypes.size === 0) return false;
        const contentTypes = Array.from(this.selectedContentTypes).join(',');
        const [groupsRes, tags] = await Promise.all([
            this.props.api.get('groups', { content_types: contentTypes, scope: 'active', cache_only: 1 }),
            this.props.api.get('country_tags'),
        ]);
        const list = Array.isArray(groupsRes) ? groupsRes : (groupsRes?.items || []);
        if (list.length === 0) {
            return false;
        }
        this.hasLoadedData = true;
        this.groups = list;
        this.countryTags = tags || [];
        this.populateCountryFilter();
        this.updateGroupSortButton();
        this.groupList.setState({ groups: this.groups, selectedGroupId: this.selectedGroupId, countryTags: this.countryTags });
        this.updateGroupHeader();
        this.renderSelectGroupPrompt();
        return true;
    }

    renderPreloadPrompt() {
        const groupsRoot = document.getElementById('groupsListRoot');
        if (groupsRoot) {
            groupsRoot.innerHTML = `<div class="muted" style="padding:1rem;text-align:center;">Selecteer één of meerdere categorieën en klik op <strong>Filter &amp; Laad</strong>.</div>`;
        }
        const channelsRoot = document.getElementById('channelsTableRoot');
        if (channelsRoot) {
            channelsRoot.innerHTML = `<tr><td colspan="4" class="muted" style="padding:2rem;text-align:center;">Selecteer één of meerdere categorieën (Live TV, Films, Series) en klik op <strong>Filter &amp; Laad</strong> om enkel die groepen en kanalen op te halen.</td></tr>`;
        }
    }

    async loadFilteredData() {
        if (this.selectedContentTypes.size === 0) {
            this.props.toast?.show('Selecteer minstens één categorie (Live TV, Films of Series).', 'warning');
            return;
        }
        const contentTypes = Array.from(this.selectedContentTypes).join(',');
        const [groupsRes, tags] = await Promise.all([
            this.props.api.get('groups', { content_types: contentTypes, scope: 'active', refresh: this.loadFromDb ? '1' : '0' }),
            this.props.api.get('country_tags'),
        ]);
        this.hasLoadedData = true;
        this.groups = Array.isArray(groupsRes) ? groupsRes : (groupsRes?.items || []);
        this.groupSortMode = groupsRes?.group_sort_mode || this.groupSortMode || 'import';
        this.countryTags = tags || [];
        this.populateCountryFilter();
        this.updateGroupSortButton();
        this.groupList.setState({ groups: this.groups, selectedGroupId: this.selectedGroupId, countryTags: this.countryTags });
        this.updateGroupHeader();
        if (this.selectedGroupId) {
            await this.loadChannels();
        } else {
            this.renderSelectGroupPrompt();
        }
    }

    async searchAndRenderAddGroups() {
        const resultsEl = document.getElementById('groupAddResults');
        if (!resultsEl) return;
        const q = (document.getElementById('groupAddSearchInput')?.value || '').trim();
        const status = document.getElementById('groupAddStatusFilter')?.value || 'disabled';
        const contentTypes = Array.from(this.selectedContentTypes).join(',');

        resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Zoeken...</div>`;
        const items = await this.props.api.get('groups_search', {
            q,
            status,
            content_types: contentTypes,
            limit: 50,
        });

        if (!Array.isArray(items) || items.length === 0) {
            resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Geen groepen gevonden.</div>`;
            return;
        }

        resultsEl.innerHTML = items.map(g => {
            const name = this.escapeHtml(g.display_name || g.custom_name || g.original_name || '');
            const enabled = Number(g.is_enabled) === 1;
            return `
                <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;padding:0.35rem 0.25rem;border-bottom:1px solid rgba(255,255,255,0.06);">
                    <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${name}">${name}</span>
                    <button type="button" class="btn btn-sm ${enabled ? '' : 'btn-primary'} group-add-toggle-btn" data-id="${g.id}" data-enable="${enabled ? '0' : '1'}" style="min-height:36px;flex-shrink:0;">
                        ${enabled ? 'Actief ✓' : '+ Activeer'}
                    </button>
                </div>
            `;
        }).join('');

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
        if (!this.hasLoadedData || this.selectedContentTypes.size === 0) {
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
            content_types: Array.from(this.selectedContentTypes).join(','),
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
            <div id="contentTypeFilterBar" class="card" style="margin-bottom:1rem;padding:0.75rem 1rem;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                <button type="button" class="btn content-type-toggle active" data-type="live" style="min-height:44px;padding:0 1rem;">📺 Live TV</button>
                <button type="button" class="btn content-type-toggle" data-type="movie" style="min-height:44px;padding:0 1rem;">🎬 Films</button>
                <button type="button" class="btn content-type-toggle" data-type="series" style="min-height:44px;padding:0 1rem;">🎞️ Series</button>
                <div style="margin-left:auto;display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;">
                    <button type="button" class="btn" id="loadSourceToggleBtn" title="Kies of Filter & Laad uit de snelle view-cache of vers uit de database laadt" style="min-height:44px;padding:0 0.9rem;">⚡ Cache</button>
                    <button type="button" class="btn btn-primary" id="applyContentTypeFilterBtn" style="min-height:44px;padding:0 1.25rem;">🔍 Filter &amp; Laad</button>
                </div>
            </div>
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary" id="openGroupSearchBtn" style="min-height:44px;">+ Groep toevoegen</button>
                            <button type="button" class="btn btn-sm" id="groupSortBtn" title="Klik om groepssortering te wisselen (Import / A-Z / Z-A)"><span id="groupSortBadge">⇅ Import</span></button>
                            <button class="btn btn-sm" id="enableAllGroupsBtn">Alles Aan</button>
                            <button class="btn btn-sm" id="disableAllGroupsBtn">Alles Uit</button>
                        </div>
                    </div>
                    <div id="groupAddPanel" class="hidden" style="padding:0.75rem;margin-bottom:0.75rem;border:1px solid var(--border-color, #334155);border-radius:8px;background:rgba(15,23,42,0.4);">
                        <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:0.5rem;flex-wrap:wrap;">
                            <input type="search" id="groupAddSearchInput" placeholder="Zoek groep (bv. BE, NL, Sport)..." style="flex:1;min-width:140px;font-size:16px;min-height:44px;">
                            <select id="groupAddStatusFilter" style="font-size:16px;min-height:44px;">
                                <option value="disabled">Alleen inactief</option>
                                <option value="all">Alle</option>
                            </select>
                            <button type="button" class="btn btn-sm" id="closeGroupSearchBtn" style="min-height:44px;">✕</button>
                        </div>
                        <div id="groupAddResults" style="max-height:240px;overflow-y:auto;"></div>
                    </div>
                    <input type="search" id="groupSearchInput" placeholder="Filter actieve groepen..." style="font-size:16px;">
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

        document.getElementById('openGroupSearchBtn')?.addEventListener('click', () => {
            const panel = document.getElementById('groupAddPanel');
            if (!panel) return;
            panel.classList.toggle('hidden');
            if (!panel.classList.contains('hidden')) {
                document.getElementById('groupAddSearchInput')?.focus();
                this.searchAndRenderAddGroups();
            }
        });

        document.getElementById('closeGroupSearchBtn')?.addEventListener('click', () => {
            document.getElementById('groupAddPanel')?.classList.add('hidden');
        });

        document.getElementById('groupAddSearchInput')?.addEventListener('input', debounce(() => {
            this.searchAndRenderAddGroups();
        }, 300));

        document.getElementById('groupAddStatusFilter')?.addEventListener('change', () => {
            this.searchAndRenderAddGroups();
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

        document.querySelectorAll('#contentTypeFilterBar .content-type-toggle').forEach(btn => {
            btn.addEventListener('click', () => {
                const type = btn.dataset.type;
                if (!type) return;
                if (this.selectedContentTypes.has(type)) {
                    this.selectedContentTypes.delete(type);
                    btn.classList.remove('active');
                } else {
                    this.selectedContentTypes.add(type);
                    btn.classList.add('active');
                }
            });
        });

        document.getElementById('loadSourceToggleBtn')?.addEventListener('click', e => {
            this.loadFromDb = !this.loadFromDb;
            const btn = e.currentTarget;
            btn.textContent = this.loadFromDb ? '🗄️ Database' : '⚡ Cache';
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

    updateContentTypeFilterUi() {
        const labels = { live: 'Live TV', movie: 'Films', series: 'Series' };
        this.container.querySelectorAll('.content-type-toggle').forEach(btn => {
            const type = btn.dataset.type;
            const active = Boolean(type && this.selectedContentTypes.has(type));
            btn.classList.toggle('is-active', active);
            btn.classList.toggle('active', active);
            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
            const baseLabel = btn.dataset.label || btn.textContent.replace(/^✓\s*/, '');
            btn.textContent = (active ? '✓ ' : '') + baseLabel;
        });
        const statusEl = document.getElementById('contentTypeStatusText');
        if (statusEl) {
            const activeNames = Array.from(this.selectedContentTypes).map(t => labels[t] || t);
            statusEl.textContent = activeNames.length > 0
                ? `Actief: ${activeNames.join(', ')}`
                : 'Actief: Geen (selecteer minstens 1)';
        }
    }

    updateChannelsTableHeader() {
        const thead = document.getElementById('channelsTableHead');
        if (!thead) return;
        if (!this.selectedGroupId) {
            thead.innerHTML = `
                <tr>
                    <th style="width:72px;">Aan/Uit</th>
                    <th style="width:90px;">#</th>
                    <th>Naam van het kanaal</th>
                </tr>`;
        } else {
            thead.innerHTML = `
                <tr>
                    <th style="width:40px;"><input type="checkbox" id="selectAllChannelsCheckbox" title="Selecteer alle zichtbare kanalen"></th>
                    <th style="width:72px;">Aan/Uit</th>
                    <th style="width:90px;">#</th>
                    <th>Naam van het kanaal</th>
                    <th>Groep</th>
                </tr>`;
        }
    }
}
