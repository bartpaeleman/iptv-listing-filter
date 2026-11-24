import { BaseComponent } from '../core/BaseComponent.js';

export class CustomGroupAvailableChannels extends BaseComponent {
    constructor(el, { api, toast, onChanged } = {}) {
        super(el);
        this.api = api;
        this.toast = toast;
        this.onChanged = onChanged || (() => {});
        this.customGroupId = null;
        this.customGroupCategory = 'Live TV';
        this.countryTags = [];
        this.filters = {
            q: '',
            country_tag: '',
            status: 'all',
            category: '',
        };
        this.items = [];
        this.seenIds = new Set();
        this.offset = 0;
        this.hasMore = false;
        this.isLoading = false;
        this.observer = null;
    }

    async mount(customGroupId, { category = 'Live TV', countryTags = [] } = {}) {
        this.customGroupId = Number(customGroupId);
        this.customGroupCategory = category || 'Live TV';
        this.countryTags = Array.isArray(countryTags) ? countryTags : [];
        this.filters = {
            q: '',
            country_tag: '',
            status: 'all',
            category: '',
        };
        this.container?.classList.remove('hidden');
        this.renderShell();
        await this.executeSearch();
    }

    unmount() {
        this.customGroupId = null;
        this.items = [];
        this.seenIds.clear();
        this.disconnectObserver();
        if (this.container) {
            this.container.innerHTML = '';
            this.container.classList.add('hidden');
        }
    }

    markChannelMembership(channelId, inCustomGroup) {
        const cid = Number(channelId);
        this.items = this.items.map(item =>
            Number(item.id) === cid
                ? { ...item, in_custom_group: inCustomGroup, already_in_custom: inCustomGroup }
                : item
        );
        const btn = this.container?.querySelector(`.js-avail-toggle-btn[data-cid="${cid}"]`);
        if (btn) {
            if (inCustomGroup) {
                btn.textContent = 'Verwijderen';
                btn.classList.remove('btn-avail-add');
                btn.classList.add('btn-avail-remove');
                btn.dataset.inGroup = '1';
            } else {
                btn.textContent = '+ Toevoegen';
                btn.classList.remove('btn-avail-remove');
                btn.classList.add('btn-avail-add');
                btn.dataset.inGroup = '0';
            }
        }
    }

    getAllowedCategories() {
        if (this.customGroupCategory === 'Film') return ['Film'];
        if (this.customGroupCategory === 'TV Series') return ['TV Series'];
        return ['Live TV', 'Sport', 'Animatie', 'Muziek', 'Adult'];
    }

    async executeSearch() {
        this.readFilterInputs();
        this.offset = 0;
        this.hasMore = false;
        this.items = [];
        this.seenIds.clear();
        const listEl = this.container?.querySelector('.js-avail-list');
        if (listEl) listEl.innerHTML = '';
        await this.fetchNextBatch();
    }

    readFilterInputs() {
        const tagEl = this.container?.querySelector('#dbFilterCountryTag');
        const qEl = this.container?.querySelector('#dbFilterQuery');
        const statusEl = this.container?.querySelector('#dbFilterStatus');
        const catEl = this.container?.querySelector('#dbFilterCategory');
        if (tagEl) this.filters.country_tag = tagEl.value;
        if (qEl) this.filters.q = qEl.value.trim();
        if (statusEl) this.filters.status = statusEl.value;
        if (catEl) this.filters.category = catEl.value;
    }

    async fetchNextBatch() {
        if (!this.customGroupId || this.isLoading) return;
        this.isLoading = true;
        this.updateFooter();

        const params = {
            custom_group_id: this.customGroupId,
            q: this.filters.q || '',
            country_tag: this.filters.country_tag || '',
            status: this.filters.status || 'all',
            category: this.filters.category || '',
            limit: 20,
            offset: this.offset,
        };

        try {
            const res = await this.api.get('search_compatible_channels', params);
            const batch = Array.isArray(res?.items) ? res.items : [];
            const newRows = [];
            for (const row of batch) {
                const cid = Number(row.id);
                if (!this.seenIds.has(cid)) {
                    this.seenIds.add(cid);
                    this.items.push(row);
                    newRows.push(row);
                }
            }
            this.hasMore = Boolean(res?.has_more);
            this.offset = Number(res?.next_offset ?? this.items.length);
            this.appendRows(newRows);
        } finally {
            this.isLoading = false;
            this.updateFooter();
            this.setupObserver();
        }
    }

    renderShell() {
        if (!this.container) return;
        this.disconnectObserver();

        const tagOptions = this.countryTags.map(t => {
            const rawPattern = String(t.tag_pattern || '');
            const cleanCode = rawPattern.replace(/[\[\]()|]/g, '').trim();
            const label = `${t.flag_emoji || ''} ${t.country_name || cleanCode} (${cleanCode})`.trim();
            return `<option value="${this.escapeHtml(cleanCode)}">${this.escapeHtml(label)}</option>`;
        }).join('');

        const allowedCats = this.getAllowedCategories();
        const catOptions = allowedCats.length > 1
            ? `<option value="">Alle Live categorieën</option>` + allowedCats.map(c => `<option value="${c}">${c}</option>`).join('')
            : allowedCats.map(c => `<option value="${c}">${c}</option>`).join('');

        this.container.innerHTML = `
            <div class="avail-channels-section">
                <div class="db-channel-filter-bar">
                    <select id="dbFilterCountryTag" style="min-height:44px;font-size:16px;">
                        <option value="">Alle landen/tags</option>
                        ${tagOptions}
                    </select>
                    <input type="search" id="dbFilterQuery" placeholder="Zoek kanaalnaam..." style="flex:2;min-width:180px;min-height:44px;font-size:16px;">
                    <select id="dbFilterStatus" style="min-height:44px;font-size:16px;">
                        <option value="all">Alle statussen</option>
                        <option value="enabled">Actief</option>
                        <option value="disabled">Inactief</option>
                    </select>
                    <select id="dbFilterCategory" style="min-height:44px;font-size:16px;">
                        ${catOptions}
                    </select>
                    <button type="button" class="btn btn-sm btn-primary" id="dbFilterSearchBtn" style="min-height:44px;">🔍 Zoeken</button>
                </div>
                <div class="avail-channels-header">
                    <strong>Beschikbare compatibele kanalen (database)</strong>
                    <span class="muted js-avail-count" style="font-size:0.85rem;">0 kanalen geladen</span>
                </div>
                <div class="avail-channels-list js-avail-list"></div>
                <div class="avail-channels-sentinel js-avail-sentinel"></div>
                <div class="db-avail-footer">
                    <button type="button" class="btn btn-sm btn-load-more hidden" id="dbLoadMoreBtn" style="min-height:44px;">⬇️ Volgende 20 laden</button>
                    <div class="muted js-avail-status" style="padding:0.4rem;text-align:center;font-size:0.85rem;"></div>
                </div>
            </div>
        `;

        this.bindShellEvents();
    }

    bindShellEvents() {
        this.container.querySelector('#dbFilterSearchBtn')?.addEventListener('click', () => this.executeSearch());
        this.container.querySelector('#dbFilterQuery')?.addEventListener('keydown', e => {
            if (e.key === 'Enter') {
                e.preventDefault();
                this.executeSearch();
            }
        });
        this.container.querySelector('#dbLoadMoreBtn')?.addEventListener('click', () => this.fetchNextBatch());
    }

    appendRows(rows) {
        const listEl = this.container?.querySelector('.js-avail-list');
        if (!listEl) return;

        const fragment = document.createElement('div');
        fragment.innerHTML = rows.map(ch => {
            const inGroup = Boolean(ch.in_custom_group ?? ch.already_in_custom);
            const hasEpg = Boolean(ch.epg_channel_id && String(ch.epg_channel_id).trim() !== '');
            const epgBadge = hasEpg
                ? '<span class="badge badge-epg-ok" title="EPG gekoppeld">EPG</span>'
                : '<span class="badge badge-epg-none" title="Geen EPG">Geen EPG</span>';
            const btnClass = inGroup ? 'btn-avail-remove' : 'btn-avail-add';
            const btnText = inGroup ? 'Verwijderen' : '+ Toevoegen';

            return `
                <div class="avail-channel-row" data-cid="${ch.id}">
                    <div class="avail-channel-info">
                        <span class="avail-channel-name">${this.escapeHtml(ch.display_name || ch.tvg_name || '')}</span>
                        <span class="badge badge-group-source">${this.escapeHtml(ch.group_display_name || '')}</span>
                        ${epgBadge}
                    </div>
                    <button type="button" class="btn btn-sm js-avail-toggle-btn ${btnClass}" data-cid="${ch.id}" data-in-group="${inGroup ? '1' : '0'}">${btnText}</button>
                </div>
            `;
        }).join('');

        while (fragment.firstElementChild) {
            const rowEl = fragment.firstElementChild;
            const btn = rowEl.querySelector('.js-avail-toggle-btn');
            btn?.addEventListener('click', () => this.handleToggle(btn));
            listEl.appendChild(rowEl);
        }
    }

    async handleToggle(btn) {
        if (!this.customGroupId) return;
        const cid = Number(btn.dataset.cid);
        const currentlyInGroup = btn.dataset.inGroup === '1';
        btn.disabled = true;

        try {
            if (currentlyInGroup) {
                await this.api.post('custom_group_remove_channels', {
                    custom_group_id: this.customGroupId,
                    channel_ids: [cid],
                });
                this.markChannelMembership(cid, false);
                this.toast?.show('Kanaal verwijderd uit custom groep');
            } else {
                await this.api.post('custom_group_add_channels', {
                    custom_group_id: this.customGroupId,
                    channel_ids: [cid],
                });
                this.markChannelMembership(cid, true);
                this.toast?.show('Kanaal toegevoegd aan custom groep');
            }
            await this.onChanged();
        } finally {
            btn.disabled = false;
        }
    }

    updateFooter() {
        const countEl = this.container?.querySelector('.js-avail-count');
        const statusEl = this.container?.querySelector('.js-avail-status');
        const loadMoreBtn = this.container?.querySelector('#dbLoadMoreBtn');

        if (countEl) {
            countEl.textContent = this.hasMore
                ? `${this.items.length} kanalen geladen`
                : `Alle ${this.items.length} resultaten geladen`;
        }
        if (loadMoreBtn) {
            loadMoreBtn.classList.toggle('hidden', !this.hasMore || this.isLoading);
        }
        if (statusEl) {
            if (this.isLoading) {
                statusEl.textContent = 'Kanalen laden...';
            } else if (this.items.length === 0) {
                statusEl.textContent = 'Geen compatibele kanalen gevonden voor de huidige filters.';
            } else {
                statusEl.textContent = this.hasMore
                    ? 'Scroll omlaag of klik op "Volgende 20 laden"'
                    : `Alle ${this.items.length} resultaten geladen`;
            }
        }
    }

    disconnectObserver() {
        if (this.observer) {
            this.observer.disconnect();
            this.observer = null;
        }
    }

    setupObserver() {
        this.disconnectObserver();
        if (!this.hasMore) return;
        const sentinel = this.container?.querySelector('.js-avail-sentinel');
        if (!sentinel || typeof IntersectionObserver === 'undefined') return;

        this.observer = new IntersectionObserver(entries => {
            if (entries.some(e => e.isIntersecting) && this.hasMore && !this.isLoading) {
                this.fetchNextBatch();
            }
        }, { rootMargin: '200px' });
        this.observer.observe(sentinel);
    }
}
