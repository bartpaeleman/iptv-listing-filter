import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { GroupList } from '../components/GroupList.js';
import { ChannelTable } from '../components/ChannelTable.js';
import { CustomGroupSyncBanner } from '../components/CustomGroupSyncBanner.js';
import { CustomGroupChannelPicker } from '../components/CustomGroupChannelPicker.js';
import { CustomGroupAvailableChannels } from '../components/CustomGroupAvailableChannels.js';
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
        this.groupSearchOffset = 0;
        this.groupSearchHasMore = false;
        this.groupSearchLoading = false;
        this.groupSearchItems = new Map();
        this.groupSearchRequestId = 0;
        this.groupAddObserver = null;
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

    renderGroupAddRow(g) {
        const catOptions = ['Live TV', 'Sport', 'Animatie', 'TV Series', 'Film', 'Muziek', 'Adult'];
        const displayRaw = String(g.display_name || g.custom_name || g.original_name || '');
        const origRaw = String(g.original_name || '');
        const name = this.escapeHtml(displayRaw);
        const showOrig = origRaw !== '' && origRaw !== displayRaw;
        const enabled = Number(g.is_enabled) === 1;
        return `
            <tr>
                <td>
                    <div>${name}</div>
                    ${showOrig ? `<div class="group-add-original-name">${this.escapeHtml(origRaw)}</div>` : ''}
                </td>
                <td>
                    <select class="category-tag-select" data-group-id="${g.id}">
                        ${catOptions.map(c => `<option value="${c}" ${c === (g.category || 'Live TV') ? 'selected' : ''}>${c}</option>`).join('')}
                    </select>
                </td>
                <td style="text-align:right;white-space:nowrap;">
                    <button type="button" class="btn btn-sm group-preview-btn" data-action="preview-group" data-id="${g.id}" data-ct="${this.escapeHtml(g.content_type || 'live')}" style="min-height:44px;margin-right:0.25rem;">👁️ Inhoud</button>
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
    }

    updateGroupAddSentinel() {
        const sentinel = document.getElementById('groupAddLoadSentinel');
        if (!sentinel) return;
        if (this.groupSearchLoading) {
            sentinel.textContent = 'Meer groepen laden...';
        } else if (this.groupSearchHasMore) {
            sentinel.textContent = '';
        } else if (this.groupSearchItems.size > 0) {
            sentinel.textContent = `Alle ${this.groupSearchItems.size} groepen geladen`;
        } else {
            sentinel.textContent = '';
        }
    }

    setupGroupAddInfiniteScroll(resultsEl) {
        if (this.groupAddObserver) {
            this.groupAddObserver.disconnect();
            this.groupAddObserver = null;
        }
        const sentinel = document.getElementById('groupAddLoadSentinel');
        if (!sentinel || !resultsEl) return;

        if ('IntersectionObserver' in window) {
            this.groupAddObserver = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting && this.groupSearchHasMore && !this.groupSearchLoading) {
                        this.searchAndRenderAddGroups(true);
                    }
                }
            }, {
                root: resultsEl,
                rootMargin: '120px 0px',
                threshold: 0.01,
            });
            this.groupAddObserver.observe(sentinel);
        }

        if (!resultsEl.dataset.scrollBound) {
            resultsEl.dataset.scrollBound = '1';
            resultsEl.addEventListener('scroll', () => {
                if (!this.groupSearchHasMore || this.groupSearchLoading) return;
                const nearBottom = resultsEl.scrollTop + resultsEl.clientHeight >= resultsEl.scrollHeight - 120;
                if (nearBottom) {
                    this.searchAndRenderAddGroups(true);
                }
            });
        }
    }

    async searchAndRenderAddGroups(append = false) {
        const resultsEl = document.getElementById('groupAddResults');
        if (!resultsEl) return;
        const q = (document.getElementById('groupAddSearchInput')?.value || '').trim();
        const status = document.getElementById('groupAddStatusFilter')?.value || 'disabled';
        const categories = Array.from(this.selectedGroupCategories).join(',');

        if (!append) {
            this.groupSearchOffset = 0;
            this.groupSearchHasMore = false;
            this.groupSearchItems.clear();
            this.groupSearchLoading = true;
            resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Zoeken...</div>`;
        } else {
            if (!this.groupSearchHasMore || this.groupSearchLoading) return;
            this.groupSearchLoading = true;
            this.updateGroupAddSentinel();
        }

        const reqId = ++this.groupSearchRequestId;
        const res = await this.props.api.get('groups_search', {
            q,
            status,
            categories,
            limit: 100,
            offset: this.groupSearchOffset,
        });

        if (reqId !== this.groupSearchRequestId) return;
        this.groupSearchLoading = false;

        const items = Array.isArray(res?.items) ? res.items : (Array.isArray(res) ? res : []);
        this.groupSearchHasMore = Boolean(res?.has_more);
        this.groupSearchOffset = Number.isFinite(res?.next_offset)
            ? res.next_offset
            : (this.groupSearchOffset + items.length);

        const newItems = [];
        for (const g of items) {
            const key = String(g.id);
            if (!this.groupSearchItems.has(key)) {
                this.groupSearchItems.set(key, g);
                newItems.push(g);
            }
        }

        if (!append) {
            if (this.groupSearchItems.size === 0) {
                resultsEl.innerHTML = `<div class="muted" style="padding:0.5rem;">Geen groepen gevonden.</div>`;
                return;
            }
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
                        ${newItems.map(g => this.renderGroupAddRow(g)).join('')}
                    </tbody>
                </table>
                <div id="groupAddLoadSentinel" class="group-add-sentinel"></div>
            `;
            this.setupGroupAddInfiniteScroll(resultsEl);
        } else if (newItems.length > 0) {
            const tbody = resultsEl.querySelector('tbody');
            if (tbody) {
                tbody.insertAdjacentHTML('beforeend', newItems.map(g => this.renderGroupAddRow(g)).join(''));
            }
        }

        this.updateGroupAddSentinel();

        resultsEl.querySelectorAll('.group-preview-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id);
                const groupCt = btn.dataset.ct || 'live';
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

                const allGroups = this.groupList?.state?.groups || [];
                const compatibleCustom = allGroups.filter(
                    g => Number(g.is_custom || 0) === 1 && (g.content_type || 'live') === groupCt
                );

                const renderPreviewRows = (memberIds = []) => {
                    const memberSet = new Set(memberIds.map(Number));
                    const targetBar = compatibleCustom.length > 0 ? `
                        <div class="preview-target-bar">
                            <label class="muted" style="font-size:0.85rem;">Doelgroep:</label>
                            <select class="preview-target-custom-group" style="min-height:44px;font-size:16px;">
                                ${compatibleCustom.map(g => `<option value="${g.id}">${this.escapeHtml(g.custom_name || g.original_name || '')}</option>`).join('')}
                            </select>
                        </div>
                    ` : '';

                    const rowsHtml = chList.map(c => {
                        const chName = this.escapeHtml(c.display_name || c.custom_name || c.tvg_name || '');
                        const on = Number(c.is_enabled) === 1;
                        const isMember = memberSet.has(Number(c.id));
                        const actionHtml = compatibleCustom.length > 0
                            ? (isMember
                                ? `<button type="button" class="btn btn-xs-danger js-preview-toggle-ch" data-cid="${c.id}" data-in-group="1">Verwijderen</button>`
                                : `<button type="button" class="btn btn-xs-success js-preview-toggle-ch" data-cid="${c.id}" data-in-group="0">+ Toevoegen</button>`)
                            : '';
                        return `<div class="preview-channel-row"><span>${chName}</span><div style="display:flex;align-items:center;gap:0.5rem;"><span class="muted">${on ? '✅ Aan' : '⬜ Uit'}</span>${actionHtml}</div></div>`;
                    }).join('');

                    box.innerHTML = targetBar + rowsHtml;

                    const sel = box.querySelector('.preview-target-custom-group');
                    if (sel) {
                        if (box.dataset.selectedTargetId) {
                            sel.value = box.dataset.selectedTargetId;
                        }
                        sel.addEventListener('change', async () => {
                            box.dataset.selectedTargetId = sel.value;
                            const mRes = await this.props.api.get('custom_group_member_ids', { custom_group_id: sel.value });
                            renderPreviewRows(mRes?.member_ids || []);
                        });
                    }

                    box.querySelectorAll('.js-preview-toggle-ch').forEach(toggleBtn => {
                        toggleBtn.addEventListener('click', async () => {
                            const targetId = Number(box.querySelector('.preview-target-custom-group')?.value || compatibleCustom[0]?.id || 0);
                            const cid = Number(toggleBtn.dataset.cid);
                            const inGroup = toggleBtn.dataset.inGroup === '1';
                            if (!targetId || !cid) return;

                            toggleBtn.disabled = true;
                            try {
                                if (inGroup) {
                                    await this.props.api.post('custom_group_remove_channels', {
                                        custom_group_id: targetId,
                                        channel_ids: [cid],
                                    });
                                    memberSet.delete(cid);
                                    toggleBtn.dataset.inGroup = '0';
                                    toggleBtn.textContent = '+ Toevoegen';
                                    toggleBtn.classList.remove('btn-xs-danger');
                                    toggleBtn.classList.add('btn-xs-success');
                                    this.props.toast?.show('Kanaal verwijderd uit custom groep');
                                } else {
                                    await this.props.api.post('custom_group_add_channels', {
                                        custom_group_id: targetId,
                                        channel_ids: [cid],
                                    });
                                    memberSet.add(cid);
                                    toggleBtn.dataset.inGroup = '1';
                                    toggleBtn.textContent = 'Verwijderen';
                                    toggleBtn.classList.remove('btn-xs-success');
                                    toggleBtn.classList.add('btn-xs-danger');
                                    this.props.toast?.show('Kanaal toegevoegd aan custom groep');
                                }
                                await this.loadGroupsOnly?.();
                            } finally {
                                toggleBtn.disabled = false;
                            }
                        });
                    });
                };

                if (compatibleCustom.length > 0) {
                    const firstTargetId = compatibleCustom[0].id;
                    box.dataset.selectedTargetId = String(firstTargetId);
                    const mRes = await this.props.api.get(`custom_group_member_ids&custom_group_id=${firstTargetId}`);
                    renderPreviewRows(mRes?.member_ids || []);
                } else {
                    renderPreviewRows([]);
                }
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
        await this.updateCustomGroupUi();
    }

    renderShell() {
        this.container.innerHTML = `
            <div class="split-view">
                <aside class="card groups-pane">
                    <div class="card-header">
                        <h2>Groepen</h2>
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-primary" id="openGroupSearchBtn" style="min-height:44px;">📋 Lijst groepen</button>
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
                            <button type="button" class="btn btn-sm hidden" id="convertToCustomBtn" title="Zet deze provider-groep om naar een bewerkbare custom groep">🛠️ Omzetten naar custom groep</button>
                            <button type="button" class="btn btn-sm btn-primary hidden" id="openCustomChannelPickerBtn" style="min-height:44px;">➕ Kanalen toevoegen uit database</button>
                            <button type="button" class="btn btn-sm btn-danger hidden" id="deleteCustomGroupBtn" title="Verwijder custom groep">🗑️ Verwijder custom groep</button>
                            <button type="button" class="btn btn-sm btn-danger hidden" id="removeFromCustomGroupBtn" title="Verwijder geselecteerde kanalen uit deze custom groep">🗑️ Verwijder uit groep</button>
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
                    <div id="customGroupTargetBar" class="toolbar hidden" style="align-items:center;gap:0.5rem;flex-wrap:wrap;">
                        <label for="targetCustomGroupSelect" class="muted" style="font-size:0.9rem;">Toevoegen aan custom groep:</label>
                        <select id="targetCustomGroupSelect" style="min-height:44px;font-size:16px;min-width:180px;"></select>
                        <button type="button" class="btn btn-sm btn-primary" id="addToCustomGroupBtn" disabled style="min-height:44px;">➕ Voeg toe (0)</button>
                    </div>
                    <div id="customGroupSyncBannerRoot" class="hidden"></div>
                    <div id="groupChannelToolsMount"></div>
                    <div class="table-wrap" id="channelsTableWrap">
                        <table class="data-table">
                            <thead id="channelsTableHead">
                                <tr>
                                    <th>Aan</th>
                                    <th id="sortChannelsByNum" style="cursor:pointer;user-select:none;" title="Sorteer op kanaalnummer">#</th>
                                    <th id="sortChannelsByName" style="cursor:pointer;user-select:none;" title="Klik om te sorteren: A-Z / Z-A / Import">Naam (Origineel / Custom) <span id="channelNameSortBadge" class="muted" style="font-size:0.8rem;font-weight:normal;"></span></th>
                                </tr>
                            </thead>
                            <tbody id="channelsTableRoot"></tbody>
                        </table>
                    </div>
                    <div id="customGroupAvailableChannelsRoot" class="hidden"></div>
                    </div>
                    <div id="groupAddPanel" class="hidden">
                        <div class="card-header">
                            <h2>Groep toevoegen</h2>
                            <button type="button" class="btn btn-sm btn-primary" id="toggleNewCustomGroupBtn" style="min-height:44px;">➕ Nieuwe groep</button>
                        </div>
                        <div id="newCustomGroupForm" class="toolbar hidden" style="gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                            <input type="text" id="newCustomGroupName" placeholder="Naam van nieuwe custom groep..." style="flex:1;min-width:180px;font-size:16px;min-height:44px;">
                            <select id="newCustomGroupCategory" style="font-size:16px;min-height:44px;">
                                <option value="Live TV">Live TV</option>
                                <option value="Sport">Sport</option>
                                <option value="Animatie">Animatie</option>
                                <option value="Muziek">Muziek</option>
                                <option value="Adult">Adult</option>
                                <option value="Film">Film</option>
                                <option value="TV Series">TV Series</option>
                            </select>
                            <button type="button" class="btn btn-sm btn-primary" id="saveNewCustomGroupBtn" style="min-height:44px;">Aanmaken</button>
                            <button type="button" class="btn btn-sm" id="cancelNewCustomGroupBtn" style="min-height:44px;">Annuleren</button>
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
                    onUpdated: async () => {
                        await this.loadChannels();
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
                const adoptBtn = document.getElementById('bulkAdoptNamesBtn');
                const resetBtn = document.getElementById('bulkResetNamesBtn');
                if (enableBtn) enableBtn.textContent = count > 0 ? `Selectie Aan (${count})` : 'Selectie Aan';
                if (disableBtn) disableBtn.textContent = count > 0 ? `Selectie Uit (${count})` : 'Selectie Uit';
                if (adoptBtn) adoptBtn.textContent = count > 0 ? `🏷️ Selectie Custom (${count})` : '🏷️ Selectie Custom';
                if (resetBtn) resetBtn.textContent = count > 0 ? `↩️ Selectie Origineel (${count})` : '↩️ Selectie Origineel';
                const addToCustomBtn = document.getElementById('addToCustomGroupBtn');
                if (addToCustomBtn) {
                    addToCustomBtn.textContent = `➕ Voeg toe (${count})`;
                    addToCustomBtn.disabled = count === 0;
                }
            }
        });

        this.syncBanner = new CustomGroupSyncBanner('#customGroupSyncBannerRoot', {
            onResolve: async ({ addIds, ignoreAddIds, removeIds }) => {
                if (!this.selectedGroupId) return;
                await this.props.api.post('custom_group_resolve_sync', {
                    custom_group_id: this.selectedGroupId,
                    add_ids: addIds,
                    ignore_add_ids: ignoreAddIds,
                    remove_ids: removeIds,
                });
                this.props.toast.show('Sync-wijzigingen verwerkt');
                await this.load();
            }
        });

        this.channelPicker = new CustomGroupChannelPicker('#customGroupChannelPickerRoot', {
            api: this.props.api,
            toast: this.props.toast,
            onAdded: async () => {
                await this.load();
            }
        });

        this.availableChannels = new CustomGroupAvailableChannels('#customGroupAvailableChannelsRoot', {
            api: this.props.api,
            toast: this.props.toast,
            onChanged: async () => {
                await this.loadChannels();
                await this.loadGroupsOnly?.();
            }
        });

        this.bindCustomGroupEvents();

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
                    <th class="ch-sort-th" data-sort="name" style="cursor:pointer;user-select:none;${nameColor}">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.5rem;flex-wrap:wrap;">
                            <span>Naam van het kanaal <span style="font-size:0.75rem;">${nameIcon}</span></span>
                            <span style="display:inline-flex;gap:0.35rem;flex-wrap:wrap;">
                                <button type="button" class="btn btn-sm" id="bulkAdoptNamesBtn" title="Actieve kanalen in deze groep omzetten naar custom naam incl. vlag" style="min-height:44px;">🏷️ Actieve Custom</button>
                                <button type="button" class="btn btn-sm" id="bulkResetNamesBtn" title="Actieve kanalen in deze groep herstellen naar originele naam" style="min-height:44px;">↩️ Actieve Origineel</button>
                            </span>
                        </div>
                    </th>
                </tr>`;
        }

        document.getElementById('selectAllChannelsCheckbox')?.addEventListener('change', e => {
            const checked = e.target.checked;
            document.querySelectorAll('#channelsTableRoot tr[data-cid]').forEach(tr => {
                const cb = tr.querySelector('.ch-select');
                if (cb) {
                    cb.checked = checked;
                    tr.classList.toggle('row-selected', checked);
                }
            });
            this.channelTable?.props?.onSelectionChange?.(this.channelTable?.getSelectedIds().length || 0);
        });

        document.getElementById('bulkAdoptNamesBtn')?.addEventListener('click', async e => {
            e.stopPropagation();
            if (!this.selectedGroupId) return;
            await this.props.api.post('group_channel_tools', {
                group_id: this.selectedGroupId,
                mode: 'adopt_all_display_names',
            });
            this.props.toast?.show('Actieve kanaalnamen omgezet naar custom');
            await this.loadChannels();
            this.channelTable?.props?.onSelectionChange?.(0);
        });

        document.getElementById('bulkResetNamesBtn')?.addEventListener('click', async e => {
            e.stopPropagation();
            if (!this.selectedGroupId) return;
            await this.props.api.post('group_channel_tools', {
                group_id: this.selectedGroupId,
                mode: 'reset_all_custom_names',
            });
            this.props.toast?.show('Actieve kanaalnamen hersteld naar origineel');
            await this.loadChannels();
            this.channelTable?.props?.onSelectionChange?.(0);
        });

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

    bindCustomGroupEvents() {
        const toggleBtn = document.getElementById('toggleNewCustomGroupBtn');
        const form = document.getElementById('newCustomGroupForm');
        const cancelBtn = document.getElementById('cancelNewCustomGroupBtn');
        const saveBtn = document.getElementById('saveNewCustomGroupBtn');
        const nameInput = document.getElementById('newCustomGroupName');
        const catSelect = document.getElementById('newCustomGroupCategory');

        toggleBtn?.addEventListener('click', () => {
            form?.classList.toggle('hidden');
            if (form && !form.classList.contains('hidden')) {
                nameInput?.focus();
            }
        });

        cancelBtn?.addEventListener('click', () => {
            form?.classList.add('hidden');
            if (nameInput) nameInput.value = '';
        });

        saveBtn?.addEventListener('click', async () => {
            const name = (nameInput?.value || '').trim();
            const category = catSelect?.value || 'Live TV';
            if (!name) {
                this.props.toast?.show('Vul een groepsnaam in', 'error');
                return;
            }
            const created = await this.props.api.post('create_custom_group', { name, category });
            form?.classList.add('hidden');
            if (nameInput) nameInput.value = '';
            this.isAddGroupMode = false;
            document.getElementById('groupAddPanel')?.classList.add('hidden');
            document.getElementById('channelsViewContainer')?.classList.remove('hidden');
            document.getElementById('openGroupSearchBtn')?.classList.remove('btn-danger');
            if (created?.id) {
                this.selectedGroupId = Number(created.id);
                this.selectedGroupName = created.custom_name || created.original_name || name;
            }
            this.props.toast?.show('Custom groep aangemaakt');
            await this.load();
        });

        document.getElementById('convertToCustomBtn')?.addEventListener('click', async () => {
            if (!this.selectedGroupId) return;
            if (!confirm('Weet je zeker dat je deze provider-groep wilt omzetten naar een custom groep?')) return;
            const created = await this.props.api.post('convert_to_custom_group', {
                source_group_id: this.selectedGroupId,
            });
            if (created?.id) {
                this.selectedGroupId = Number(created.id);
                this.selectedGroupName = created.custom_name || created.original_name || this.selectedGroupName;
            }
            this.props.toast?.show('Groep omgezet naar custom groep');
            await this.load();
        });

        document.getElementById('openCustomChannelPickerBtn')?.addEventListener('click', async () => {
            if (!this.selectedGroupId) return;
            this.isCustomAddMode = !this.isCustomAddMode;
            await this.updateCustomGroupUi();
            if (!this.isCustomAddMode) {
                await this.loadChannels();
            }
        });

        document.getElementById('deleteCustomGroupBtn')?.addEventListener('click', async () => {
            if (!this.selectedGroupId) return;
            if (!confirm('Weet je zeker dat je deze custom groep wilt verwijderen?')) return;
            await this.props.api.post('delete_custom_group', {
                custom_group_id: this.selectedGroupId,
                restore_source: true,
            });
            this.selectedGroupId = null;
            this.selectedGroupName = '';
            this.props.toast?.show('Custom groep verwijderd');
            await this.load();
        });

        document.getElementById('removeFromCustomGroupBtn')?.addEventListener('click', async () => {
            if (!this.selectedGroupId) return;
            const ids = this.channelTable?.getSelectedIds?.() || [];
            if (ids.length === 0) {
                this.props.toast?.show('Selecteer eerst kanalen om te verwijderen', 'error');
                return;
            }
            await this.props.api.post('custom_group_remove_channels', {
                custom_group_id: this.selectedGroupId,
                channel_ids: ids,
            });
            ids.forEach(cid => this.availableChannels?.markChannelMembership(cid, false));
            this.props.toast?.show(`${ids.length} kanaal/kanalen verwijderd uit custom groep`);
            await this.load();
        });

        document.getElementById('targetCustomGroupSelect')?.addEventListener('change', async () => {
            await this.loadTargetMemberIds();
        });

        document.getElementById('addToCustomGroupBtn')?.addEventListener('click', async () => {
            const targetSelect = document.getElementById('targetCustomGroupSelect');
            const targetId = Number(targetSelect?.value || 0);
            const ids = this.channelTable?.getSelectedIds?.() || [];
            if (!targetId || ids.length === 0) return;

            const res = await this.props.api.post('custom_group_add_channels', {
                custom_group_id: targetId,
                channel_ids: ids,
            });
            const targetName = targetSelect?.options[targetSelect.selectedIndex]?.textContent || '';
            this.channelTable?.setState({
                targetMemberIds: res?.member_ids || [],
                targetGroupName: targetName,
            });
            this.channelTable?.props?.onSelectionChange?.(0);
            this.props.toast?.show(`${res?.added ?? 0} kanaal/kanalen toegevoegd aan ${targetName}`);
            await this.loadGroupsOnly?.();
        });
    }

    async updateCustomGroupUi() {
        const groups = this.groupList?.state?.groups || [];
        const selected = this.selectedGroupId
            ? groups.find(g => Number(g.id) === Number(this.selectedGroupId))
            : null;
        const isCustom = selected && Number(selected.is_custom || 0) === 1;

        document.getElementById('convertToCustomBtn')?.classList.toggle('hidden', !selected || isCustom);
        document.getElementById('openCustomChannelPickerBtn')?.classList.toggle('hidden', !isCustom);
        document.getElementById('deleteCustomGroupBtn')?.classList.toggle('hidden', !isCustom);
        document.getElementById('removeFromCustomGroupBtn')?.classList.toggle('hidden', !isCustom);
        if (!isCustom) {
            this.isCustomAddMode = false;
            this.channelPicker?.close();
        }

        const pickerBtn = document.getElementById('openCustomChannelPickerBtn');
        if (pickerBtn) {
            pickerBtn.textContent = this.isCustomAddMode
                ? '← Terug naar groepsinhoud'
                : '➕ Kanalen toevoegen uit database';
        }

        const tableWrap = document.getElementById('channelsTableWrap');
        const toolsMount = document.getElementById('groupChannelToolsMount');
        if (isCustom && this.isCustomAddMode) {
            tableWrap?.classList.add('hidden');
            toolsMount?.classList.add('hidden');
        } else {
            tableWrap?.classList.remove('hidden');
            toolsMount?.classList.remove('hidden');
        }

        if (isCustom && selected) {
            this.syncBanner?.setData(Number(selected.id), selected.sync_diff_json || null);
            if (this.isCustomAddMode) {
                const filters = {
                    q: document.getElementById('channelSearchInput')?.value || '',
                    country_tag_id: document.getElementById('channelCountryFilter')?.value || '',
                    status: document.getElementById('channelStatusFilter')?.value || 'all',
                    has_epg: document.getElementById('channelEpgFilter')?.value || 'all',
                };
                if (this.availableChannels?.customGroupId === Number(selected.id)) {
                    await this.availableChannels.setFilters(filters);
                } else {
                    await this.availableChannels?.mount(Number(selected.id), filters);
                }
            } else {
                this.availableChannels?.unmount();
            }
        } else {
            this.syncBanner?.setData(null, null);
            this.availableChannels?.unmount();
        }

        const targetBar = document.getElementById('customGroupTargetBar');
        const targetSelect = document.getElementById('targetCustomGroupSelect');
        if (!selected || isCustom) {
            targetBar?.classList.add('hidden');
            this.channelTable?.setState({ targetMemberIds: [], targetGroupName: '' });
            return;
        }

        const sourceCt = selected.content_type || 'live';
        const compatibleCustomGroups = groups.filter(
            g => Number(g.is_custom || 0) === 1 && (g.content_type || 'live') === sourceCt
        );

        if (compatibleCustomGroups.length === 0 || !targetSelect) {
            targetBar?.classList.add('hidden');
            this.channelTable?.setState({ targetMemberIds: [], targetGroupName: '' });
            return;
        }

        const prevVal = targetSelect.value;
        targetSelect.innerHTML = compatibleCustomGroups.map(g => {
            const label = g.custom_name || g.original_name || `Custom #${g.id}`;
            return `<option value="${g.id}">${this.escapeHtml(label)}</option>`;
        }).join('');
        if (prevVal && compatibleCustomGroups.some(g => String(g.id) === String(prevVal))) {
            targetSelect.value = prevVal;
        }

        targetBar?.classList.remove('hidden');
        await this.loadTargetMemberIds();
    }

    async loadTargetMemberIds() {
        const targetSelect = document.getElementById('targetCustomGroupSelect');
        const targetId = Number(targetSelect?.value || 0);
        if (!targetId) {
            this.channelTable?.setState({ targetMemberIds: [], targetGroupName: '' });
            return;
        }
        const targetName = targetSelect?.options[targetSelect.selectedIndex]?.textContent || '';
        try {
            const res = await this.props.api.get('custom_group_member_ids', { custom_group_id: targetId });
            this.channelTable?.setState({
                targetMemberIds: res?.member_ids || [],
                targetGroupName: targetName,
            });
        } catch {
            this.channelTable?.setState({ targetMemberIds: [], targetGroupName: targetName });
        }
    }
}
