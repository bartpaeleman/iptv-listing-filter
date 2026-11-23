import { BaseComponent } from '../core/BaseComponent.js';

export class GroupChannelToolsBar extends BaseComponent {
    render() {
        const groupId = this.props.groupId ?? null;
        if (!groupId) {
            this.container.innerHTML = '';
            return;
        }

        const qualities = ['SD', 'HD', 'FHD', 'UHD', '4K', 'HEVC', 'RAW'];

        this.container.innerHTML = `
            <div class="card" style="padding:0.75rem 1rem;margin-bottom:0.75rem;display:flex;flex-wrap:wrap;gap:0.75rem;align-items:center;">
                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;">
                    <span style="font-weight:600;font-size:0.9rem;">Kwaliteit:</span>
                    <select id="gctQualitySelect" style="min-height:44px;font-size:16px;padding:0.35rem 0.5rem;">
                        ${qualities.map(q => `<option value="${q}">${q}</option>`).join('')}
                    </select>
                    <button type="button" class="btn btn-sm" id="gctQualityOnBtn" style="min-height:44px;">Aan</button>
                    <button type="button" class="btn btn-sm" id="gctQualityOffBtn" style="min-height:44px;">Uit</button>
                </div>

                <div style="display:flex;align-items:center;">
                    <button type="button" class="btn btn-sm" id="gctDisableCommentsBtn" style="min-height:44px;">🚫 Commentaar uit (###)</button>
                </div>

                <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap;flex:1 1 320px;">
                    <span style="font-weight:600;font-size:0.9rem;">Zoek &amp; Vervang:</span>
                    <input type="text" id="gctSearchInput" placeholder="Zoek tekst (bijv. XXX )" style="min-height:44px;font-size:16px;flex:1 1 140px;">
                    <input type="text" id="gctReplaceInput" placeholder="Vervang (leeg = wissen)" style="min-height:44px;font-size:16px;flex:1 1 140px;">
                    <label class="checkbox-row" style="min-height:44px;cursor:pointer;white-space:nowrap;">
                        <input type="checkbox" id="gctCaseSensitive">
                        <span style="font-size:0.85rem;">Hoofdlettergevoelig</span>
                    </label>
                    <button type="button" class="btn btn-primary btn-sm" id="gctReplaceBtn" style="min-height:44px;">Toepassen</button>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        const runTool = async (payload) => {
            try {
                const res = await this.props.api.post('group_channel_tools', {
                    group_id: this.props.groupId,
                    ...payload,
                });
                this.props.toast.show(`${res.affected ?? 0} kanaal/kanalen bijgewerkt.`);
                if (typeof this.props.onUpdated === 'function') {
                    this.props.onUpdated(res);
                }
            } catch (err) {
                this.props.toast.show(err?.message || 'Actie mislukt.');
            }
        };

        document.getElementById('gctQualityOnBtn')?.addEventListener('click', () => {
            const quality = document.getElementById('gctQualitySelect')?.value || 'HD';
            runTool({ mode: 'quality', quality, enabled: 1 });
        });

        document.getElementById('gctQualityOffBtn')?.addEventListener('click', () => {
            const quality = document.getElementById('gctQualitySelect')?.value || 'HD';
            runTool({ mode: 'quality', quality, enabled: 0 });
        });

        document.getElementById('gctDisableCommentsBtn')?.addEventListener('click', () => {
            runTool({ mode: 'disable_comments' });
        });

        document.getElementById('gctReplaceBtn')?.addEventListener('click', () => {
            const search = document.getElementById('gctSearchInput')?.value || '';
            const replace = document.getElementById('gctReplaceInput')?.value || '';
            const caseSensitive = document.getElementById('gctCaseSensitive')?.checked ? 1 : 0;
            if (!search) {
                this.props.toast.show('Vul een zoektekst in.');
                return;
            }
            runTool({ mode: 'search_replace', search, replace, case_sensitive: caseSensitive });
        });
    }
}
