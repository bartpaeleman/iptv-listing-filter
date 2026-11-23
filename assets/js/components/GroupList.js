import { BaseComponent } from '../core/BaseComponent.js';

export class GroupList extends BaseComponent {
    render() {
        const { groups = [], selectedGroupId = null, filter = '' } = this.state;
        const q = filter.toLowerCase();
        const filtered = groups.filter(g =>
            (g.original_name || g.name || '').toLowerCase().includes(q) ||
            (g.custom_name || '').toLowerCase().includes(q) ||
            (g.display_name || '').toLowerCase().includes(q)
        );

        this.container.innerHTML = filtered.map(g => {
            const rawName = g.custom_name || g.original_name;
            const display = g.display_name || rawName;
            const tooltip = g.original_name || rawName;
            const isCustom = Number(g.is_custom || 0) === 1;
            let diffBadge = '';
            if (isCustom && g.sync_diff_json) {
                try {
                    const diff = typeof g.sync_diff_json === 'string' ? JSON.parse(g.sync_diff_json) : g.sync_diff_json;
                    const addCount = Array.isArray(diff?.added) ? diff.added.length : 0;
                    const remCount = Array.isArray(diff?.removed) ? diff.removed.length : 0;
                    if (addCount > 0 || remCount > 0) {
                        diffBadge = `<span class="badge badge-warn" title="Bron-groep gewijzigd na sync">⚠️ +${addCount} / -${remCount}</span>`;
                    }
                } catch {}
            }
            const customBadge = isCustom ? '<span class="badge badge-custom">Custom</span>' : '';
            return `
            <div class="group-item ${Number(selectedGroupId) === Number(g.id) ? 'active is-selected' : ''}" data-gid="${g.id}">
                <input type="checkbox" class="group-toggle" ${g.is_enabled ? 'checked' : ''} title="Groep in-/uitschakelen">
                <span class="group-item-name" title="${this.escapeHtml(tooltip)}">${this.escapeHtml(display)}</span>
                ${customBadge}${diffBadge}
                <span class="muted" style="font-size:0.8rem;">${g.active_channel_count}/${g.channel_count}</span>
            </div>
        `;
        }).join('');

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
