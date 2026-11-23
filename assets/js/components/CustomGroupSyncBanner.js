import { BaseComponent } from '../core/BaseComponent.js';

export class CustomGroupSyncBanner extends BaseComponent {
    constructor(el, { onResolve } = {}) {
        super(el);
        this.onResolve = onResolve || (() => {});
        this.diff = null;
        this.customGroupId = null;
    }

    setData(customGroupId, syncDiffJson) {
        this.customGroupId = customGroupId;
        if (!syncDiffJson) {
            this.diff = null;
            this.render();
            return;
        }
        try {
            const parsed = typeof syncDiffJson === 'string' ? JSON.parse(syncDiffJson) : syncDiffJson;
            const added = Array.isArray(parsed?.added) ? parsed.added : [];
            const removed = Array.isArray(parsed?.removed) ? parsed.removed : [];
            this.diff = (added.length > 0 || removed.length > 0) ? { added, removed } : null;
        } catch {
            this.diff = null;
        }
        this.render();
    }

    render() {
        if (!this.el) return;
        if (!this.diff) {
            this.el.innerHTML = '';
            this.el.classList.add('hidden');
            return;
        }

        this.el.classList.remove('hidden');
        const { added, removed } = this.diff;

        const addedRows = added.map((ch) => `
            <div class="sync-diff-item">
                <span class="sync-diff-name">+ ${this.escapeHtml(ch.name || '')}</span>
                <div class="sync-diff-actions">
                    <button type="button" class="btn btn-sm btn-primary js-sync-add" data-id="${ch.id}">Voeg toe</button>
                    <button type="button" class="btn btn-sm btn-secondary js-sync-ignore" data-id="${ch.id}">Negeer</button>
                </div>
            </div>
        `).join('');

        const removedRows = removed.map((ch) => `
            <div class="sync-diff-item">
                <span class="sync-diff-name">- ${this.escapeHtml(ch.name || '')}</span>
                <div class="sync-diff-actions">
                    <button type="button" class="btn btn-sm btn-danger js-sync-remove" data-id="${ch.id}">Verwijder verdwenen</button>
                </div>
            </div>
        `).join('');

        this.el.innerHTML = `
            <div class="custom-sync-banner">
                <div class="custom-sync-banner-header">
                    <strong>⚠️ Bron-groep gewijzigd na sync (${added.length} nieuw, ${removed.length} verdwenen)</strong>
                    <div class="custom-sync-bulk-actions">
                        ${added.length > 0 ? '<button type="button" class="btn btn-sm btn-primary js-sync-add-all">Alles toevoegen</button>' : ''}
                        ${removed.length > 0 ? '<button type="button" class="btn btn-sm btn-danger js-sync-remove-all">Verwijder alle verdwenen</button>' : ''}
                    </div>
                </div>
                <div class="custom-sync-banner-list">
                    ${addedRows}
                    ${removedRows}
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        this.el.querySelectorAll('.js-sync-add').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = Number(btn.dataset.id);
                this.onResolve({ addIds: [id], ignoreAddIds: [], removeIds: [] });
            });
        });

        this.el.querySelectorAll('.js-sync-ignore').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = Number(btn.dataset.id);
                this.onResolve({ addIds: [], ignoreAddIds: [id], removeIds: [] });
            });
        });

        this.el.querySelectorAll('.js-sync-remove').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = Number(btn.dataset.id);
                this.onResolve({ addIds: [], ignoreAddIds: [], removeIds: [id] });
            });
        });

        const addAllBtn = this.el.querySelector('.js-sync-add-all');
        if (addAllBtn && this.diff) {
            addAllBtn.addEventListener('click', () => {
                const addIds = this.diff.added.map((c) => Number(c.id));
                this.onResolve({ addIds, ignoreAddIds: [], removeIds: [] });
            });
        }

        const removeAllBtn = this.el.querySelector('.js-sync-remove-all');
        if (removeAllBtn && this.diff) {
            removeAllBtn.addEventListener('click', () => {
                const removeIds = this.diff.removed.map((c) => Number(c.id));
                this.onResolve({ addIds: [], ignoreAddIds: [], removeIds });
            });
        }
    }
}
