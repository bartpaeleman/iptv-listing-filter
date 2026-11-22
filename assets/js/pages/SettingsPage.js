import { BaseComponent } from '../core/BaseComponent.js';

export class SettingsPage extends BaseComponent {
    async load() {
        const s = await this.props.api.get('get_settings');
        this.setState({ settings: s });
    }

    render() {
        const s = this.state.settings;
        if (!s) {
            this.container.innerHTML = '<div class="card">Laden...</div>';
            return;
        }

        const cronCmd = `0 * * * * /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php >/dev/null 2>&1`;

        this.container.innerHTML = `
            <div class="card">
                <h2>Bronnen & Automatisering</h2>
                <form id="settingsForm" class="stack-form">
                    <label>M3U Bron URL (laat leeg om huidige te behouden)
                        <input type="url" id="settingM3uUrl" placeholder="${this.escapeHtml(s.m3u_source_url_masked || 'https://...')}">
                    </label>
                    <label>EPG Bron URL (laat leeg om huidige te behouden)
                        <input type="url" id="settingEpgUrl" placeholder="${this.escapeHtml(s.epg_source_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml')}">
                    </label>
                    <div class="grid-2">
                        <label>Sync Interval (uren)
                            <input type="number" id="settingIntervalHours" value="${s.sync_interval_hours}" min="1" max="720" required>
                        </label>
                        <label>Voorkeursuur (0-23)
                            <input type="number" id="settingPreferredHour" value="${s.sync_preferred_hour}" min="0" max="23" required>
                        </label>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" id="settingEnforceLan" ${s.enforce_lan_ip ? 'checked' : ''}>
                        <span>Beperk uitgaande feeds tot lokale LAN-subnets</span>
                    </label>
                    <button type="submit" class="btn btn-primary">Instellingen Opslaan</button>
                </form>
            </div>

            <div class="card">
                <h2>Beveiliging & QNAP Crontab</h2>
                <div class="endpoint-row">
                    <label>Uitgaand Feed Token</label>
                    <div class="copy-group">
                        <input type="text" id="settingOutputToken" value="${this.escapeHtml(s.output_token)}" readonly>
                        <button class="btn" id="regenTokenBtn">Regenereer Token</button>
                    </div>
                </div>
                <div class="endpoint-row" style="margin-top:1rem;">
                    <label>QNAP Crontab (elke uur controleren)</label>
                    <div class="copy-group">
                        <input type="text" id="qnapCronInput" value="${this.escapeHtml(cronCmd)}" readonly>
                        <button class="btn" id="copyCronBtn">Kopieer</button>
                    </div>
                </div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        document.getElementById('settingsForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_settings', {
                m3u_source_url: document.getElementById('settingM3uUrl').value,
                epg_source_url: document.getElementById('settingEpgUrl').value,
                sync_interval_hours: Number(document.getElementById('settingIntervalHours').value),
                sync_preferred_hour: Number(document.getElementById('settingPreferredHour').value),
                enforce_lan_ip: document.getElementById('settingEnforceLan').checked,
            });
            this.props.toast.show('Instellingen opgeslagen!');
            this.load();
        });

        document.getElementById('regenTokenBtn')?.addEventListener('click', async () => {
            const res = await this.props.api.post('regenerate_token');
            document.getElementById('settingOutputToken').value = res.output_token;
            this.props.toast.show('Nieuw token gegenereerd!');
        });

        document.getElementById('copyCronBtn')?.addEventListener('click', ev => {
            const input = document.getElementById('qnapCronInput');
            if (!input) return;
            ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
        });
    }
}
