import { BaseComponent } from '../core/BaseComponent.js';

export class GroupList extends BaseComponent {
    render() {
        const { groups = [], selectedGroupId = null, filter = '' } = this.state;
        const q = filter.toLowerCase();
        const filtered = groups.filter(g => (g.custom_name || g.original_name).toLowerCase().includes(q));

        this.container.innerHTML = filtered.map(g => `
            <div class="group-item ${selectedGroupId === g.id ? 'active' : ''}" data-gid="${g.id}">
                <input type="checkbox" class="group-toggle" ${g.is_enabled ? 'checked' : ''}>
                <span class="group-item-name" title="${this.escapeHtml(g.original_name)}">${this.escapeHtml(g.custom_name || g.original_name)}</span>
                <span class="muted" style="font-size:0.8rem;">${g.active_channel_count}/${g.channel_count}</span>
            </div>
        `).join('');

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('.group-item').forEach(el => {
            el.addEventListener('click', ev => {
                if (ev.target.classList.contains('group-toggle')) return;
                const id = Number(el.dataset.gid);
                const name = el.querySelector('.group-item-name')?.textContent || '';
                if (this.props.onSelect) this.props.onSelect(id, name);
            });
            el.querySelector('.group-toggle')?.addEventListener('change', ev => {
                const id = Number(el.dataset.gid);
                if (this.props.onToggle) this.props.onToggle(id, ev.target.checked);
            });
        });
    }
}
