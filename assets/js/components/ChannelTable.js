import { BaseComponent } from '../core/BaseComponent.js';

export class ChannelTable extends BaseComponent {
    render() {
        const { channels = [], isAllChannelsView = false } = this.state;
        this.container.innerHTML = channels.map(c => {
            const rawName = c.custom_name || c.tvg_name;
            const cleanName = c.clean_name || rawName;
            const emojiPrefix = c.emoji_prefix || '';
            const hasCustomName = Boolean(c.custom_name && String(c.custom_name).trim() !== '');
            const isEnabled = Boolean(Number(c.is_enabled));

            return `
            <tr data-cid="${c.id}">
                ${isAllChannelsView ? '' : '<td><input type="checkbox" class="ch-select" title="Selecteer kanaal voor bulkactie"></td>'}
                <td>
                    <label class="toggle-switch" title="Kanaal opnemen in actieve M3U-playlist">
                        <input type="checkbox" class="ch-enable" ${isEnabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                    </label>
                </td>
                <td>${isEnabled
                    ? `<input type="number" class="ch-num" value="${c.channel_number ?? ''}" style="width:76px;font-size:16px;min-height:44px;">`
                    : `<input type="text" class="ch-num" value="-" disabled style="width:76px;font-size:16px;min-height:44px;text-align:center;opacity:0.5;" title="Kanaal is uitgeschakeld">`
                }</td>
                <td>
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        ${emojiPrefix ? `<span class="ch-emoji-prefix" title="${this.escapeHtml(c.tvg_name || '')}">${this.escapeHtml(emojiPrefix)}</span>` : ''}
                        <input type="text" class="ch-name" value="${this.escapeHtml(cleanName)}" title="Origineel: ${this.escapeHtml(c.tvg_name || '')}">
                        ${emojiPrefix ? `<button type="button" class="btn btn-sm ch-adopt-flag" data-emoji="${this.escapeHtml(emojiPrefix)}" title="Naam incl. landvlag opslaan als custom naam" style="min-height:44px;min-width:44px;flex-shrink:0;">❐</button>` : ''}
                        ${hasCustomName ? `<button type="button" class="btn btn-sm ch-reset-name" title="Originele naam herstellen (${this.escapeHtml(c.tvg_name || '')})" style="min-height:44px;min-width:44px;flex-shrink:0;">↩️</button>` : ''}
                    </div>
                </td>
            </tr>
        `;
        }).join('');

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('tr').forEach(tr => {
            const id = Number(tr.dataset.cid);
            tr.querySelector('.ch-enable')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { is_enabled: ev.target.checked ? 1 : 0 });
            });
            tr.querySelector('.ch-num')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { channel_number: ev.target.value });
            });
            tr.querySelector('.ch-name')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { custom_name: ev.target.value });
            });
            tr.querySelector('.ch-adopt-flag')?.addEventListener('click', () => {
                const nameInput = tr.querySelector('.ch-name');
                const emojiSpan = tr.querySelector('.ch-emoji-prefix');
                const adoptBtn = tr.querySelector('.ch-adopt-flag');
                const emoji = adoptBtn?.dataset.emoji || '';
                const cleanName = (nameInput?.value || '').trim();
                const adoptedName = `${emoji} ${cleanName}`.trim();
                if (nameInput) nameInput.value = adoptedName;
                if (emojiSpan) emojiSpan.style.display = 'none';
                this.props.onUpdate?.(id, { custom_name: adoptedName });
            });
            tr.querySelector('.ch-reset-name')?.addEventListener('click', () => {
                this.props.onUpdate?.(id, { custom_name: '' });
            });
        });
    }
}
