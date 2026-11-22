import { BaseComponent } from '../core/BaseComponent.js';

export class ChannelTable extends BaseComponent {
    render() {
        const { channels = [] } = this.state;
        this.container.innerHTML = channels.map(c => `
            <tr data-cid="${c.id}">
                <td><input type="checkbox" class="ch-select"></td>
                <td><input type="checkbox" class="ch-enable" ${c.is_enabled ? 'checked' : ''}></td>
                <td><input type="number" class="ch-num" value="${c.channel_number ?? ''}" style="width:76px;"></td>
                <td><input type="text" class="ch-name" value="${this.escapeHtml(c.custom_name || c.tvg_name)}"></td>
                <td class="muted">${this.escapeHtml(c.group_name)}</td>
            </tr>
        `).join('');

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
        });
    }

    getSelectedIds() {
        return Array.from(this.container.querySelectorAll('tr'))
            .filter(tr => tr.querySelector('.ch-select')?.checked)
            .map(tr => Number(tr.dataset.cid));
    }
}
