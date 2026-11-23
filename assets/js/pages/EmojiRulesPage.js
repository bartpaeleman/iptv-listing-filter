import { BaseComponent } from '../core/BaseComponent.js';

const GROUP_CATEGORIES = ['Live TV', 'Sport', 'Animatie', 'TV Series', 'Film', 'Muziek', 'Adult'];
const EMOJI_PAGE_SIZE = 8;
const CAT_PAGE_SIZE = 10;

export class EmojiRulesPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.emojiFilter = { search: '', type: 'all', sortDir: 'asc', page: 1 };
        this.catFilter = { search: '', category: 'all', sortBy: 'pattern', sortDir: 'asc', page: 1 };
        this.editingEmojiId = null;
        this.editingEmojiData = null;
    }

    async load() {
        const [emojiRes, catRes] = await Promise.all([
            this.props.api.get('emoji_rules'),
            this.props.api.get('group_category_rules'),
        ]);
        this.setState({
            emojiRules: emojiRes.items || emojiRes.rules || (Array.isArray(emojiRes) ? emojiRes : []),
            catRules: catRes.items || catRes.rules || (Array.isArray(catRes) ? catRes : []),
        });
    }

    getRuleType(r) {
        return String(r.match_type || r.rule_type || r.type || '');
    }

    getFilteredEmojiRules() {
        const all = this.state.emojiRules || [];
        const q = this.emojiFilter.search.trim().toLowerCase();
        const filtered = all.filter(r => {
            const rType = this.getRuleType(r);
            if (this.emojiFilter.type !== 'all' && rType !== this.emojiFilter.type) return false;
            if (q) {
                const hay = `${r.pattern || ''} ${r.label || ''} ${r.emoji || ''} ${rType}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });

        const dir = this.emojiFilter.sortDir === 'desc' ? -1 : 1;
        filtered.sort((a, b) => String(a.pattern || '').localeCompare(String(b.pattern || '')) * dir);
        return filtered;
    }

    getFilteredCatRules() {
        const all = this.state.catRules || [];
        const q = this.catFilter.search.trim().toLowerCase();
        const filtered = all.filter(r => {
            if (this.catFilter.category !== 'all' && String(r.category || '') !== this.catFilter.category) return false;
            if (q && !String(r.pattern || '').toLowerCase().includes(q)) return false;
            return true;
        });

        const dir = this.catFilter.sortDir === 'desc' ? -1 : 1;
        const key = this.catFilter.sortBy;
        filtered.sort((a, b) => {
            const va = String(key === 'status' ? (a.is_enabled ?? 1) : (a[key] || '')).toLowerCase();
            const vb = String(key === 'status' ? (b.is_enabled ?? 1) : (b[key] || '')).toLowerCase();
            return va.localeCompare(vb) * dir;
        });
        return filtered;
    }

    sortChevron(currentKey, currentDir, colKey) {
        if (currentKey !== colKey) return ' ↕';
        return currentDir === 'asc' ? ' ▲' : ' ▼';
    }

    render() {
        if (!this.state.emojiRules) {
            this.container.innerHTML = '<div class="card">Laden...</div>';
            return;
        }

        const emojiAll = this.state.emojiRules || [];
        const uniqueTypes = Array.from(new Set(emojiAll.map(r => this.getRuleType(r)).filter(Boolean)))
            .sort((a, b) => a.localeCompare(b));

        const emojiFiltered = this.getFilteredEmojiRules();
        const emojiTotalPages = Math.max(1, Math.ceil(emojiFiltered.length / EMOJI_PAGE_SIZE));
        this.emojiFilter.page = Math.min(this.emojiFilter.page, emojiTotalPages);
        const emojiStart = (this.emojiFilter.page - 1) * EMOJI_PAGE_SIZE;
        const emojiPageRows = emojiFiltered.slice(emojiStart, emojiStart + EMOJI_PAGE_SIZE);

        const catAll = this.state.catRules || [];
        const catFiltered = this.getFilteredCatRules();
        const catTotalPages = Math.max(1, Math.ceil(catFiltered.length / CAT_PAGE_SIZE));
        this.catFilter.page = Math.min(this.catFilter.page, catTotalPages);
        const catStart = (this.catFilter.page - 1) * CAT_PAGE_SIZE;
        const catPageRows = catFiltered.slice(catStart, catStart + CAT_PAGE_SIZE);

        const editData = this.editingEmojiData || {};
        const editType = this.getRuleType(editData) || 'country';

        this.container.innerHTML = `
            <details class="collapsible-card compact-form-card" id="newEmojiRuleAccordion" ${this.editingEmojiId ? 'open' : ''}>
                <summary class="collapsible-card__header">
                    <span>➕ ${this.editingEmojiId ? 'Regel bewerken' : 'Nieuwe regel toevoegen'}</span>
                    <span class="collapsible-card__chevron">▼</span>
                </summary>
                <div class="collapsible-card__body">
                    <form id="emojiRuleForm" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                        <select id="erType" style="min-height:44px;font-size:16px;">
                            <option value="country" ${editType === 'country' ? 'selected' : ''}>Landcode</option>
                            <option value="keyword" ${editType === 'keyword' ? 'selected' : ''}>Keyword</option>
                        </select>
                        <input type="text" id="erPattern" value="${this.escapeHtml(editData.pattern || '')}" placeholder="Patroon (bv. BE of SPORT)" required style="min-height:44px;font-size:16px;flex:1 1 150px;">
                        <input type="text" id="erEmoji" value="${this.escapeHtml(editData.emoji || '')}" placeholder="Emoji (bv. 🇧🇪)" required style="min-height:44px;font-size:16px;width:110px;">
                        <input type="text" id="erLabel" value="${this.escapeHtml(editData.label || '')}" placeholder="Omschrijving (optioneel)" style="min-height:44px;font-size:16px;flex:1 1 150px;">
                        <button type="submit" class="btn btn-primary" style="min-height:44px;">${this.editingEmojiId ? 'Bijwerken' : 'Opslaan'}</button>
                        ${this.editingEmojiId ? `<button type="button" class="btn" id="erCancelEditBtn" style="min-height:44px;">Annuleren</button>` : ''}
                    </form>
                </div>
            </details>

            <div class="card" style="margin-bottom:1rem;">
                <div class="card-header" style="margin-bottom:0.75rem;">
                    <h2>🏷️ Land- &amp; Keyword-Emoji Regels <span class="badge">${emojiAll.length}</span></h2>
                    <input type="search" id="erSearchInput" value="${this.escapeHtml(this.emojiFilter.search)}" placeholder="Zoek patroon, omschrijving of emoji..." style="min-height:44px;font-size:16px;max-width:300px;width:100%;">
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>
                                    <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                                        <span>Type</span>
                                        <select id="emojiTypeHeaderFilter" style="min-height:36px;font-size:14px;padding:0.15rem 0.35rem;">
                                            <option value="all" ${this.emojiFilter.type === 'all' ? 'selected' : ''}>Alle</option>
                                            ${uniqueTypes.map(t => `<option value="${this.escapeHtml(t)}" ${this.emojiFilter.type === t ? 'selected' : ''}>${this.escapeHtml(t)}</option>`).join('')}
                                        </select>
                                    </div>
                                </th>
                                <th class="sortable-th" id="erSortPatternTh">Patroon ${this.emojiFilter.sortDir === 'asc' ? '▲' : '▼'}</th>
                                <th>Emoji</th>
                                <th>Omschrijving</th>
                                <th>Actief</th>
                                <th>Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${emojiPageRows.length > 0 ? emojiPageRows.map(r => `
                                <tr>
                                    <td>${this.escapeHtml(this.getRuleType(r))}</td>
                                    <td><code>${this.escapeHtml(r.pattern || '')}</code></td>
                                    <td><span class="emoji-pill">${this.escapeHtml(r.emoji || '')}</span></td>
                                    <td>${this.escapeHtml(r.label || '')}</td>
                                    <td>
                                        <button type="button" class="btn btn-sm er-toggle-btn" data-id="${r.id}" data-enabled="${Number(r.is_enabled ?? 1) === 1 ? 0 : 1}" style="min-height:44px;">
                                            ${Number(r.is_enabled ?? 1) === 1 ? '✅ Actief' : '⏸️ Inactief'}
                                        </button>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        <button type="button" class="btn btn-sm er-edit-btn" data-id="${r.id}" style="min-height:44px;">✏️</button>
                                        <button type="button" class="btn btn-sm btn-danger er-delete-btn" data-id="${r.id}" style="min-height:44px;">🗑️</button>
                                    </td>
                                </tr>
                            `).join('') : '<tr><td colspan="6" class="muted">Geen regels gevonden.</td></tr>'}
                        </tbody>
                    </table>
                </div>

                <div class="table-pagination-bar">
                    <button type="button" class="btn btn-sm" id="erPrevPage" ${this.emojiFilter.page <= 1 ? 'disabled' : ''}>← Vorige</button>
                    <span class="muted">Pagina ${this.emojiFilter.page} van ${emojiTotalPages} (${emojiFiltered.length} regels)</span>
                    <button type="button" class="btn btn-sm" id="erNextPage" ${this.emojiFilter.page >= emojiTotalPages ? 'disabled' : ''}>Volgende →</button>
                </div>
            </div>

            <details class="collapsible-card" id="catRulesAccordion">
                <summary class="collapsible-card__header">
                    <span>📂 Groep-categorie Regels <span class="badge">${catAll.length}</span></span>
                    <span class="collapsible-card__chevron">▼</span>
                </summary>
                <div class="collapsible-card__body">
                    <form id="catRuleForm" style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;margin-bottom:0.75rem;">
                        <input type="text" id="crPattern" placeholder="Patroon / trefwoord (bv. SPORT)" required style="min-height:44px;font-size:16px;flex:1 1 180px;">
                        <select id="crCategory" style="min-height:44px;font-size:16px;">
                            ${GROUP_CATEGORIES.map(c => `<option value="${this.escapeHtml(c)}">${this.escapeHtml(c)}</option>`).join('')}
                        </select>
                        <button type="submit" class="btn btn-primary" style="min-height:44px;">Opslaan</button>
                    </form>

                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.75rem;">
                        <input type="search" id="crSearchInput" value="${this.escapeHtml(this.catFilter.search)}" placeholder="Zoek patroon..." style="min-height:44px;font-size:16px;flex:1 1 200px;">
                        <select id="crCatFilter" style="min-height:44px;font-size:16px;">
                            <option value="all" ${this.catFilter.category === 'all' ? 'selected' : ''}>Alle categorieën</option>
                            ${GROUP_CATEGORIES.map(c => `<option value="${this.escapeHtml(c)}" ${this.catFilter.category === c ? 'selected' : ''}>${this.escapeHtml(c)}</option>`).join('')}
                        </select>
                    </div>

                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <th class="sortable-th" data-cr-sort="pattern">Patroon${this.sortChevron(this.catFilter.sortBy, this.catFilter.sortDir, 'pattern')}</th>
                                    <th class="sortable-th" data-cr-sort="category">Categorie${this.sortChevron(this.catFilter.sortBy, this.catFilter.sortDir, 'category')}</th>
                                    <th class="sortable-th" data-cr-sort="status">Status${this.sortChevron(this.catFilter.sortBy, this.catFilter.sortDir, 'status')}</th>
                                    <th>Acties</th>
                                </tr>
                            </thead>
                            <tbody>
                                ${catPageRows.length > 0 ? catPageRows.map(r => `
                                    <tr>
                                        <td><code>${this.escapeHtml(r.pattern || '')}</code></td>
                                        <td><span class="category-badge">${this.escapeHtml(r.category || '')}</span></td>
                                        <td>${Number(r.is_enabled ?? 1) === 1 ? '✅ Actief' : '⏸️ Inactief'}</td>
                                        <td style="white-space:nowrap;">
                                            <button type="button" class="btn btn-sm btn-danger cr-delete-btn" data-id="${r.id}" style="min-height:44px;">🗑️</button>
                                        </td>
                                    </tr>
                                `).join('') : '<tr><td colspan="4" class="muted">Geen categorie-regels gevonden.</td></tr>'}
                            </tbody>
                        </table>
                    </div>

                    <div class="table-pagination-bar">
                        <button type="button" class="btn btn-sm" id="crPrevPage" ${this.catFilter.page <= 1 ? 'disabled' : ''}>← Vorige</button>
                        <span class="muted">Pagina ${this.catFilter.page} van ${catTotalPages} (${catFiltered.length} regels)</span>
                        <button type="button" class="btn btn-sm" id="crNextPage" ${this.catFilter.page >= catTotalPages ? 'disabled' : ''}>Volgende →</button>
                    </div>
                </div>
            </details>
        `;

        this.bindEvents();
    }

    bindEvents() {
        document.getElementById('emojiRuleForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_emoji_rule', {
                id: this.editingEmojiId || 0,
                rule_type: document.getElementById('erType')?.value || 'country',
                pattern: document.getElementById('erPattern')?.value || '',
                emoji: document.getElementById('erEmoji')?.value || '',
                label: document.getElementById('erLabel')?.value || '',
            });
            this.editingEmojiId = null;
            this.editingEmojiData = null;
            this.props.toast.show('Emoji-regel opgeslagen!');
            await this.load();
        });

        document.getElementById('erCancelEditBtn')?.addEventListener('click', () => {
            this.editingEmojiId = null;
            this.editingEmojiData = null;
            this.render();
        });

        this.container.querySelectorAll('.er-edit-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const id = Number(btn.dataset.id);
                const rule = (this.state.emojiRules || []).find(r => Number(r.id) === id);
                if (!rule) return;
                this.editingEmojiId = id;
                this.editingEmojiData = rule;
                this.render();
            });
        });

        this.container.querySelectorAll('.er-toggle-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('toggle_emoji_rule', {
                    id: Number(btn.dataset.id),
                    is_enabled: Number(btn.dataset.enabled),
                });
                this.props.toast.show('Status bijgewerkt');
                await this.load();
            });
        });

        this.container.querySelectorAll('.er-delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('delete_emoji_rule', { id: Number(btn.dataset.id) });
                this.props.toast.show('Regel verwijderd');
                await this.load();
            });
        });

        document.getElementById('erSearchInput')?.addEventListener('input', e => {
            this.emojiFilter.search = e.target.value;
            this.emojiFilter.page = 1;
            this.render();
        });

        const typeSelect = document.getElementById('emojiTypeHeaderFilter');
        typeSelect?.addEventListener('click', e => e.stopPropagation());
        typeSelect?.addEventListener('change', e => {
            this.emojiFilter.type = e.target.value;
            this.emojiFilter.page = 1;
            this.render();
        });

        document.getElementById('erSortPatternTh')?.addEventListener('click', () => {
            this.emojiFilter.sortDir = this.emojiFilter.sortDir === 'asc' ? 'desc' : 'asc';
            this.render();
        });

        document.getElementById('erPrevPage')?.addEventListener('click', () => {
            if (this.emojiFilter.page > 1) { this.emojiFilter.page--; this.render(); }
        });
        document.getElementById('erNextPage')?.addEventListener('click', () => {
            this.emojiFilter.page++;
            this.render();
        });

        document.getElementById('catRuleForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_group_category_rule', {
                pattern: document.getElementById('crPattern')?.value || '',
                category: document.getElementById('crCategory')?.value || 'Live TV',
            });
            this.props.toast.show('Categorie-regel opgeslagen!');
            await this.load();
        });

        this.container.querySelectorAll('.cr-delete-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('delete_group_category_rule', { id: Number(btn.dataset.id) });
                this.props.toast.show('Categorie-regel verwijderd');
                await this.load();
            });
        });

        document.getElementById('crSearchInput')?.addEventListener('input', e => {
            this.catFilter.search = e.target.value;
            this.catFilter.page = 1;
            this.rerenderPreservingFocus('crSearchInput', e.target.selectionStart, e.target.selectionEnd);
        });
        document.getElementById('crCatFilter')?.addEventListener('change', e => {
            this.catFilter.category = e.target.value;
            this.catFilter.page = 1;
            this.render();
        });
        this.container.querySelectorAll('[data-cr-sort]').forEach(th => {
            th.addEventListener('click', () => {
                const col = th.dataset.crSort;
                if (this.catFilter.sortBy === col) {
                    this.catFilter.sortDir = this.catFilter.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    this.catFilter.sortBy = col;
                    this.catFilter.sortDir = 'asc';
                }
                this.render();
            });
        });
        document.getElementById('crPrevPage')?.addEventListener('click', () => {
            if (this.catFilter.page > 1) { this.catFilter.page--; this.render(); }
        });
        document.getElementById('crNextPage')?.addEventListener('click', () => {
            this.catFilter.page++;
            this.render();
        });
    }

    rerenderPreservingFocus(inputId, selStart, selEnd) {
        this.render();
        const el = document.getElementById(inputId);
        if (el) {
            el.focus();
            if (typeof selStart === 'number' && typeof selEnd === 'number') {
                el.setSelectionRange(selStart, selEnd);
            }
        }
    }
}
