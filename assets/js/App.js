import { ApiClient } from './core/ApiClient.js';
import { ToastNotification } from './components/ToastNotification.js';
import { ModalDialog } from './components/ModalDialog.js';
import { DashboardPage } from './pages/DashboardPage.js';
import { ChannelsPage } from './pages/ChannelsPage.js';
import { EpgMappingPage } from './pages/EpgMappingPage.js';
import { CacheManagerPage } from './pages/CacheManagerPage.js';
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
            settings: new SettingsPage('#tab-settings', deps),
        };
    }

    init() {
        document.querySelectorAll('.tab-btn').forEach(btn => {
            btn.addEventListener('click', () => this.switchTab(btn.dataset.tab));
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
