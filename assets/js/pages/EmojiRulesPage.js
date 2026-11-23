import { BaseComponent } from '../core/BaseComponent.js';

const QUICK_EMOJIS = [
    '🎬', '📺', '🏎️', '❤️🔥', '🏷️', '⚽', '🎵', '📰', '👶', '🎭', '🎮', '📚',
    '🇧🇪', '🇳🇱', '🇬🇧', '🇺🇸', '🇩🇪', '🇫🇷', '🇪🇸', '🇮🇹', '🇵🇹', '🇹🇷', '🇸🇦', '🌍',
];

export class EmojiRulesPage extends BaseComponent {
    constructor(selector, props) {
        super(selector, props);
        this.rules = [];
        this.searchQuery = '';
        this.typeFilter = 'all';
        this.editingId = null;
        this.searchTimer = null;
    }

    async load() {
        const [res, catRes] = await Promise.all([
            this.props.api.get('emoji_rules'),
            this.props.api.get('group_category_rules').catch(() => []),
        ]);
        this.rules = Array.isArray(res) ? res : (res?.data || []);
        this.groupCategoryRules = Array.isArray(catRes) ? catRes : (catRes?.data || []);
        this.render();
    }

    render() {
        const editingRule = this.editingId ? this.rules.find(r => Number(r.id) === Number(this.editingId)) : null;
        const filtered = this.getFilteredRules();

        this.container.innerHTML = `
            <div class="card">
                <div class="card-header" style="flex-wrap:wrap;gap:0.75rem;">
                    <div style="display:flex;align-items:center;gap:0.75rem;flex-wrap:wrap;">
                        <button class="btn btn-sm" id="backToChannelsBtn" style="min-height:44px;">← Terug naar Groepen & Kanalen</button>
                        <h2 style="margin:0;">🏷️ Landen & Tags Beheren</h2>
                    </div>
                    <span class="muted">${this.rules.length} regels in database</span>
                </div>

                <form id="emojiRuleForm" class="card" style="background:var(--bg-elevated, rgba(255,255,255,0.03));margin-bottom:1rem;padding:1rem;">
                    <h3 style="margin-top:0;">${editingRule ? 'Regel bewerken' : 'Nieuwe regel toevoegen'}</h3>
                    <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:0.75rem;">
                        <div>
                            <label for="ruleMatchType">Type</label>
                            <select id="ruleMatchType" style="font-size:16px;min-height:44px;width:100%;">
                                <option value="country" ${(!editingRule || editingRule.match_type === 'country') ? 'selected' : ''}>Landcode / Prefix</option>
                                <option value="keyword" ${(editingRule && editingRule.match_type === 'keyword') ? 'selected' : ''}>Categorie-trefwoord</option>
                            </select>
                        </div>
                        <div>
                            <label for="rulePattern">Patroon (landcode of trefwoord)</label>
                            <input type="text" id="rulePattern" value="${editingRule ? this.escapeHtml(editingRule.pattern) : ''}" placeholder="Bijv. BE of netflix" required style="font-size:16px;min-height:44px;width:100%;">
                        </div>
                        <div>
                            <label for="ruleEmoji">Emoji</label>
                            <input type="text" id="ruleEmoji" value="${editingRule ? this.escapeHtml(editingRule.emoji) : ''}" placeholder="Bijv. 🇧🇪 of 🎬" required style="font-size:16px;min-height:44px;width:100%;">
                        </div>
                        <div>
                            <label for="ruleLabel">Omschrijving (optioneel)</label>
                            <input type="text" id="ruleLabel" value="${editingRule ? this.escapeHtml(editingRule.label || '') : ''}" placeholder="Bijv. België of Netflix" style="font-size:16px;min-height:44px;width:100%;">
                        </div>
                    </div>

                    <div style="margin-top:0.75rem;">
                        <small class="muted" style="display:block;margin-bottom:0.35rem;">Snelle emoji-kiezer:</small>
                        <div id="quickEmojiPicker" style="display:flex;flex-wrap:wrap;gap:0.35rem;">
                            ${QUICK_EMOJIS.map(em => `<button type="button" class="btn btn-sm quick-emoji-btn" data-emoji="${em}" style="min-width:44px;min-height:44px;font-size:1.15rem;padding:0.25rem;">${em}</button>`).join('')}
                        </div>
                    </div>

                    <div style="display:flex;gap:0.5rem;margin-top:1rem;flex-wrap:wrap;">
                        <button type="submit" class="btn btn-primary" style="min-height:44px;">${editingRule ? 'Wijzigingen opslaan' : 'Regel toevoegen'}</button>
                        ${editingRule ? '<button type="button" class="btn" id="cancelEditRuleBtn" style="min-height:44px;">Annuleren</button>' : ''}
                    </div>
                </form>

                <div class="toolbar" style="display:flex;gap:0.75rem;flex-wrap:wrap;margin-bottom:1rem;">
                    <input type="search" id="emojiRuleSearchInput" value="${this.escapeHtml(this.searchQuery)}" placeholder="Zoek op patroon, emoji of omschrijving..." style="flex:2;min-width:220px;font-size:16px;min-height:44px;">
                    <select id="emojiRuleTypeFilter" style="flex:1;min-width:160px;font-size:16px;min-height:44px;">
                        <option value="all" ${this.typeFilter === 'all' ? 'selected' : ''}>Alle types (${this.rules.length})</option>
                        <option value="country" ${this.typeFilter === 'country' ? 'selected' : ''}>Landcodes (${this.rules.filter(r => r.match_type === 'country').length})</option>
                        <option value="keyword" ${this.typeFilter === 'keyword' ? 'selected' : ''}>Categorie-trefwoorden (${this.rules.filter(r => r.match_type === 'keyword').length})</option>
                    </select>
                </div>

                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                            <tr>
                                <th>Type</th>
                                <th>Patroon</th>
                                <th>Emoji</th>
                                <th>Omschrijving</th>
                                <th>Actief</th>
                                <th>Acties</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${filtered.length === 0 ? '<tr><td colspan="6" class="muted">Geen regels gevonden.</td></tr>' : filtered.map(r => `
                                <tr>
                                    <td><span class="badge">${r.match_type === 'country' ? 'Landcode' : 'Categorie'}</span></td>
                                    <td><code>${this.escapeHtml(r.pattern)}</code></td>
                                    <td style="font-size:1.25rem;">${this.escapeHtml(r.emoji)}</td>
                                    <td>${this.escapeHtml(r.label || '—')}</td>
                                    <td>
                                        <input type="checkbox" class="toggle-rule-active" data-id="${r.id}" ${Number(r.is_active) === 1 ? 'checked' : ''} style="width:22px;height:22px;cursor:pointer;">
                                    </td>
                                    <td>
                                        <div style="display:flex;gap:0.35rem;">
                                            <button type="button" class="btn btn-sm edit-rule-btn" data-id="${r.id}" style="min-width:44px;min-height:44px;" title="Bewerken">✏️</button>
                                            <button type="button" class="btn btn-sm btn-danger delete-rule-btn" data-id="${r.id}" style="min-width:44px;min-height:44px;" title="Verwijderen">🗑️</button>
                                        </div>
                                    </td>
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="card" style="margin-top:1rem;">
                <div class="card-header">
                    <h2>📂 Groep-categorie Regels</h2>
                    <span class="muted">${(this.groupCategoryRules || []).length} eigen regels</span>
                </div>
                <form id="groupCategoryRuleForm" style="display:flex;gap:0.5rem;flex-wrap:wrap;align-items:center;margin-bottom:0.75rem;">
                    <input type="text" id="groupCatPatternInput" placeholder="Trefwoord/tag in groepsnaam (bv. VIAP, JEUGD)..." required style="flex:1;min-width:180px;font-size:16px;min-height:44px;">
                    <select id="groupCatSelectInput" style="font-size:16px;min-height:44px;">
                        <option value="Live TV">Live TV</option>
                        <option value="Sport">Sport</option>
                        <option value="Animatie">Animatie</option>
                        <option value="TV Series">TV Series</option>
                        <option value="Film">Film</option>
                        <option value="Muziek">Muziek</option>
                        <option value="Adult">Adult</option>
                    </select>
                    <button type="submit" class="btn btn-primary" style="min-height:44px;">+ Regel toevoegen</button>
                </form>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Trefwoord / Tag</th><th>Categorie</th><th style="width:90px;">Actie</th></tr></thead>
                        <tbody>
                            ${(this.groupCategoryRules || []).length === 0
                                ? `<tr><td colspan="3" class="muted" style="text-align:center;">Nog geen eigen groeps-categorie regels.</td></tr>`
                                : (this.groupCategoryRules || []).map(r => `
                                    <tr>
                                        <td><code>${this.escapeHtml(r.pattern)}</code></td>
                                        <td><span class="cat-badge">${this.escapeHtml(r.category)}</span></td>
                                        <td><button type="button" class="btn btn-sm delete-group-cat-rule-btn" data-id="${r.id}" style="min-height:40px;">🗑️</button></td>
                                    </tr>
                                `).join('')}
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    getFilteredRules() {
        const q = this.searchQuery.trim().toLowerCase();
        return this.rules.filter(r => {
            if (this.typeFilter !== 'all' && r.match_type !== this.typeFilter) {
                return false;
            }
            if (!q) return true;
            return (
                String(r.pattern || '').toLowerCase().includes(q) ||
                String(r.emoji || '').toLowerCase().includes(q) ||
                String(r.label || '').toLowerCase().includes(q)
            );
        });
    }

    bindEvents() {
        this.container.querySelector('#backToChannelsBtn')?.addEventListener('click', () => {
            this.props.switchTab('channels');
        });

        this.container.querySelectorAll('.quick-emoji-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const input = this.container.querySelector('#ruleEmoji');
                if (input) {
                    input.value = btn.dataset.emoji || '';
                    input.focus();
                }
            });
        });

        this.container.querySelector('#cancelEditRuleBtn')?.addEventListener('click', () => {
            this.editingId = null;
            this.render();
        });

        this.container.querySelector('#emojiRuleForm')?.addEventListener('submit', async e => {
            e.preventDefault();
            const payload = {
                id: this.editingId,
                match_type: this.container.querySelector('#ruleMatchType')?.value || 'country',
                pattern: this.container.querySelector('#rulePattern')?.value.trim() || '',
                emoji: this.container.querySelector('#ruleEmoji')?.value.trim() || '',
                label: this.container.querySelector('#ruleLabel')?.value.trim() || '',
                is_active: true,
            };

            await this.props.api.post('save_emoji_rule', payload);
            this.props.toast.show(this.editingId ? 'Regel bijgewerkt.' : 'Regel toegevoegd.');
            this.editingId = null;
            await this.load();
        });

        this.container.querySelector('#emojiRuleSearchInput')?.addEventListener('input', e => {
            const val = e.target.value;
            clearTimeout(this.searchTimer);
            this.searchTimer = setTimeout(() => {
                this.searchQuery = val;
                this.render();
                const input = this.container.querySelector('#emojiRuleSearchInput');
                if (input) {
                    input.focus();
                    input.setSelectionRange(val.length, val.length);
                }
            }, 300);
        });

        this.container.querySelector('#emojiRuleTypeFilter')?.addEventListener('change', e => {
            this.typeFilter = e.target.value;
            this.render();
        });

        this.container.querySelectorAll('.toggle-rule-active').forEach(cb => {
            cb.addEventListener('change', async e => {
                const id = Number(e.target.dataset.id);
                const isActive = Boolean(e.target.checked);
                await this.props.api.post('toggle_emoji_rule', { id, is_active: isActive });
                const rule = this.rules.find(r => Number(r.id) === id);
                if (rule) rule.is_active = isActive ? 1 : 0;
                this.props.toast.show('Status bijgewerkt.');
            });
        });

        this.container.querySelectorAll('.edit-rule-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.editingId = Number(btn.dataset.id);
                this.render();
                this.container.scrollIntoView({ behavior: 'smooth', block: 'start' });
            });
        });

        this.container.querySelectorAll('.delete-rule-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id);
                await this.props.api.post('delete_emoji_rule', { id });
                this.props.toast.show('Regel verwijderd.');
                if (this.editingId === id) {
                    this.editingId = null;
                }
                await this.load();
            });
        });

        document.getElementById('groupCategoryRuleForm')?.addEventListener('submit', async e => {
            e.preventDefault();
            const pattern = document.getElementById('groupCatPatternInput')?.value.trim() || '';
            const category = document.getElementById('groupCatSelectInput')?.value || 'Live TV';
            if (!pattern) return;
            await this.props.api.post('save_group_category_rule', { pattern, category });
            this.props.toast.show('Groep-categorie regel opgeslagen en toegepast.');
            await this.load();
        });

        this.container.querySelectorAll('.delete-group-cat-rule-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                const id = Number(btn.dataset.id);
                await this.props.api.post('delete_group_category_rule', { id });
                this.props.toast.show('Groep-categorie regel verwijderd.');
                await this.load();
            });
        });
    }
}
