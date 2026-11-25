import { ApiClient } from './core/ApiClient.js';
import { ToastNotification } from './components/ToastNotification.js';
import { ModalDialog } from './components/ModalDialog.js';
import { DashboardPage } from './pages/DashboardPage.js';
import { ChannelsPage } from './pages/ChannelsPage.js';
import { EpgMappingPage } from './pages/EpgMappingPage.js';
import { CacheManagerPage } from './pages/CacheManagerPage.js';
import { EmojiRulesPage } from './pages/EmojiRulesPage.js';
import { SettingsPage } from './pages/SettingsPage.js';

class App {
    constructor() {
        this.toast = new ToastNotification('#toastRoot');
        this.modal = new ModalDialog('#modalRoot');
        this.api = new ApiClient(this.toast);

        const deps = { api: this.api, toast: this.toast, modal: this.modal, switchTab: tab => this.switchTab(tab) };
        this.pages = {
            dashboard: new DashboardPage('#tab-dashboard', deps),
            channels: new ChannelsPage('#tab-channels', deps),
            epg: new EpgMappingPage('#tab-epg', deps),
            cache: new CacheManagerPage('#tab-cache', deps),
            'emoji-rules': new EmojiRulesPage('#tab-emoji-rules', deps),
            settings: new SettingsPage('#tab-settings', deps),
            admin: {
                container: document.querySelector('#tab-admin'),
                escapeHtml: s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c])),
                load: async () => {
                    const el = document.querySelector('#tab-admin');
                    if (!el) return;
                    const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                    let currentLines = 10;
                    let currentFile = 'app.log';

                    el.innerHTML = `
                        <div class="card" style="margin-bottom:1rem;">
                            <h2>Admin &amp; Database Tools</h2>
                            <p class="muted">Voer <code>database/update.sql</code> uit om eventuele openstaande database-migraties toe te passen.</p>
                            <button type="button" id="runUpdateSqlBtn" class="btn btn-primary" style="min-height:44px;">🛠️ Voer update.sql uit</button>
                            <div id="updateSqlResult" style="margin-top:1rem;"></div>
                        </div>

                        <details class="admin-collapsible-section collapsible-card" style="margin-bottom:1rem;">
                            <summary class="collapsible-card__header">
                                <span>♻️ Custom Groepen Backup &amp; Herstel</span>
                                <span class="collapsible-card__chevron">▼</span>
                            </summary>
                            <div class="collapsible-card__body stack-form" style="gap:0.75rem;">
                                <div id="customGroupsBackupStatus" class="muted" style="font-size:0.9rem;">Backupstatus laden...</div>
                                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;align-items:center;">
                                    <a href="index.php?route=api&action=custom_groups_backup_download" class="btn" style="min-height:44px;display:inline-flex;align-items:center;">⬇️ Download backup (custom_groups_latest.json)</a>
                                    <button type="button" id="restoreCustomGroupsBtn" class="btn btn-primary" style="min-height:44px;">♻️ 1-klik Herstel naar database</button>
                                    <label class="btn" style="min-height:44px;display:inline-flex;align-items:center;cursor:pointer;margin:0;">
                                        📂 Herstel vanuit lokaal JSON-bestand
                                        <input type="file" id="uploadCustomGroupsFile" accept=".json,application/json" style="display:none;">
                                    </label>
                                </div>
                            </div>
                        </details>

                        <div class="card">
                            <div class="card-header" style="margin-bottom:0.5rem;">
                                <h2>Applicatie Log (<code id="appLogPath">storage/logs/app.log</code>)</h2>
                                <span id="appLogMeta" class="muted" style="font-size:0.85rem;">Laden...</span>
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;align-items:center;">
                                <select id="logFileSelect" aria-label="Logbestand" style="min-height:44px;font-size:16px;padding:0.25rem 0.5rem;">
                                    <option value="app.log" selected>Applicatie Log (app.log)</option>
                                    <option value="sync.log">Sync Log (sync.log)</option>
                                </select>
                                <button type="button" id="logFewerBtn" class="btn btn-sm" style="min-height:44px;">Minder (-10)</button>
                                <select id="logLinesSelect" style="min-height:44px;font-size:16px;padding:0.25rem 0.5rem;">
                                    <option value="5">5 regels</option>
                                    <option value="10" selected>10 regels</option>
                                    <option value="25">25 regels</option>
                                    <option value="50">50 regels</option>
                                    <option value="100">100 regels</option>
                                    <option value="250">250 regels</option>
                                </select>
                                <button type="button" id="logMoreBtn" class="btn btn-sm" style="min-height:44px;">Meer (+10)</button>
                                <button type="button" id="logRefreshBtn" class="btn btn-sm" style="min-height:44px;">🔄 Vernieuwen</button>
                                <button type="button" id="logClearBtn" class="btn btn-sm btn-danger" style="min-height:44px;">🗑️ Log wissen</button>
                            </div>
                            <pre id="appLogBox" class="log-viewer-box">Laden...</pre>
                        </div>
                    `;

                    const loadLogs = async () => {
                        const box = document.getElementById('appLogBox');
                        const meta = document.getElementById('appLogMeta');
                        const pathEl = document.getElementById('appLogPath');
                        const sel = document.getElementById('logLinesSelect');
                        if (pathEl) {
                            pathEl.textContent = `storage/logs/${currentFile}`;
                        }
                        if (sel && Array.from(sel.options).some(o => Number(o.value) === currentLines)) {
                            sel.value = String(currentLines);
                        }
                        try {
                            const res = await this.api.get('app_logs', { lines: currentLines, file: currentFile });
                            const lines = res.lines || [];
                            if (meta) {
                                meta.textContent = `${res.total_lines ?? 0} regels totaal • ${res.file_size_kb ?? 0} KB${res.updated_at ? ` • ${res.updated_at}` : ''}`;
                            }
                            if (box) {
                                if (lines.length === 0) {
                                    box.textContent = '(Logbestand is leeg of bestaat nog niet)';
                                } else {
                                    box.innerHTML = lines.map(l => {
                                        const safe = esc(l);
                                        return /\[(API ERROR|XTREAM ERROR|FATAL)\]/i.test(l)
                                            ? `<span class="log-line-error">${safe}</span>`
                                            : safe;
                                    }).join('\n');
                                    box.scrollTop = box.scrollHeight;
                                }
                            }
                        } catch (err) {
                            if (box) box.textContent = `Fout bij laden van logs: ${err.message}`;
                        }
                    };

                    document.getElementById('runUpdateSqlBtn')?.addEventListener('click', async () => {
                        const resEl = document.getElementById('updateSqlResult');
                        try {
                            await this.api.post('run_update_sql');
                            this.toast.show('update.sql succesvol uitgevoerd!');
                            if (resEl) resEl.innerHTML = '<div class="alert alert-success">update.sql succesvol uitgevoerd.</div>';
                        } catch (err) {
                            if (resEl) resEl.innerHTML = `<div class="alert alert-error">${esc(err.message)}</div>`;
                        }
                    });

                    const loadBackupStatus = async () => {
                        const stEl = document.getElementById('customGroupsBackupStatus');
                        if (!stEl) return;
                        try {
                            const st = await this.api.get('custom_groups_backup_status');
                            if (!st.exists) {
                                stEl.textContent = 'Nog geen automatische backup aanwezig op schijf. Klik op Download backup om er direct een te genereren.';
                            } else {
                                const kb = Math.max(1, Math.round((st.size_bytes || 0) / 1024));
                                stEl.innerHTML = `Laatste backup: <strong>${esc(st.updated_at || '-')}</strong> · <strong>${st.group_count}</strong> custom groepen · <strong>${st.channel_count}</strong> kanalen (${kb} KB)`;
                            }
                        } catch (e) {
                            stEl.textContent = `Kon backupstatus niet laden: ${e.message}`;
                        }
                    };
                    loadBackupStatus();

                    document.getElementById('restoreCustomGroupsBtn')?.addEventListener('click', () => {
                        this.modal.confirm(
                            'Custom Groepen Herstellen',
                            'Weet je zeker dat je de laatste backup (custom_groups_latest.json) wilt terugzetten naar MariaDB?',
                            async () => {
                                const res = await this.api.post('custom_groups_backup_restore', {});
                                this.toast.show(`Herstel voltooid: ${res.groups_restored} groepen, ${res.channels_linked} kanalen gekoppeld (${res.channels_pending} in afwachting).`);
                                loadBackupStatus();
                            }
                        );
                    });

                    document.getElementById('uploadCustomGroupsFile')?.addEventListener('change', async ev => {
                        const file = ev.target.files?.[0];
                        if (!file) return;
                        try {
                            const text = await file.text();
                            const res = await this.api.post('custom_groups_backup_restore', { json_payload: text });
                            this.toast.show(`Herstel vanuit bestand voltooid: ${res.groups_restored} groepen, ${res.channels_linked} kanalen gekoppeld.`);
                            loadBackupStatus();
                        } finally {
                            ev.target.value = '';
                        }
                    });

                    document.getElementById('logFileSelect')?.addEventListener('change', e => {
                        currentFile = e.target.value === 'sync.log' ? 'sync.log' : 'app.log';
                        loadLogs();
                    });
                    document.getElementById('logFewerBtn')?.addEventListener('click', () => {
                        currentLines = Math.max(5, currentLines - 10);
                        loadLogs();
                    });
                    document.getElementById('logMoreBtn')?.addEventListener('click', () => {
                        currentLines = Math.min(500, currentLines + 10);
                        loadLogs();
                    });
                    document.getElementById('logLinesSelect')?.addEventListener('change', e => {
                        currentLines = Math.max(5, Math.min(500, Number(e.target.value) || 10));
                        loadLogs();
                    });
                    document.getElementById('logRefreshBtn')?.addEventListener('click', loadLogs);
                    document.getElementById('logCopyBtn')?.addEventListener('click', async e => {
                        const btn = e.currentTarget;
                        const box = document.getElementById('appLogBox');
                        const text = (box?.innerText || box?.textContent || '').trim();
                        if (!text || text === '(Logbestand is leeg of bestaat nog niet)') {
                            this.toast.show('Geen logregels om te kopiëren');
                            return;
                        }
                        let copied = false;
                        if (navigator.clipboard?.writeText && window.isSecureContext) {
                            try {
                                await navigator.clipboard.writeText(text);
                                copied = true;
                            } catch {
                                copied = false;
                            }
                        }
                        if (!copied) {
                            const ta = document.createElement('textarea');
                            ta.value = text;
                            ta.setAttribute('readonly', '');
                            ta.style.position = 'fixed';
                            ta.style.left = '-9999px';
                            ta.style.top = '0';
                            ta.style.fontSize = '16px';
                            document.body.appendChild(ta);
                            ta.focus();
                            ta.select();
                            ta.setSelectionRange(0, text.length);
                            try {
                                copied = document.execCommand('copy');
                            } catch {
                                copied = false;
                            }
                            document.body.removeChild(ta);
                        }
                        if (copied) {
                            this.toast.show('Applicatie log gekopieerd naar klembord!');
                            if (btn) {
                                btn.textContent = '✅ Gekopieerd';
                                setTimeout(() => {
                                    btn.textContent = '📋 Log kopiëren';
                                }, 1500);
                            }
                        } else {
                            this.toast.show('Kopiëren naar klembord mislukt');
                        }
                    });
                    document.getElementById('logClearBtn')?.addEventListener('click', async () => {
                        if (!confirm(`Weet je zeker dat je storage/logs/${currentFile} wilt wissen?`)) return;
                        await this.api.post('clear_app_logs', { file: currentFile });
                        this.toast.show(`${currentFile} gewist!`);
                        loadLogs();
                    });

                    await loadLogs();
                },
            },
        };
    }

    init() {
        window.addEventListener('error', ev => {
            this.toast?.show(`JS Fout: ${ev.message}`);
        });
        window.addEventListener('unhandledrejection', ev => {
            const msg = ev.reason?.message || String(ev.reason || 'Onbekende fout');
            this.toast?.show(`Fout: ${msg}`);
        });

        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => this.switchTab(btn.dataset.tab));
        });

        document.getElementById('adminToolsBtn')?.addEventListener('click', () => {
            this.switchTab('admin');
        });

        document.getElementById('themeToggle')?.addEventListener('click', () => {
            const html = document.documentElement;
            html.dataset.theme = html.dataset.theme === 'light' ? 'dark' : 'light';
        });

        document.getElementById('logoutBtn')?.addEventListener('click', async () => {
            await this.api.post('logout');
            window.location.reload();
        });

        this.switchTab('dashboard');
    }

    async switchTab(tab) {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
        document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === `tab-${tab}`));
        const page = this.pages[tab];
        if (!page) return;
        try {
            await page.load();
        } catch (e) {
            if (page.container) {
                page.container.innerHTML = `<div class="card"><div class="alert alert-error">Fout bij laden: ${page.escapeHtml(e.message)}</div></div>`;
            }
        }
    }
}

document.addEventListener('DOMContentLoaded', () => {
    new App().init();
});
