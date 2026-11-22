import { BaseComponent } from '../core/BaseComponent.js';

export class GroupList extends BaseComponent {
    render() {
        const { groups = [], selectedGroupId = null, filter = '' } = this.state;
        const q = filter.toLowerCase();
        const filtered = groups.filter(g => (g.custom_name || g.original_name).toLowerCase().includes(q));

        this.container.innerHTML = filtered.map(g => {
            const rawName = g.custom_name || g.original_name;
            const display = g.display_name || rawName;
            const tooltip = g.original_name || rawName;
            return `
            <div class="group-item ${selectedGroupId === g.id ? 'active' : ''}" data-gid="${g.id}" data-custom="${this.escapeHtml(g.custom_name || '')}" data-orig="${this.escapeHtml(g.original_name || '')}">
                <input type="checkbox" class="group-toggle" ${g.is_enabled ? 'checked' : ''} title="Groep in-/uitschakelen">
                <span class="group-item-name" title="${this.escapeHtml(tooltip)}">${this.escapeHtml(display)}</span>
                <button type="button" class="btn btn-sm group-edit-btn" title="Groepsnaam aanpassen (leeg = reset naar origineel: ${this.escapeHtml(tooltip)})" style="padding:2px 6px;min-height:28px;min-width:28px;font-size:0.75rem;flex-shrink:0;">✏️</button>
                <span class="muted" style="font-size:0.8rem;">${g.active_channel_count}/${g.channel_count}</span>
            </div>
        `;
        }).join('');

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('.group-item').forEach(el => {
            el.addEventListener('click', ev => {
                if (ev.target.classList.contains('group-toggle') || ev.target.classList.contains('group-edit-btn') || ev.target.classList.contains('group-edit-input')) return;
                const id = Number(el.dataset.gid);
                const name = el.querySelector('.group-item-name')?.textContent || '';
                if (this.props.onSelect) this.props.onSelect(id, name);
            });
            el.querySelector('.group-toggle')?.addEventListener('change', ev => {
                const id = Number(el.dataset.gid);
                if (this.props.onToggle) this.props.onToggle(id, ev.target.checked);
            });
            el.querySelector('.group-edit-btn')?.addEventListener('click', ev => {
                ev.stopPropagation();
                const id = Number(el.dataset.gid);
                const nameSpan = el.querySelector('.group-item-name');
                if (!nameSpan || el.querySelector('.group-edit-input')) return;

                const currentVal = el.dataset.custom || el.dataset.orig || '';
                const input = document.createElement('input');
                input.type = 'text';
                input.className = 'group-edit-input';
                input.value = currentVal;
                input.placeholder = el.dataset.orig || 'Originele naam...';
                input.style.cssText = 'flex:1;min-width:80px;font-size:16px;padding:2px 6px;';

                nameSpan.replaceWith(input);
                input.focus();
                input.select();

                let committed = false;
                const commit = () => {
                    if (committed) return;
                    committed = true;
                    const newVal = input.value.trim();
                    if (this.props.onRename) {
                        this.props.onRename(id, newVal);
                    }
                };
                input.addEventListener('keydown', ke => {
                    if (ke.key === 'Enter') {
                        ke.preventDefault();
                        commit();
                    } else if (ke.key === 'Escape') {
                        committed = true;
                        this.render();
                    }
                });
                input.addEventListener('blur', commit);
            });
        });
    }
}
