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
                    el.innerHTML = `
                        <div class="card">
                            <h2>Admin &amp; Database Tools</h2>
                            <p class="muted">Voer <code>database/update.sql</code> uit om eventuele openstaande database-migraties toe te passen.</p>
                            <button type="button" id="runUpdateSqlBtn" class="btn btn-primary" style="min-height:44px;">🛠️ Voer update.sql uit</button>
                            <div id="updateSqlResult" style="margin-top:1rem;"></div>
                        </div>
                    `;
                    document.getElementById('runUpdateSqlBtn')?.addEventListener('click', async () => {
                        const resEl = document.getElementById('updateSqlResult');
                        try {
                            await this.api.post('run_update_sql');
                            this.toast.show('update.sql succesvol uitgevoerd!');
                            if (resEl) resEl.innerHTML = '<div class="alert alert-success">update.sql succesvol uitgevoerd.</div>';
                        } catch (err) {
                            if (resEl) resEl.innerHTML = `<div class="alert alert-error">${err.message}</div>`;
                        }
                    });
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
