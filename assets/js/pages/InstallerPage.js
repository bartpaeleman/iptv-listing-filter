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

        const existingToggle = document.getElementById('useExistingUserToggle');
        const adminBlock = document.getElementById('adminProvisionBlock');
        existingToggle?.addEventListener('change', () => {
            adminBlock?.classList.toggle('hidden', existingToggle.checked);
        });

        document.getElementById('regenAppPassBtn')?.addEventListener('click', () => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%^&*-_';
            const arr = new Uint32Array(24);
            crypto.getRandomValues(arr);
            const pass = Array.from(arr, n => chars[n % chars.length]).join('');
            const input = document.getElementById('appDbPassInput');
            if (input) input.value = pass;
        });

        document.getElementById('copyAppPassBtn')?.addEventListener('click', ev => {
            const input = document.getElementById('appDbPassInput');
            if (!input) return;
            ClipboardHelper.copyText(input.value, ev.currentTarget);
        });

        const modeSelect = document.getElementById('sourceModeSelect');
        const xtreamBlock = document.getElementById('xtreamFieldsBlock');
        const customBlock = document.getElementById('customUrlFieldsBlock');
        modeSelect?.addEventListener('change', () => {
            const isCustom = modeSelect.value === 'custom_urls';
            xtreamBlock?.classList.toggle('hidden', isCustom);
            customBlock?.classList.toggle('hidden', !isCustom);
        });

        const m3uInput = document.getElementById('customM3uInput');
        const detectBanner = document.getElementById('xtreamAutoDetectBanner');
        let detectedXmltv = '';
        m3uInput?.addEventListener('input', () => {
            try {
                const u = new URL(m3uInput.value.trim());
                const user = u.searchParams.get('username');
                const pass = u.searchParams.get('password');
                if (user && pass) {
                    detectedXmltv = `${u.origin}/xmltv.php?username=${encodeURIComponent(user)}&password=${encodeURIComponent(pass)}`;
                    detectBanner?.classList.remove('hidden');
                    return;
                }
            } catch { /* ignore invalid URL */ }
            detectBanner?.classList.add('hidden');
        });

        document.getElementById('applyDetectedXmltvBtn')?.addEventListener('click', () => {
            const epgInput = document.getElementById('customEpgInput');
            if (epgInput && detectedXmltv) {
                epgInput.value = detectedXmltv;
                detectBanner?.classList.add('hidden');
            }
        });
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
