import { BaseComponent } from '../core/BaseComponent.js';
import { debounce } from '../core/Debounce.js';

export class SearchFilterBar extends BaseComponent {
    render() {
        const placeholder = this.props.placeholder || 'Zoeken...';
        this.container.innerHTML = `<input type="search" class="search-input" placeholder="${this.escapeHtml(placeholder)}">`;
    }

    bindEvents() {
        const input = this.container.querySelector('input');
        input?.addEventListener('input', debounce(e => {
            if (this.props.onSearch) this.props.onSearch(e.target.value);
        }, this.props.delay || 300));
    }
}
