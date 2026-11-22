import { BaseComponent } from '../core/BaseComponent.js';

export class ChannelTable extends BaseComponent {
    render() {
        const { channels = [] } = this.state;
        this.container.innerHTML = channels.map(c => {
            const rawName = c.custom_name || c.tvg_name;
            const grpDisplay = c.group_display_name || c.group_name;

            return `
            <tr data-cid="${c.id}">
                <td><input type="checkbox" class="ch-select" title="Selecteer kanaal voor bulkactie"></td>
                <td>
                    <label class="toggle-switch" title="Kanaal opnemen in actieve M3U-playlist">
                        <input type="checkbox" class="ch-enable" ${c.is_enabled ? 'checked' : ''}>
                        <span class="toggle-slider"></span>
                    </label>
                </td>
                <td><input type="number" class="ch-num" value="${c.channel_number ?? ''}" style="width:76px;"></td>
                <td>
                    <div style="display:flex;align-items:center;gap:0.4rem;">
                        <input type="text" class="ch-name" value="${this.escapeHtml(c.display_name || rawName)}">
                    </div>
                </td>
                <td class="muted" title="${this.escapeHtml(c.group_name)}">${this.escapeHtml(grpDisplay)}</td>
            </tr>
        `;
        }).join('');

        this.bindEvents();
        this.props.onSelectionChange?.(0);
    }

    bindEvents() {
        this.container.querySelectorAll('tr').forEach(tr => {
            const id = Number(tr.dataset.cid);
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
        });
    }

    getSelectedIds() {
        return Array.from(this.container.querySelectorAll('tr'))
            .filter(tr => tr.querySelector('.ch-select')?.checked)
            .map(tr => Number(tr.dataset.cid));
    }
}
