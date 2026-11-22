import { BaseComponent } from '../core/BaseComponent.js';

export class ModalDialog extends BaseComponent {
    open(title, bodyHtml) {
        this.container.innerHTML = `
            <div class="modal">
                <div class="modal-content">
                    <div class="card-header">
                        <h3>${this.escapeHtml(title)}</h3>
                        <button class="btn btn-ghost" data-close-modal>✕</button>
                    </div>
                    <div class="modal-body">${bodyHtml}</div>
                </div>
            </div>
        `;
        this.container.querySelector('[data-close-modal]')?.addEventListener('click', () => this.close());
    }

    close() {
        this.container.innerHTML = '';
    }
}
