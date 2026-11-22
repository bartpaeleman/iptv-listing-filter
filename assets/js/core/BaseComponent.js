export class BaseComponent {
    constructor(container, props = {}) {
        this.container = typeof container === 'string'
            ? document.querySelector(container)
            : container;
        this.props = props;
        this.state = {};
    }

    setState(partialState) {
        this.state = { ...this.state, ...partialState };
        this.render();
    }

    mount() {
        this.render();
        this.bindEvents();
    }

    render() {}

    bindEvents() {}

    escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }
}
