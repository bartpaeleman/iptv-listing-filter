import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';

export class CustomGroupChannelPicker extends BaseComponent {
    constructor(el, { api, toast, onAdded } = {}) {
        super(el);
        this.api = api;
        this.toast = toast;
        this.onAdded = onAdded || (() => {});
        this.customGroupId = null;
        this.customGroupName = '';
        this.items = [];
        this.compatibleGroups = [];
        this.selectedIds = new Set();
        this.query = '';
        this.sourceGroupId = '';
        this.offset = 0;
        this.hasMore = false;
        this.isOpen = false;
        this.isLoading = false;
    }

    async open(customGroupId, customGroupName = '') {
        this.customGroupId = Number(customGroupId);
        this.customGroupName = customGroupName;
        this.items = [];
        this.selectedIds.clear();
        this.query = '';
        this.sourceGroupId = '';
        this.offset = 0;
        this.hasMore = false;
        this.isOpen = true;
        this.render();
        await this.fetchChannels(true);
    }

    close() {
        this.isOpen = false;
        if (this.container) {
            this.container.innerHTML = '';
            this.container.classList.add('hidden');
        }
    }

    async fetchChannels(reset = false) {
        if (!this.customGroupId || this.isLoading) return;
        this.isLoading = true;
        if (reset) {
            this.offset = 0;
            this.items = [];
        }

        const params = {
            custom_group_id: this.customGroupId,
            q: this.query,
            limit: 50,
            offset: this.offset,
        };
        if (this.sourceGroupId !== '') {
            params.source_group_id = this.sourceGroupId;
        }

        try {
            const res = await this.api.get('search_compatible_channels', params);
            const newItems = Array.isArray(res?.items) ? res.items : [];
            if (reset && Array.isArray(res?.compatible_groups)) {
                this.compatibleGroups = res.compatible_groups;
            }
            this.items = reset ? newItems : [...this.items, ...newItems];
            this.hasMore = Boolean(res?.has_more);
            this.offset = Number(res?.next_offset ?? this.items.length);
        } finally {
            this.isLoading = false;
            this.renderList();
            this.updateAddButton();
        }
    }

    render() {
        if (!this.container) return;
        if (!this.isOpen) {
            this.container.innerHTML = '';
            this.container.classList.add('hidden');
            return;
        }

        this.container.classList.remove('hidden');
        const groupOptions = this.compatibleGroups.map(g =>
            `<option value="${g.id}" ${String(this.sourceGroupId) === String(g.id) ? 'selected' : ''}>${this.escapeHtml(g.name || '')}</option>`
        ).join('');

        this.container.innerHTML = `
            <div class="custom-picker-panel">
                <div class="custom-picker-header">
                    <strong>➕ Kanalen toevoegen aan ${this.escapeHtml(this.customGroupName)}</strong>
                    <button type="button" class="btn btn-sm js-picker-close" style="min-height:44px;">✖️ Sluiten</button>
                </div>
                <div class="toolbar" style="gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                    <input type="search" class="js-picker-search" value="${this.escapeHtml(this.query)}" placeholder="Zoek op kanaalnaam of brongroep..." style="flex:2;min-width:180px;font-size:16px;min-height:44px;">
                    <select class="js-picker-group-filter" style="flex:1;min-width:160px;font-size:16px;min-height:44px;">
                        <option value="">Alle compatibele brongroepen</option>
                        ${groupOptions}
                    </select>
                    <button type="button" class="btn btn-sm btn-primary js-picker-add-selected" disabled style="min-height:44px;">➕ Geselecteerde toevoegen (0)</button>
                </div>
                <div class="custom-picker-list js-picker-list">
                    <div class="muted" style="padding:1rem;text-align:center;">Kanalen laden...</div>
                </div>
                <div class="custom-picker-footer js-picker-footer"></div>
            </div>
        `;

        this.bindShellEvents();
        this.renderList();
        this.updateAddButton();
    }

    renderList() {
        if (!this.container || !this.isOpen) return;
        const groupSelect = this.container.querySelector('.js-picker-group-filter');
        if (groupSelect && groupSelect.options.length <= 1 && this.compatibleGroups.length > 0) {
            const opts = this.compatibleGroups.map(g =>
                `<option value="${g.id}">${this.escapeHtml(g.name || '')}</option>`
            ).join('');
            groupSelect.innerHTML = `<option value="">Alle compatibele brongroepen</option>${opts}`;
            if (this.sourceGroupId) groupSelect.value = this.sourceGroupId;
        }

        const listEl = this.container.querySelector('.js-picker-list');
        const footerEl = this.container.querySelector('.js-picker-footer');
        if (!listEl) return;

        if (this.items.length === 0) {
            listEl.innerHTML = `<div class="muted" style="padding:1rem;text-align:center;">${this.isLoading ? 'Laden...' : 'Geen compatibele kanalen gevonden.'}</div>`;
            if (footerEl) footerEl.innerHTML = '';
            return;
        }

        listEl.innerHTML = this.items.map(ch => {
            const isAdded = Boolean(ch.already_in_custom);
            const isChecked = !isAdded && this.selectedIds.has(Number(ch.id));
            return `
                <label class="custom-picker-row ${isAdded ? 'row-in-custom-group' : ''}" data-cid="${ch.id}">
                    <input type="checkbox" class="js-picker-cb" value="${ch.id}" ${isAdded ? 'disabled' : (isChecked ? 'checked' : '')}>
                    <span class="custom-picker-ch-name">${this.escapeHtml(ch.display_name || ch.tvg_name || '')}</span>
                    <span class="badge badge-group-source">${this.escapeHtml(ch.group_display_name || '')}</span>
                    ${isAdded ? '<span class="badge badge-in-group">✓ Toegevoegd</span>' : ''}
                </label>
            `;
        }).join('');

        listEl.querySelectorAll('.js-picker-cb').forEach(cb => {
            cb.addEventListener('change', () => {
                const id = Number(cb.value);
                if (cb.checked) {
                    this.selectedIds.add(id);
                } else {
                    this.selectedIds.delete(id);
                }
                this.updateAddButton();
            });
        });

        if (footerEl) {
            footerEl.innerHTML = this.hasMore
                ? '<button type="button" class="btn btn-sm js-picker-load-more" style="min-height:44px;width:100%;margin-top:0.5rem;">Meer laden...</button>'
                : '';
            footerEl.querySelector('.js-picker-load-more')?.addEventListener('click', () => this.fetchChannels(false));
        }
    }

    updateAddButton() {
        const btn = this.container?.querySelector('.js-picker-add-selected');
        if (!btn) return;
        const count = this.selectedIds.size;
        btn.textContent = `➕ Geselecteerde toevoegen (${count})`;
        btn.disabled = count === 0;
    }

    bindShellEvents() {
        this.container.querySelector('.js-picker-close')?.addEventListener('click', () => this.close());

        const searchInput = this.container.querySelector('.js-picker-search');
        searchInput?.addEventListener('input', debounce(e => {
            this.query = e.target.value;
            this.fetchChannels(true);
        }, 300));

        this.container.querySelector('.js-picker-group-filter')?.addEventListener('change', e => {
            this.sourceGroupId = e.target.value;
            this.fetchChannels(true);
        });

        this.container.querySelector('.js-picker-add-selected')?.addEventListener('click', async () => {
            const ids = Array.from(this.selectedIds);
            if (ids.length === 0 || !this.customGroupId) return;

            const res = await this.api.post('custom_group_add_channels', {
                custom_group_id: this.customGroupId,
                channel_ids: ids,
            });

            const addedSet = new Set(ids);
            this.items = this.items.map(item =>
                addedSet.has(Number(item.id)) ? { ...item, already_in_custom: true } : item
            );
            this.selectedIds.clear();
            this.renderList();
            this.updateAddButton();
            this.toast?.show(`${res?.added ?? ids.length} kanaal/kanalen toegevoegd`);
            await this.onAdded();
        });
    }
}
