import { BaseComponent } from '../core/BaseComponent.js';

export class CustomGroupAvailableChannels extends BaseComponent {
    constructor(el, { api, toast, onChanged } = {}) {
        super(el);
        this.api = api;
        this.toast = toast;
        this.onChanged = onChanged || (() => {});
        this.customGroupId = null;
        this.filters = {
            q: '',
            country_tag_id: '',
            status: 'all',
            has_epg: 'all',
        };
        this.items = [];
        this.seenIds = new Set();
        this.offset = 0;
        this.hasMore = false;
        this.isLoading = false;
        this.observer = null;
    }

    async mount(customGroupId, filters = {}) {
        this.customGroupId = Number(customGroupId);
        this.filters = { ...this.filters, ...filters };
        this.container?.classList.remove('hidden');
        await this.reload();
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

    async setFilters(filters = {}) {
        if (!this.customGroupId) return;
        this.filters = { ...this.filters, ...filters };
        await this.reload();
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

    async reload() {
        this.offset = 0;
        this.hasMore = false;
        this.items = [];
        this.seenIds.clear();
        this.renderShell();
        await this.fetchNextBatch();
    }

    async fetchNextBatch() {
        if (!this.customGroupId || this.isLoading) return;
        this.isLoading = true;
        this.updateStatusText('Kanalen laden...');

        const params = {
            custom_group_id: this.customGroupId,
            q: this.filters.q || '',
            country_tag_id: this.filters.country_tag_id || '',
            status: this.filters.status || 'all',
            has_epg: this.filters.has_epg && this.filters.has_epg !== 'all' ? this.filters.has_epg : '',
            limit: 50,
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
            this.updateStatusText(
                this.items.length === 0
                    ? 'Geen compatibele kanalen gevonden voor de huidige filters.'
                    : (this.hasMore ? 'Scroll omlaag voor meer...' : `${this.items.length} kanalen geladen`)
            );
            this.setupObserver();
        }
    }

    renderShell() {
        if (!this.container) return;
        this.disconnectObserver();
        this.container.innerHTML = `
            <div class="avail-channels-section">
                <div class="avail-channels-header">
                    <strong>Beschikbare compatibele kanalen (database)</strong>
                    <span class="muted js-avail-count" style="font-size:0.85rem;"></span>
                </div>
                <div class="avail-channels-list js-avail-list"></div>
                <div class="avail-channels-sentinel js-avail-sentinel"></div>
                <div class="muted js-avail-status" style="padding:0.5rem;text-align:center;font-size:0.85rem;"></div>
            </div>
        `;
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

        const countEl = this.container?.querySelector('.js-avail-count');
        if (countEl) {
            countEl.textContent = `${this.items.length} getoond`;
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

    updateStatusText(text) {
        const el = this.container?.querySelector('.js-avail-status');
        if (el) el.textContent = text;
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
