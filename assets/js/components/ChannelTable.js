import { BaseComponent } from '../core/BaseComponent.js';

export class ChannelTable extends BaseComponent {
    render() {
        const { channels = [] } = this.state;
        this.container.innerHTML = channels.map(c => this.renderRow(c)).join('');
        this.bindEvents(this.container);
    }

    appendItems(newItems = []) {
        if (!Array.isArray(newItems) || newItems.length === 0) return;
        const current = Array.isArray(this.state.channels) ? this.state.channels : [];
        this.state.channels = current.concat(newItems);
        const temp = document.createElement('tbody');
        temp.innerHTML = newItems.map(c => this.renderRow(c)).join('');
        const fragment = document.createDocumentFragment();
        while (temp.firstElementChild) {
            const tr = temp.firstElementChild;
            this.bindRowEvents(tr);
            fragment.appendChild(tr);
        }
        this.container.appendChild(fragment);
    }

    renderRow(c) {
        const { isAllChannelsView = false, isCustomGroup = false, targetGroupId = null, targetMemberIds = [], targetGroupName = '' } = this.state;
        const memberSet = new Set((Array.isArray(targetMemberIds) ? targetMemberIds : []).map(Number));
        const tvgTrimmed = (c.tvg_name || '').trim();
        if (/^#{3,}/.test(tvgTrimmed)) {
            const label = tvgTrimmed.replace(/^#+\s*/, '').replace(/\s*#+$/, '') || tvgTrimmed;
            const colSpan = isAllChannelsView ? 3 : 4;
            return `
            <tr class="channel-group-header-row">
                <td colspan="${colSpan}"><span class="channel-group-header-label">${this.escapeHtml(label)}</span></td>
            </tr>`;
        }

        const rawName = c.custom_name || c.tvg_name;
        const cleanName = c.clean_name || rawName;
        const originalCleanName = c.original_clean_name || c.tvg_name || '';
        const emojiPrefix = c.emoji_prefix || '';
        const hasCustomName = Boolean(c.custom_name && String(c.custom_name).trim() !== '');
        const showOriginalRef = hasCustomName && originalCleanName.trim() !== '' && String(cleanName).trim() !== originalCleanName.trim();
        const isEnabled = Boolean(Number(c.is_enabled));
        const isMember = !isAllChannelsView && memberSet.has(Number(c.id));

        let customActionBtn = '';
        if (isCustomGroup && !isAllChannelsView) {
            customActionBtn = `<button type="button" class="btn-icon-remove ch-remove-from-custom" title="Verwijderen uit custom groep">−</button>`;
        } else if (!isCustomGroup && !isAllChannelsView && targetGroupId) {
            customActionBtn = isMember
                ? `<span class="badge badge-in-group" title="Reeds in ${this.escapeHtml(targetGroupName)}">✓</span><button type="button" class="btn-icon-remove ch-toggle-target-custom" data-in-group="1" title="Verderen uit ${this.escapeHtml(targetGroupName)}">−</button>`
                : `<button type="button" class="btn-icon-add ch-toggle-target-custom" data-in-group="0" title="Toevoegen aan ${this.escapeHtml(targetGroupName)}">+</button>`;
        }

        return `
        <tr data-cid="${c.id}" class="${isMember ? 'row-in-custom-group' : ''}">
            ${isAllChannelsView ? '' : `<td><input type="checkbox" class="ch-select" ${isMember ? 'disabled title="Reeds toegevoegd aan deze groep"' : 'title="Selecteer kanaal voor bulkactie"'}></td>`}
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
            <td style="min-width:0;">
                <div style="display:flex;align-items:center;gap:0.4rem;min-width:0;">
                    ${emojiPrefix ? `<span class="ch-emoji-prefix" title="${this.escapeHtml(c.tvg_name || '')}">${this.escapeHtml(emojiPrefix)}</span>` : ''}
                    <input type="text" class="ch-name" value="${this.escapeHtml(cleanName)}" title="Origineel: ${this.escapeHtml(c.tvg_name || '')}" style="min-width:0;">
                    <button type="button" class="btn btn-sm ch-use-original ${!hasCustomName ? 'btn-primary' : ''}" title="Originele naam gebruiken" style="min-height:44px;min-width:44px;flex-shrink:0;">↩️</button>
                    <button type="button" class="btn btn-sm ch-use-custom ${hasCustomName ? 'btn-primary' : ''}" data-emoji="${this.escapeHtml(emojiPrefix)}" title="Custom naam incl. vlag/symbool gebruiken" style="min-height:44px;min-width:44px;flex-shrink:0;">${this.escapeHtml(emojiPrefix || '✏️')}</button>
                    ${customActionBtn}
                </div>
                ${showOriginalRef ? `<div class="ch-original-ref" title="${this.escapeHtml(originalCleanName)}">Origineel: ${this.escapeHtml(originalCleanName)}</div>` : ''}
            </td>
        </tr>
    `;
    }

    getSelectedIds() {
        return [...this.container.querySelectorAll('tr[data-cid]')]
            .filter(tr => tr.querySelector('.ch-select')?.checked)
            .map(tr => Number(tr.dataset.cid));
    }

    bindEvents(root = this.container) {
        root.querySelectorAll('tr').forEach(tr => this.bindRowEvents(tr));
    }

    bindRowEvents(tr) {
            const id = Number(tr.dataset.cid);
            if (!id) return;
            tr.querySelector('.ch-select')?.addEventListener('change', ev => {
                tr.classList.toggle('row-selected', ev.target.checked);
                this.props.onSelectionChange?.(this.getSelectedIds().length);
            });
            tr.querySelector('.ch-enable')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { is_enabled: ev.target.checked ? 1 : 0 });
            });
            tr.querySelector('.ch-num')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { channel_number: ev.target.value });
            });
            tr.querySelector('.ch-name')?.addEventListener('change', ev => {
                this.props.onUpdate?.(id, { custom_name: ev.target.value });
            });
            tr.querySelector('.ch-use-original')?.addEventListener('click', () => {
                this.props.onUpdate?.(id, { custom_name: '' });
            });
            tr.querySelector('.ch-use-custom')?.addEventListener('click', () => {
                const nameInput = tr.querySelector('.ch-name');
                const customBtn = tr.querySelector('.ch-use-custom');
                const emojiPrefix = customBtn?.dataset.emoji || '';
                const val = (nameInput?.value || '').trim();
                const finalName = (emojiPrefix && !val.startsWith(emojiPrefix))
                    ? `${emojiPrefix} ${val}`.trim()
                    : val;
                this.props.onUpdate?.(id, { custom_name: finalName });
            });
            tr.querySelector('.ch-remove-from-custom')?.addEventListener('click', () => {
                if (this.props.onRemoveFromCustomGroup) this.props.onRemoveFromCustomGroup(id);
            });
            tr.querySelector('.ch-toggle-target-custom')?.addEventListener('click', ev => {
                const inGroup = ev.currentTarget.dataset.inGroup === '1';
                if (this.props.onToggleTargetCustomGroup) this.props.onToggleTargetCustomGroup(id, inGroup);
            });
    }
}
