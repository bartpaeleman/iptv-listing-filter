import { BaseComponent } from '../core/BaseComponent.js';
import { ClipboardHelper } from '../core/ClipboardHelper.js';

export class InstallerPage extends BaseComponent {
    mount() {
        document.addEventListener('click', ev => {
            const btn = ev.target.closest('[data-copy-text]');
            if (!btn) return;
            const codeEl = btn.closest('.remediation-cmd-wrap')?.querySelector('code') || null;
            ClipboardHelper.copyText(btn.dataset.copyText || '', btn, codeEl);
        });

        const recheckBtn = document.getElementById('recheckPrereqsBtn');
        recheckBtn?.addEventListener('click', () => this.recheck(recheckBtn));
    }

    async recheck(btn) {
        const origText = btn.textContent;
        btn.disabled = true;
        btn.textContent = '⏳ Controleren...';

        try {
            const res = await fetch('index.php?route=install&step=recheck');
            const json = await res.json();
            if (!json.ok) throw new Error(json.error || 'Recheck mislukt');

            const { checks, all_passed } = json.data;
            const list = document.getElementById('prereqList');
            if (list) {
                list.innerHTML = checks.map(c => `
                    <li class="check-item ${c.passed ? 'ok' : 'fail'}" data-check-id="${this.escapeHtml(c.id)}">
                        <div class="check-row">
                            <span class="check-icon">${c.passed ? '✓' : '✗'}</span>
                            <span class="check-label">${this.escapeHtml(c.label)}</span>
                            <span class="check-val muted">${this.escapeHtml(c.current_value)}</span>
                        </div>
                        ${!c.passed ? `
                            <div class="remediation-box" role="alert">
                                <div class="remediation-title">${this.escapeHtml(c.fix_title)}</div>
                                <div class="remediation-cmd-wrap">
                                    <pre><code>${this.escapeHtml(c.fix_command)}</code></pre>
                                    <button type="button" class="btn btn-sm copy-fix-btn" data-copy-text="${this.escapeHtml(c.fix_command)}">Kopieer</button>
                                </div>
                            </div>
                        ` : ''}
                    </li>
                `).join('');
            }

            document.getElementById('nextStepBtn')?.classList.toggle('hidden', !all_passed);
            document.getElementById('prereqErrorHint')?.classList.toggle('hidden', all_passed);
        } finally {
            btn.disabled = false;
            btn.textContent = origText;
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new InstallerPage(document.body).mount();
});
