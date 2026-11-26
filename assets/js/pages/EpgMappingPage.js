import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';
import { EpgMapper } from '../components/EpgMapper.js';

export class EpgMappingPage extends BaseComponent {
    constructor(container, props) {
        super(container, props);
        this.mapper = null;
        this.currentPage = 1;
        this.pageSize = 10;
        this.totalItems = 0;
        this.groupsLoaded = false;
        this.sortBy = 'name_asc';
    }

    async load() {
        if (!this.container.dataset.mounted) {
            this.renderShell();
            this.container.dataset.mounted = '1';
        }
        if (!this.groupsLoaded) {
            await this.loadActiveGroups();
        }
        const search = document.getElementById('epgChannelSearch')?.value || '';
        const epg_status = document.getElementById('epgStatusFilter')?.value || '';
        const group_id = document.getElementById('epgGroupFilter')?.value || '';
        const offset = (this.currentPage - 1) * this.pageSize;

        if (!group_id) {
            this.totalItems = 0;
            this.mapper.setState({ channels: [] });
            const tbody = document.getElementById('epgMapperRoot');
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="4" class="muted" style="text-align:center;padding:1.5rem;">Kies hierboven een groep om kanalen te laden.</td></tr>';
            }
            this.updateSortHeaders();
            this.renderPagination();
            return;
        }

        const params = {
            active_only: 1,
            live_only: 1,
            search,
            epg_status,
            sort_by: this.sortBy,
            limit: this.pageSize,
            offset,
            group_id,
        };

        const data = await this.props.api.get('channels', params);
        this.totalItems = Number(data.total || 0);
        const safeItems = (data.items || []).filter(
            ch => Number(ch.is_enabled ?? 1) === 1 && (!ch.group_category || ch.group_category === 'Live TV')
        );
        this.mapper.setState({ channels: safeItems });
        this.enhanceCloseMatchRows(safeItems);
        this.updateSortHeaders();
        this.renderPagination();
    }

    enhanceCloseMatchRows(items) {
        const tbody = document.getElementById('epgMapperRoot');
        if (!tbody) return;
        const rows = tbody.querySelectorAll('tr');
        items.forEach((ch, idx) => {
            if (ch.epg_match_type !== 'close_match' || !ch.epg_channel_id) return;
            const tr = rows[idx];
            if (!tr) return;
            tr.classList.add('row-epg-close-match');
            const statusTd = tr.children[1];
            if (statusTd) {
                statusTd.innerHTML = '<span class="badge badge-warning">⚠️ Close match — controleer</span>';
            }
            const actionTd = tr.children[3];
            if (actionTd) {
                const confirmBtn = document.createElement('button');
                confirmBtn.type = 'button';
                confirmBtn.className = 'btn btn-sm btn-primary btn-confirm-epg';
                confirmBtn.textContent = '✓ Bevestig';
                confirmBtn.style.marginLeft = '0.35rem';
                confirmBtn.addEventListener('click', async () => {
                    await this.props.api.post('map_epg', {
                        channel_id: ch.id,
                        epg_channel_id: ch.epg_channel_id,
                        match_type: 'manual',
                    });
                    this.props.toast.show('Close match bevestigd!');
                    this.load();
                });
                actionTd.appendChild(confirmBtn);
            }
        });
    }

    async loadActiveGroups() {
        const sel = document.getElementById('epgGroupFilter');
        if (!sel) return;
        const groups = await this.props.api.get('groups', { scope: 'active', live_only: 1 });
        const rawList = Array.isArray(groups) ? groups : (groups?.items || []);
        const list = rawList.filter(
            g => Number(g.is_enabled ?? 1) === 1 && Number(g.show_in_epg ?? 1) === 1 && g.category !== 'Film' && g.category !== 'TV Series'
        );
        const cur = sel.value;
        sel.innerHTML = `<option value="">-- Kies een groep om kanalen te laden --</option>` + list.map(g => {
            const label = this.escapeHtml(g.display_name || g.custom_name || g.original_name || '');
            return `<option value="${g.id}">${label}</option>`;
        }).join('');
        sel.value = list.some(g => String(g.id) === String(cur)) ? cur : '';
        this.groupsLoaded = true;
    }

    renderPagination() {
        const wrap = document.getElementById('epgPagination');
        if (!wrap) return;
        const totalPages = Math.max(1, Math.ceil(this.totalItems / this.pageSize));
        if (this.currentPage > totalPages) {
            this.currentPage = totalPages;
        }
        wrap.innerHTML = `
            <button type="button" class="btn btn-sm" id="epgPrevPageBtn" ${this.currentPage <= 1 ? 'disabled' : ''} style="min-height:44px;padding:0 1rem;">← Vorige</button>
            <span class="muted" style="font-size:0.9rem;">Pagina <strong>${this.currentPage}</strong> van <strong>${totalPages}</strong> (${this.totalItems} kanalen)</span>
            <button type="button" class="btn btn-sm" id="epgNextPageBtn" ${this.currentPage >= totalPages ? 'disabled' : ''} style="min-height:44px;padding:0 1rem;">Volgende →</button>
        `;
        document.getElementById('epgPrevPageBtn')?.addEventListener('click', () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.load();
            }
        });
        document.getElementById('epgNextPageBtn')?.addEventListener('click', () => {
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.load();
            }
        });
    }

    updateSortHeaders() {
        this.container.querySelectorAll('.epg-sort-th').forEach(th => {
            const key = th.dataset.sort;
            const icon = th.querySelector('.sort-chevron');
            if (!icon) return;
            if (this.sortBy === `${key}_asc`) {
                icon.textContent = '▲';
                th.style.color = 'var(--accent, #38bdf8)';
            } else if (this.sortBy === `${key}_desc`) {
                icon.textContent = '▼';
                th.style.color = 'var(--accent, #38bdf8)';
            } else {
                icon.textContent = '⇅';
                th.style.color = '';
            }
        });
    }

    renderShell() {
        this.container.innerHTML = `
            <style>
                .epg-mapping-table th, .epg-mapping-table td { padding: 0.4rem 0.65rem; }
                .epg-sort-th { cursor: pointer; user-select: none; white-space: nowrap; }
                .epg-sort-th:hover { opacity: 0.85; }
                .epg-sort-th .sort-chevron { margin-left: 0.3rem; font-size: 0.75rem; opacity: 0.85; }
                .epg-automatch-status { display: none; margin: 0.75rem 0; padding: 0.75rem 1rem; border-radius: 8px; background: rgba(56, 189, 248, 0.08); border: 1px solid rgba(56, 189, 248, 0.25); }
                .epg-automatch-status.is-active { display: block; }
                .epg-automatch-row { display: flex; align-items: center; justify-content: space-between; gap: 0.75rem; margin-bottom: 0.5rem; font-size: 0.92rem; }
                .epg-automatch-label { display: flex; align-items: center; gap: 0.5rem; font-weight: 500; }
                .epg-spinner { width: 16px; height: 16px; border: 2px solid rgba(56, 189, 248, 0.25); border-top-color: var(--accent, #38bdf8); border-radius: 50%; animation: epgSpin 0.8s linear infinite; flex-shrink: 0; }
                @keyframes epgSpin { to { transform: rotate(360deg); } }
                .epg-progress-track { width: 100%; height: 8px; background: rgba(255, 255, 255, 0.08); border-radius: 999px; overflow: hidden; }
                .epg-progress-fill { height: 100%; width: 0%; background: var(--accent, #38bdf8); border-radius: 999px; transition: width 0.2s ease; }
            </style>
            <div class="card">
                <div class="card-header">
                    <h2>EPG Zender Koppeling</h2>
                    <button class="btn btn-primary" id="autoMatchEpgBtn" style="min-height:44px;">✨ Auto-koppel op naam</button>
                </div>
                <div class="toolbar" style="display:flex;gap:0.5rem;flex-wrap:nowrap;align-items:center;overflow-x:auto;">
                    <input type="search" id="epgChannelSearch" placeholder="Zoek actief kanaal..." style="font-size:16px;min-height:44px;flex:1;min-width:140px;">
                    <select id="epgGroupFilter" style="font-size:16px;min-height:44px;width:auto;max-width:260px;flex-shrink:0;">
                        <option value="">-- Kies een groep om kanalen te laden --</option>
                    </select>
                    <select id="epgStatusFilter" style="font-size:16px;min-height:44px;width:auto;flex-shrink:0;">
                        <option value="">Alle statussen</option>
                        <option value="unmapped">Geen EPG</option>
                        <option value="mapped">Gekoppeld</option>
                    </select>
                </div>
                <div id="epgAutoMatchStatus" class="epg-automatch-status" role="status" aria-live="polite">
                    <div class="epg-automatch-row">
                        <span class="epg-automatch-label">
                            <span class="epg-spinner" aria-hidden="true"></span>
                            <span id="epgAutoMatchText">Bezig met auto-koppelen van kanalen...</span>
                        </span>
                        <span id="epgAutoMatchCounter" class="muted">0%</span>
                    </div>
                    <div class="epg-progress-track">
                        <div id="epgAutoMatchProgress" class="epg-progress-fill"></div>
                    </div>
                </div>
                <div class="table-wrap">
                    <table class="data-table epg-mapping-table">
                        <thead>
                            <tr>
                                <th class="epg-sort-th" data-sort="name">Kanaal<span class="sort-chevron">▲</span></th>
                                <th class="epg-sort-th" data-sort="epg_status">Status<span class="sort-chevron">⇅</span></th>
                                <th class="epg-sort-th" data-sort="epg_name">Gekoppelde EPG Zender<span class="sort-chevron">⇅</span></th>
                                <th>Actie</th>
                            </tr>
                        </thead>
                        <tbody id="epgMapperRoot"></tbody>
                    </table>
                </div>
                <div id="epgPagination" style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem;padding-top:0.75rem;flex-wrap:wrap;"></div>
            </div>
        `;

        this.mapper = new EpgMapper('#epgMapperRoot', {
            onPick: (channelId, channelName) => this.openPicker(channelId, channelName)
        });

        this.container.querySelectorAll('.epg-sort-th').forEach(th => {
            th.addEventListener('click', () => {
                const key = th.dataset.sort;
                this.sortBy = this.sortBy === `${key}_asc` ? `${key}_desc` : `${key}_asc`;
                this.currentPage = 1;
                this.load();
            });
        });

        document.getElementById('epgChannelSearch')?.addEventListener('input', debounce(() => {
            this.currentPage = 1;
            this.load();
        }, 300));
        document.getElementById('epgGroupFilter')?.addEventListener('change', () => {
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('epgStatusFilter')?.addEventListener('change', () => {
            this.currentPage = 1;
            this.load();
        });
        document.getElementById('autoMatchEpgBtn')?.addEventListener('click', () => {
            this.runAutoMatch();
        });
    }

    async runAutoMatch() {
        const btn = document.getElementById('autoMatchEpgBtn');
        const statusBox = document.getElementById('epgAutoMatchStatus');
        const statusText = document.getElementById('epgAutoMatchText');
        const counterEl = document.getElementById('epgAutoMatchCounter');
        const progressEl = document.getElementById('epgAutoMatchProgress');
        if (!btn || btn.disabled) return;

        const rawGroupId = document.getElementById('epgGroupFilter')?.value || '';
        const groupId = rawGroupId !== '' ? Number(rawGroupId) : 0;
        const rawStatus = document.getElementById('epgStatusFilter')?.value || '';
        const epgStatus = rawStatus === 'mapped' ? 'coupled' : (rawStatus === 'unmapped' ? 'uncoupled' : 'all');
        const search = document.getElementById('epgChannelSearch')?.value || '';

        const updateProgress = (processed, total, text) => {
            const pct = total > 0 ? Math.min(100, Math.round((processed / total) * 100)) : 0;
            if (progressEl) progressEl.style.width = `${pct}%`;
            if (counterEl) counterEl.textContent = total > 0 ? `${processed} / ${total} (${pct}%)` : `${pct}%`;
            if (statusText && text) statusText.textContent = text;
        };

        btn.disabled = true;
        if (statusBox) statusBox.classList.add('is-active');
        updateProgress(0, 0, 'Kanalen ophalen binnen actieve filter...');

        try {
            const channelIds = [];
            let offset = 0;
            const pageLimit = 200;
            let total = 0;

            do {
                const params = {
                    active_only: 1,
                    live_only: 1,
                    search,
                    epg_status: rawStatus,
                    limit: pageLimit,
                    offset,
                };
                if (groupId > 0) params.group_id = groupId;

                const page = await this.props.api.get('channels', params);
                total = Number(page.total || 0);
                const items = Array.isArray(page.items) ? page.items : [];
                for (const ch of items) {
                    if (ch.id) channelIds.push(Number(ch.id));
                }
                offset += items.length;
                if (items.length < pageLimit) break;
            } while (offset < total);

            if (channelIds.length === 0) {
                updateProgress(1, 1, 'Geen kanalen gevonden binnen de actieve filter.');
                this.props.toast.show('Geen kanalen om te koppelen binnen de actieve filter.');
                return;
            }

            const batchSize = 50;
            let processed = 0;
            let totalMatched = 0;
            updateProgress(0, channelIds.length, 'Bezig met auto-koppelen van kanalen...');

            for (let i = 0; i < channelIds.length; i += batchSize) {
                const batch = channelIds.slice(i, i + batchSize);
                const res = await this.props.api.post('auto_match_epg', {
                    group_id: groupId,
                    epg_status: epgStatus,
                    active_only: 1,
                    live_only: 1,
                    channel_ids: batch,
                });
                totalMatched += Number(res.matched || 0);
                processed += batch.length;
                updateProgress(processed, channelIds.length, 'Bezig met auto-koppelen van kanalen...');
            }

            updateProgress(channelIds.length, channelIds.length, `Voltooid: ${totalMatched} kanalen gekoppeld.`);
            this.props.toast.show(`${totalMatched} kanalen automatisch gekoppeld!`);
            await this.load();
        } catch (err) {
            this.props.toast.show(err?.message || 'Fout bij auto-koppelen van kanalen.');
        } finally {
            btn.disabled = false;
            setTimeout(() => {
                if (statusBox) statusBox.classList.remove('is-active');
                if (progressEl) progressEl.style.width = '0%';
                if (counterEl) counterEl.textContent = '0%';
                if (statusText) statusText.textContent = 'Bezig met auto-koppelen van kanalen...';
            }, 800);
        }
    }

    async openPicker(channelId, channelName) {
        this.props.modal.open(
            `Kies EPG Zender voor ${channelName}`,
            `<input type="search" id="epgModalSearch" placeholder="Typ zendernaam (bv. NPO 1, VRT 1)...">
             <div id="epgModalResults" class="epg-results"></div>`
        );

        const search = async q => {
            const items = await this.props.api.get('epg_search', { q });
            const box = document.getElementById('epgModalResults');
            if (!box) return;
            box.innerHTML = `<div class="epg-option" data-epgid=""><em>Geen EPG (ontkoppelen)</em></div>` + items.map(i => `
                <div class="epg-option" data-epgid="${this.escapeHtml(i.epg_id)}">
                    ${i.icon_url ? `<img src="${this.escapeHtml(i.icon_url)}" alt="">` : ''}
                    <div>
                        <div><strong>${this.escapeHtml(i.display_name)}</strong></div>
                        <div class="muted" style="font-size:0.8rem;">${this.escapeHtml(i.epg_id)}</div>
                    </div>
                </div>
            `).join('');

            box.querySelectorAll('.epg-option').forEach(opt => {
                opt.addEventListener('click', async () => {
                    await this.props.api.post('update_channel', { id: channelId, epg_channel_id: opt.dataset.epgid });
                    this.props.modal.close();
                    this.props.toast.show('EPG koppeling opgeslagen');
                    this.load();
                });
            });
        };

        document.getElementById('epgModalSearch')?.addEventListener('input', debounce(e => search(e.target.value), 250));
        await search('');
    }
}
