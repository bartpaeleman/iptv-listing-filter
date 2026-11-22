import { BaseComponent } from '../core/BaseComponent.js';

export class EpgMapper extends BaseComponent {
    render() {
        const { channels = [] } = this.state;
        this.container.innerHTML = channels.map(c => {
            const badge = c.epg_channel_id
                ? (c.epg_match_type === 'manual' ? '<span class="badge badge-success">Gekoppeld</span>' : '<span class="badge badge-warning">Auto-matched</span>')
                : '<span class="badge badge-muted">Geen EPG</span>';
            return `
                <tr>
                    <td>${this.escapeHtml(c.custom_name || c.tvg_name)}</td>
                    <td>${badge}</td>
                    <td>${this.escapeHtml(c.epg_display_name || c.epg_channel_id || '-')}</td>
                    <td><button class="btn btn-sm btn-secondary pick-epg-btn" data-id="${c.id}" data-name="${this.escapeHtml(c.custom_name || c.tvg_name)}">Kies EPG</button></td>
                </tr>
            `;
        }).join('');

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('.pick-epg-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                this.props.onPick?.(Number(btn.dataset.id), btn.dataset.name);
            });
        });
    }
}
