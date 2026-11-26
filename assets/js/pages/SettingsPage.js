import { BaseComponent } from '../core/BaseComponent.js';
import { ClipboardHelper } from '../core/ClipboardHelper.js';

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
        this._appRoot = s.app_root || '';
        this.activeCronType = this.activeCronType || 'm3u';
        const m3uInt = Number(s.sync_m3u_interval_hours ?? s.sync_interval_hours ?? 72);
        const m3uHr = Number(s.sync_m3u_preferred_hour ?? s.sync_preferred_hour ?? 3);
        const epgInt = Number(s.sync_epg_interval_hours ?? 12);
        const epgHr = Number(s.sync_epg_preferred_hour ?? 4);
        this.cronState = {
            m3u: {
                interval_hours: m3uInt,
                preferred_hour: m3uHr,
                cron_expression: this.buildCronSchedule(m3uInt, m3uHr).expr,
                php_cli_path: '/mnt/ext/opt/apache/bin/php',
                active_rule: '',
            },
            epg: {
                interval_hours: epgInt,
                preferred_hour: epgHr,
                cron_expression: this.buildCronSchedule(epgInt, epgHr).expr,
                php_cli_path: '/mnt/ext/opt/apache/bin/php',
                active_rule: '',
            },
        };

        const base = window.location.origin + window.location.pathname.replace(/\/index\.php$/, '');
        const tok = s.output_token ? `&token=${encodeURIComponent(s.output_token)}` : '';
        const playlistUrl = `${base}/index.php?route=playlist${tok}`;
        const epgUrl = `${base}/index.php?route=epg${tok}`;
        const activeState = this.cronState[this.activeCronType];
        const initCron = this.buildCronSchedule(activeState.interval_hours, activeState.preferred_hour);
        const cronCmd = `${initCron.expr} /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php --${this.activeCronType} --force >/dev/null 2>&1`;

        const sourceMode = s.source_mode === 'separate_urls' ? 'separate_urls' : 'xtream';

        this.container.innerHTML = `
            <form id="settingsForm" class="stack-form">
                <details class="collapsible-card">
                    <summary class="collapsible-card__header">
                        <span>📡 Bronnen &amp; Import</span>
                        <span class="collapsible-card__chevron">▼</span>
                    </summary>
                    <div class="collapsible-card__body stack-form">
                        <div class="source-mode-selector" style="display:flex;gap:1rem;flex-wrap:wrap;margin-bottom:0.5rem;">
                            <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                <input type="radio" name="source_mode" value="xtream" ${sourceMode === 'xtream' ? 'checked' : ''}>
                                <span><strong>Xtream Codes API</strong> (M3U + EPG automatisch)</span>
                            </label>
                            <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                <input type="radio" name="source_mode" value="separate_urls" ${sourceMode === 'separate_urls' ? 'checked' : ''}>
                                <span><strong>Aparte M3U &amp; EPG links</strong></span>
                            </label>
                        </div>

                        <div id="xtreamFields" class="${sourceMode === 'xtream' ? '' : 'hidden'}" style="display:flex;flex-direction:column;gap:0.75rem;">
                            <label>Server URL
                                <input type="url" id="settingXtreamHost" value="${this.escapeHtml(s.xtream_host || '')}" placeholder="https://example.com:8080">
                            </label>
                            <div class="grid-2">
                                <label>Gebruikersnaam
                                    <input type="text" id="settingXtreamUser" placeholder="${this.escapeHtml(s.xtream_username_masked || 'Gebruikersnaam (laat leeg om te behouden)')}">
                                </label>
                                <label>Wachtwoord
                                    <input type="password" id="settingXtreamPass" placeholder="${s.xtream_password_set ? '•••••••• (laat leeg om te behouden)' : 'Wachtwoord'}">
                                </label>
                            </div>
                            <label>Output-parameters (optioneel)
                                <input type="text" id="settingXtreamOutputParams" value="${this.escapeHtml(s.xtream_output_params || 'type=m3u_plus&output=ts')}" placeholder="type=m3u_plus&output=ts">
                            </label>
                            <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.25rem;">
                                <span style="font-size:0.9rem;font-weight:600;">EPG-bron binnen Xtream</span>
                                <div style="display:flex;gap:1rem;flex-wrap:wrap;">
                                    <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                        <input type="radio" name="xtream_epg_mode" value="xtream_xmltv" ${(s.xtream_epg_mode || 'xtream_xmltv') === 'xtream_xmltv' ? 'checked' : ''}>
                                        <span>Standaard Xtream XMLTV (<code>/xmltv.php</code>)</span>
                                    </label>
                                    <label class="checkbox-row" style="min-height:44px;cursor:pointer;">
                                        <input type="radio" name="xtream_epg_mode" value="custom_url" ${s.xtream_epg_mode === 'custom_url' ? 'checked' : ''}>
                                        <span>Eigen aangepaste EPG-URL</span>
                                    </label>
                                </div>
                                <div id="xtreamCustomEpgWrap" class="${s.xtream_epg_mode === 'custom_url' ? '' : 'hidden'}">
                                    <label>Aangepaste EPG Bron URL (laat leeg om huidige te behouden)
                                        <input type="url" id="settingXtreamCustomEpgUrl" placeholder="${this.escapeHtml(s.xtream_custom_epg_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml')}">
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div id="separateUrlFields" class="${sourceMode === 'separate_urls' ? '' : 'hidden'}" style="display:flex;flex-direction:column;gap:0.75rem;">
                            <label>M3U Bron URL (laat leeg om huidige te behouden)
                                <input type="url" id="settingM3uUrl" placeholder="${this.escapeHtml(s.m3u_source_url_masked || 'https://...')}">
                            </label>
                            <label>EPG Bron URL (laat leeg om huidige te behouden)
                                <input type="url" id="settingEpgUrl" placeholder="${this.escapeHtml(s.epg_source_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml')}">
                            </label>
                        </div>
                    </div>
                </details>

                <details class="collapsible-card">
                    <summary class="collapsible-card__header">
                        <span>⏱️ Automatisering &amp; Crontab</span>
                        <span class="collapsible-card__chevron">▼</span>
                    </summary>
                    <div class="collapsible-card__body stack-form">
                        <div class="btn-group" role="tablist" aria-label="Cronjob selectie" style="margin-bottom:0.25rem;">
                            <button type="button" class="btn ${this.activeCronType !== 'epg' ? 'btn-primary' : ''}" data-cron-type="m3u" style="min-height:44px;">📺 M3U Kanalen Cronjob</button>
                            <button type="button" class="btn ${this.activeCronType === 'epg' ? 'btn-primary' : ''}" data-cron-type="epg" style="min-height:44px;">📅 EPG Gids Cronjob</button>
                        </div>

                        <div id="cronFieldsM3u" class="grid-2 ${this.activeCronType === 'epg' ? 'hidden' : ''}">
                            <label>M3U Sync Interval (uren)
                                <input type="number" id="settingM3uIntervalHours" value="${s.sync_m3u_interval_hours ?? s.sync_interval_hours}" min="1" max="720" required>
                            </label>
                            <label>M3U Voorkeursuur (0-23)
                                <input type="number" id="settingM3uPreferredHour" value="${s.sync_m3u_preferred_hour ?? s.sync_preferred_hour}" min="0" max="23" required>
                            </label>
                        </div>

                        <div id="cronFieldsEpg" class="grid-2 ${this.activeCronType !== 'epg' ? 'hidden' : ''}">
                            <label>EPG Sync Interval (uren)
                                <input type="number" id="settingEpgIntervalHours" value="${s.sync_epg_interval_hours ?? 12}" min="1" max="720" required>
                            </label>
                            <label>EPG Voorkeursuur (0-23)
                                <input type="number" id="settingEpgPreferredHour" value="${s.sync_epg_preferred_hour ?? 4}" min="0" max="23" required>
                            </label>
                        </div>

                        <div class="endpoint-row" style="margin-top:0.5rem;">
                            <div class="cron-instruction-line">
                                <span>Crontab (<strong id="qnapCronTypeLabel">${this.activeCronType === 'epg' ? 'EPG' : 'M3U'}</strong>)</span>
                                <span class="cron-paren-accent">(<span id="qnapCronLabel">${this.escapeHtml(initCron.label)}</span>)</span>
                            </div>
                            <div class="copy-group">
                                <input type="text" id="qnapCronInput" value="${this.escapeHtml(cronCmd)}" readonly>
                                <button type="button" class="btn" id="copyCronBtn">Kopieer</button>
                            </div>
                        </div>

                        <hr style="border:none;border-top:1px solid var(--border-color, #2a2f3a);margin:0.5rem 0;">
                        <div id="crontabManagerBox" class="stack-form" style="gap:0.75rem;">
                            <p class="muted" style="margin:0;">Crontab status laden...</p>
                        </div>
                    </div>
                </details>

                <button type="submit" class="btn btn-primary" style="min-height:44px;">Instellingen Opslaan</button>
            </form>
        `;

        this.bindEvents();
    }

    bindEvents() {
        this.container.querySelectorAll('input[name="source_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const mode = this.container.querySelector('input[name="source_mode"]:checked')?.value || 'xtream';
                document.getElementById('xtreamFields')?.classList.toggle('hidden', mode !== 'xtream');
                document.getElementById('separateUrlFields')?.classList.toggle('hidden', mode !== 'separate_urls');
            });
        });

        this.container.querySelectorAll('input[name="xtream_epg_mode"]').forEach(radio => {
            radio.addEventListener('change', () => {
                const epgMode = this.container.querySelector('input[name="xtream_epg_mode"]:checked')?.value || 'xtream_xmltv';
                document.getElementById('xtreamCustomEpgWrap')?.classList.toggle('hidden', epgMode !== 'custom_url');
            });
        });

        document.getElementById('settingsForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            const sourceMode = this.container.querySelector('input[name="source_mode"]:checked')?.value || 'xtream';
            const xtreamEpgMode = this.container.querySelector('input[name="xtream_epg_mode"]:checked')?.value || 'xtream_xmltv';
            await this.props.api.post('save_settings', {
                source_mode: sourceMode,
                xtream_host: document.getElementById('settingXtreamHost')?.value || '',
                xtream_username: document.getElementById('settingXtreamUser')?.value || '',
                xtream_password: document.getElementById('settingXtreamPass')?.value || '',
                xtream_output_params: document.getElementById('settingXtreamOutputParams')?.value || 'type=m3u_plus&output=ts',
                xtream_epg_mode: xtreamEpgMode,
                xtream_custom_epg_url: document.getElementById('settingXtreamCustomEpgUrl')?.value || '',
                m3u_source_url: document.getElementById('settingM3uUrl')?.value || '',
                epg_source_url: document.getElementById('settingEpgUrl')?.value || '',
                sync_m3u_interval_hours: Number(document.getElementById('settingM3uIntervalHours').value),
                sync_m3u_preferred_hour: Number(document.getElementById('settingM3uPreferredHour').value),
                sync_epg_interval_hours: Number(document.getElementById('settingEpgIntervalHours').value),
                sync_epg_preferred_hour: Number(document.getElementById('settingEpgPreferredHour').value),
            });
            this.props.toast.show('Instellingen opgeslagen!');
            this.load();
        });

        const syncFromInputs = (type) => {
            const intEl = document.getElementById(type === 'epg' ? 'settingEpgIntervalHours' : 'settingM3uIntervalHours');
            const hrEl = document.getElementById(type === 'epg' ? 'settingEpgPreferredHour' : 'settingM3uPreferredHour');
            const interval = Math.max(1, parseInt(intEl?.value || (type === 'epg' ? '12' : '72'), 10) || 1);
            const hour = Math.max(0, Math.min(23, parseInt(hrEl?.value || (type === 'epg' ? '4' : '3'), 10) || 0));
            const cron = this.buildCronSchedule(interval, hour);

            if (this.cronState?.[type]) {
                this.cronState[type].interval_hours = interval;
                this.cronState[type].preferred_hour = hour;
                this.cronState[type].cron_expression = cron.expr;
            }

            if (this.activeCronType === type) {
                const labelEl = document.getElementById('qnapCronLabel');
                const inputEl = document.getElementById('qnapCronInput');
                const exprEl = document.getElementById('cronExprInput');
                const presetEl = document.getElementById('cronPresetSelect');
                if (labelEl) labelEl.textContent = cron.label;
                if (inputEl && this._cronCmdBase) {
                    const baseWithFlag = this._cronCmdBase.replace(/\s+--force/, ` --${type} --force`);
                    inputEl.value = `${cron.expr} ${baseWithFlag}`;
                }
                if (exprEl) exprEl.value = cron.expr;
                if (presetEl) {
                    const hasPreset = [...presetEl.options].some(o => o.value === cron.expr);
                    presetEl.value = hasPreset ? cron.expr : 'custom';
                }
            }
        };

        ['settingM3uIntervalHours', 'settingM3uPreferredHour'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => syncFromInputs('m3u'));
            document.getElementById(id)?.addEventListener('change', () => syncFromInputs('m3u'));
        });
        ['settingEpgIntervalHours', 'settingEpgPreferredHour'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => syncFromInputs('epg'));
            document.getElementById(id)?.addEventListener('change', () => syncFromInputs('epg'));
        });

        this.container.querySelectorAll('[data-cron-type]').forEach(btn => {
            btn.addEventListener('click', () => {
                const nextType = btn.dataset.cronType === 'epg' ? 'epg' : 'm3u';
                if (this.activeCronType === nextType) return;

                // Save current tab state before switching
                const prevType = this.activeCronType;
                const prevExpr = document.getElementById('cronExprInput')?.value?.trim();
                const prevPhp = document.getElementById('cronPhpPathInput')?.value?.trim();
                if (this.cronState?.[prevType]) {
                    if (prevExpr) this.cronState[prevType].cron_expression = prevExpr;
                    if (prevPhp) this.cronState[prevType].php_cli_path = prevPhp;
                }

                this.activeCronType = nextType;
                this.container.querySelectorAll('[data-cron-type]').forEach(b => {
                    b.classList.toggle('btn-primary', b.dataset.cronType === nextType);
                });
                document.getElementById('cronFieldsM3u')?.classList.toggle('hidden', nextType !== 'm3u');
                document.getElementById('cronFieldsEpg')?.classList.toggle('hidden', nextType !== 'epg');
                const typeLabel = document.getElementById('qnapCronTypeLabel');
                if (typeLabel) typeLabel.textContent = nextType === 'epg' ? 'EPG' : 'M3U';
                this.loadCrontabStatus();
            });
        });

        document.getElementById('copyCronBtn')?.addEventListener('click', ev => {
            const input = document.getElementById('qnapCronInput');
            if (!input) return;
            ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
        });

        this.container.querySelectorAll('.copy-setting-btn').forEach(btn => {
            btn.addEventListener('click', ev => {
                const input = document.getElementById(btn.dataset.copy);
                if (!input) return;
                ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
            });
        });

        this._cronCmdBase = `/mnt/ext/opt/apache/bin/php ${this._appRoot || ''}/bin/sync.php --force >/dev/null 2>&1`;
        const updateCronPreview = () => {
            const interval = Math.max(1, parseInt(document.getElementById('settingIntervalHours')?.value || '72', 10) || 1);
            const hour = Math.max(0, Math.min(23, parseInt(document.getElementById('settingPreferredHour')?.value || '3', 10) || 0));
            const cron = this.buildCronSchedule(interval, hour);
            const labelEl = document.getElementById('qnapCronLabel');
            const inputEl = document.getElementById('qnapCronInput');
            if (labelEl) labelEl.textContent = cron.label;
            if (inputEl) inputEl.value = `${cron.expr} ${this._cronCmdBase}`;
            const presetEl = document.getElementById('cronPresetSelect');
            const exprEl = document.getElementById('cronExprInput');
            if (presetEl) presetEl.value = 'custom';
            if (exprEl) exprEl.value = cron.expr;
        };
        document.getElementById('settingIntervalHours')?.addEventListener('input', updateCronPreview);
        document.getElementById('settingIntervalHours')?.addEventListener('change', updateCronPreview);
        document.getElementById('settingPreferredHour')?.addEventListener('input', updateCronPreview);
        document.getElementById('settingPreferredHour')?.addEventListener('change', updateCronPreview);

        document.getElementById('addEpgSourceForm')?.addEventListener('submit', async ev => {
            ev.preventDefault();
            await this.props.api.post('save_epg_source', {
                name: document.getElementById('newEpgName').value,
                url: document.getElementById('newEpgUrl').value,
                priority: Number(document.getElementById('newEpgPrio').value),
            });
            this.props.toast.show('EPG bron toegevoegd!');
            this.load();
        });

        this.container.querySelectorAll('.epg-src-toggle').forEach(cb => {
            cb.addEventListener('change', async () => {
                await this.props.api.post('toggle_epg_source', { id: Number(cb.dataset.id), is_enabled: cb.checked });
                this.props.toast.show('EPG bron bijgewerkt');
            });
        });

        this.container.querySelectorAll('.test-epg-src-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                btn.disabled = true;
                btn.textContent = '⏳...';
                try {
                    const stats = await this.props.api.post('test_epg_source', { id: Number(btn.dataset.id) });
                    this.props.toast.show(`OK: ${stats.epg_channels} zenders`);
                    this.load();
                } catch {
                    this.load();
                }
            });
        });

        this.container.querySelectorAll('.del-epg-src-btn').forEach(btn => {
            btn.addEventListener('click', async () => {
                await this.props.api.post('delete_epg_source', { id: Number(btn.dataset.id) });
                this.props.toast.show('EPG bron verwijderd');
                this.load();
            });
        });

        this.loadCrontabStatus();
    }

    async loadCrontabStatus() {
        const box = document.getElementById('crontabManagerBox');
        if (!box) return;
        try {
            const st = await this.props.api.get('crontab_status');
            if (!st.supported) {
                box.innerHTML = `<p class="muted" style="margin:0;">Crontab-beheer wordt op deze server niet ondersteund.</p>`;
                return;
            }

            const presets = [
                { value: '0 * * * *', label: 'Elk uur (0 * * * *)' },
                { value: '0 */6 * * *', label: 'Elke 6 uur (0 */6 * * *)' },
                { value: '0 3 * * *', label: 'Dagelijks om 03:00 (0 3 * * *)' },
                { value: 'custom', label: 'Aangepast schema' },
            ];
            const activeType = this.activeCronType === 'epg' ? 'epg' : 'm3u';
            const job = st.jobs?.[activeType] || {
                raw_line: st.raw_line || '',
                current_schedule: st.current_schedule || '',
                current_command: st.current_command || '',
            };
            const typeLabel = activeType === 'epg' ? 'EPG Gids' : 'M3U Kanalen';
            const intervalElId = activeType === 'epg' ? 'settingEpgIntervalHours' : 'settingM3uIntervalHours';
            const hourElId = activeType === 'epg' ? 'settingEpgPreferredHour' : 'settingM3uPreferredHour';

            const currentExpr = job.current_schedule || document.getElementById('qnapCronInput')?.value?.split(/\s+/).slice(0, 5).join(' ') || (activeType === 'epg' ? '0 */12 * * *' : '0 3 */3 * *');
            const isPreset = presets.some(p => p.value === currentExpr);
            const selectedPreset = isPreset ? currentExpr : 'custom';

            const settings = this.state.settings || {};
            const execMode = settings.cron_exec_mode || 'direct';
            const hasSudoPass = Boolean(settings.has_cron_sudo_password);
            const cronStatus = settings.cron_status || { can_write: Boolean(st.writable), mode: execMode, message: st.permission_hint || '', fix_command: 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab' };
            const canWrite = Boolean(cronStatus.can_write);

            const m3uActive = Boolean(st.jobs?.m3u?.raw_line);
            const epgActive = Boolean(st.jobs?.epg?.raw_line);
            const crontabTargetLabel = st.backend === 'qnap' ? '/etc/config/crontab' : 'crontab';

            box.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                    <strong>Interactief Crontab-beheer (${typeLabel})</strong>
                    <div style="display:flex;gap:0.4rem;flex-wrap:wrap;">
                        <span class="badge ${m3uActive ? 'badge-success' : 'badge-warning'}">📺 M3U Kanalen: ${m3uActive ? `✅ Actief in ${crontabTargetLabel}` : '⚠️ Niet ingesteld'}</span>
                        <span class="badge ${epgActive ? 'badge-success' : 'badge-warning'}">📅 EPG Gids: ${epgActive ? `✅ Actief in ${crontabTargetLabel}` : '⚠️ Niet ingesteld'}</span>
                    </div>
                </div>
                ${job.raw_line ? `<div class="muted" style="font-size:0.85rem;">Huidige regel: <code>${this.escapeHtml(job.raw_line)}</code></div>` : ''}
                ${!st.writable ? `
                    <div class="alert alert-warning" style="padding:0.75rem;border-radius:6px;background:rgba(245,158,11,0.12);border:1px solid rgba(245,158,11,0.4);">
                        <p style="margin:0 0 0.5rem 0;font-size:0.88rem;">${this.escapeHtml(st.permission_hint)}</p>
                        <div class="copy-group">
                            <input type="text" id="cronPermCmdInput" value="${this.escapeHtml(cronStatus.fix_command || 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab')}" readonly style="font-size:16px;">
                            <button type="button" class="btn btn-sm" id="copyCronPermBtn" style="min-height:44px;">Kopieer SSH-commando</button>
                        </div>
                    </div>
                ` : ''}
                <div class="grid-2">
                    <label>Schema Preset
                        <select id="cronPresetSelect" style="font-size:16px;">
                            ${presets.map(p => `<option value="${this.escapeHtml(p.value)}" ${p.value === selectedPreset ? 'selected' : ''}>${this.escapeHtml(p.label)}</option>`).join('')}
                        </select>
                    </label>
                    <label>Cron Expressie (5 velden)
                        <input type="text" id="cronExprInput" value="${this.escapeHtml(currentExpr)}" style="font-size:16px;">
                    </label>
                </div>
                <label>PHP-CLI Pad
                    <input type="text" id="cronPhpPathInput" value="${this.escapeHtml(st.default_php_path || '/usr/bin/php')}" style="font-size:16px;">
                </label>
                <details id="cronElevationDetails" ${!st.writable ? 'open' : ''} style="border:1px solid var(--border-color, #2a2f3a);border-radius:6px;padding:0.65rem 0.85rem;background:rgba(0,0,0,0.12);">
                    <summary style="cursor:pointer;font-weight:600;min-height:44px;display:flex;align-items:center;">🔐 Toegang voor /etc/config/crontab (QNAP)</summary>
                    <div id="cronElevationNotice" class="muted" style="font-size:0.85rem;margin:0.35rem 0 0.65rem 0;">
                        Alleen nodig als de webserver geen directe schrijfrechten heeft. Wachtwoorden worden nooit opgeslagen.
                    </div>
                    <div class="grid-2" style="gap:0.65rem;">
                        <label>Authenticatiemethode
                            <select id="cronAuthModeSelect" style="font-size:16px;">
                                <option value="">Automatisch / Direct</option>
                                <option value="sudo">Automatisch / Sudo</option>
                                <option value="ssh">QNAP SSH Login (admin / root)</option>
                            </select>
                        </label>
                        <label id="cronSshUserWrap" class="hidden">SSH Gebruikersnaam
                            <input type="text" id="cronSshUserInput" value="admin" autocomplete="off" style="font-size:16px;">
                        </label>
                    </div>
                    <label id="cronSshPassWrap" class="hidden" style="margin-top:0.5rem;">Wachtwoord (eenmalig, niet opgeslagen)
                        <input type="password" id="cronSshPassInput" value="" autocomplete="off" placeholder="Sudo of SSH wachtwoord" style="font-size:16px;">
                    </label>
                </details>
                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                    <button type="button" class="btn btn-primary" id="saveCrontabBtn" style="min-height:44px;font-size:16px;">
                        💾 Opslaan in ${crontabTargetLabel}
                    </button>
                    <button type="button" class="btn" id="testCrontabBtn" style="min-height:44px;">▶️ ${typeLabel} nu testen</button>
                    <button type="button" class="btn" id="viewCrontabBtn" style="min-height:44px;">📋 Bekijk Crontab</button>
                    ${job.raw_line ? `<button type="button" class="btn btn-danger" id="deleteCrontabBtn" style="min-height:44px;font-size:16px;">🗑️ Verwijderen uit crontab</button>` : ''}
                </div>
                <div id="crontabViewerBox" class="hidden" style="margin-top:0.5rem;"></div>
            `;

            document.getElementById('cronAuthModeSelect')?.addEventListener('change', ev => {
                const mode = ev.target.value;
                document.getElementById('cronSshUserWrap')?.classList.toggle('hidden', mode !== 'ssh');
                document.getElementById('cronSshPassWrap')?.classList.toggle('hidden', mode !== 'sudo' && mode !== 'ssh');
            });

            document.getElementById('cronExecModeSelect')?.addEventListener('change', ev => {
                const passGroup = document.getElementById('cronSudoPassGroup');
                if (passGroup) passGroup.style.display = ev.target.value === 'sudo_password' ? 'flex' : 'none';
            });

            document.getElementById('saveCronExecModeBtn')?.addEventListener('click', async ev => {
                const btn = ev.currentTarget;
                const mode = document.getElementById('cronExecModeSelect')?.value || 'direct';
                const pass = document.getElementById('cronSudoPassInput')?.value || '';
                const clearPass = Boolean(document.getElementById('clearCronSudoPassChk')?.checked);
                btn.disabled = true;
                try {
                    await this.props.api.post('save_settings', {
                        cron_exec_mode: mode,
                        cron_sudo_password: pass,
                        clear_cron_sudo_password: clearPass,
                    });
                    const updated = await this.props.api.get('get_settings');
                    this.state.settings = updated;
                    this.props.toast.show('Cron executiemodus opgeslagen!');
                    await this.loadCrontabStatus();
                } catch (err) {
                    this.props.toast.show(err?.message || 'Opslaan mislukt.', 'error');
                } finally {
                    btn.disabled = false;
                }
            });

            document.getElementById('copyCronPermBtn')?.addEventListener('click', ev => {
                const input = document.getElementById('cronPermCmdInput');
                if (input) ClipboardHelper.copyText(input.value, ev.currentTarget, null, this.props.toast);
            });

            const syncFromCronExpression = (expr) => {
                const parts = String(expr || '').trim().split(/\s+/);
                if (parts.length !== 5) return;
                const [, hPart, dPart] = parts;
                const intervalEl = document.getElementById(intervalElId);
                const hourEl = document.getElementById(hourElId);

                let newInterval = null;
                let newHour = null;

                if (hPart === '*' && dPart === '*') {
                    newInterval = 1;
                } else if (/^\*\/(\d+)$/.test(hPart) && dPart === '*') {
                    newInterval = Math.max(1, parseInt(hPart.slice(2), 10));
                } else if (/^\d+$/.test(hPart) && dPart === '*') {
                    newInterval = 24;
                    newHour = Math.max(0, Math.min(23, parseInt(hPart, 10)));
                } else if (/^\d+$/.test(hPart) && /^\*\/(\d+)$/.test(dPart)) {
                    newInterval = Math.max(1, parseInt(dPart.slice(2), 10)) * 24;
                    newHour = Math.max(0, Math.min(23, parseInt(hPart, 10)));
                }

                if (newInterval !== null && intervalEl) intervalEl.value = String(newInterval);
                if (newHour !== null && hourEl) hourEl.value = String(newHour);

                const effectiveInterval = newInterval ?? (parseInt(intervalEl?.value || '72', 10) || 72);
                const effectiveHour = newHour ?? (parseInt(hourEl?.value || '3', 10) || 3);
                const cron = this.buildCronSchedule(effectiveInterval, effectiveHour);
                const labelEl = document.getElementById('qnapCronLabel');
                const inputEl = document.getElementById('qnapCronInput');
                if (labelEl) labelEl.textContent = cron.label;
                if (inputEl && this._cronCmdBase) {
                    const baseWithFlag = this._cronCmdBase.replace(/\s+--force/, ` --${activeType} --force`);
                    inputEl.value = `${expr.trim()} ${baseWithFlag}`;
                }
            };

            document.getElementById('cronPresetSelect')?.addEventListener('change', ev => {
                const val = ev.target.value;
                const exprInput = document.getElementById('cronExprInput');
                if (val !== 'custom' && exprInput) {
                    exprInput.value = val;
                    syncFromCronExpression(val);
                }
            });

            document.getElementById('cronExprInput')?.addEventListener('input', ev => {
                const val = ev.target.value.trim();
                const presetEl = document.getElementById('cronPresetSelect');
                if (presetEl) {
                    const hasPreset = [...presetEl.options].some(o => o.value === val);
                    presetEl.value = hasPreset ? val : 'custom';
                }
                syncFromCronExpression(val);
            });

            syncFromCronExpression(currentExpr);

            const getElevationPayload = () => {
                const authMode = document.getElementById('cronAuthModeSelect')?.value || '';
                const sshUser = document.getElementById('cronSshUserInput')?.value?.trim() || 'admin';
                const sshPassword = document.getElementById('cronSshPassInput')?.value || '';
                return authMode ? { auth_mode: authMode, ssh_user: sshUser, ssh_password: sshPassword } : {};
            };

            const handleElevationNeeded = (err) => {
                const details = document.getElementById('cronElevationDetails');
                const notice = document.getElementById('cronElevationNotice');
                if (details) details.open = true;
                if (notice) {
                    notice.textContent = err?.message || 'Geen directe schrijfrechten. Kies Sudo of QNAP SSH Login en probeer opnieuw.';
                    notice.style.color = '#f59e0b';
                }
                const modeSelect = document.getElementById('cronAuthModeSelect');
                if (modeSelect && !modeSelect.value) {
                    modeSelect.value = 'ssh';
                    modeSelect.dispatchEvent(new Event('change'));
                }
            };

            document.getElementById('saveCrontabBtn')?.addEventListener('click', async () => {
                const schedule = document.getElementById('cronExprInput')?.value?.trim() || '';
                const phpPath = document.getElementById('cronPhpPathInput')?.value?.trim() || '';
                const intEl = document.getElementById(intervalElId);
                const hrEl = document.getElementById(hourElId);
                const intervalHours = Math.max(1, parseInt(intEl?.value || (activeType === 'epg' ? '12' : '72'), 10) || 1);
                const preferredHour = Math.max(0, Math.min(23, parseInt(hrEl?.value || (activeType === 'epg' ? '4' : '3'), 10) || 0));

                try {
                    const res = await this.props.api.post('crontab_save', {
                        job_type: activeType,
                        type: activeType,
                        interval_hours: intervalHours,
                        preferred_hour: preferredHour,
                        cron_expression: schedule,
                        schedule,
                        php_cli_path: phpPath,
                        php_path: phpPath,
                        ...getElevationPayload(),
                    });

                    if (res?.needs_elevation || res?.ok === false) {
                        handleElevationNeeded(res);
                        this.props.toast.show(res?.message || 'Geen directe schrijfrechten. Vul QNAP SSH/Sudo in.', 'error');
                        return;
                    }

                    if (this.cronState?.[activeType]) {
                        this.cronState[activeType].interval_hours = res?.interval_hours ?? intervalHours;
                        this.cronState[activeType].preferred_hour = res?.preferred_hour ?? preferredHour;
                        this.cronState[activeType].cron_expression = res?.cron_expression ?? schedule;
                        this.cronState[activeType].php_cli_path = phpPath;
                        this.cronState[activeType].active_rule = res?.active_rule ?? '';
                    }

                    const passInput = document.getElementById('cronSshPassInput');
                    if (passInput) passInput.value = '';
                    this.props.toast.show(`${typeLabel} cronjob opgeslagen!`);
                    this.loadCrontabStatus();
                } catch (err) {
                    if (err?.data?.needs_elevation || String(err?.message || '').includes('schrijfrechten')) {
                        handleElevationNeeded(err);
                    }
                    this.props.toast.show(err?.message || 'Opslaan van cronjob mislukt.', 'error');
                }
            });

            const renderCrontabViewer = async () => {
                const viewer = document.getElementById('crontabViewerBox');
                if (!viewer) return;
                viewer.classList.remove('hidden');
                viewer.innerHTML = '<p class="muted" style="margin:0;">Crontab ophalen...</p>';
                try {
                    const data = await this.props.api.get('get_crontab');
                    const lines = String(data.content || '').split(/\r\n|\r|\n/).map(line => {
                        const escaped = this.escapeHtml(line);
                        if (line.includes('sync.php')) {
                            return `<span style="display:block;background:rgba(13,148,136,0.18);color:#5eead4;padding:0 0.25rem;border-radius:3px;">${escaped}</span>`;
                        }
                        return escaped;
                    }).join('\n');

                    viewer.innerHTML = `
                        <div style="border:1px solid var(--border-color, #2a2f3a);border-radius:6px;padding:0.75rem;background:rgba(0,0,0,0.2);">
                            <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;margin-bottom:0.5rem;">
                                <span class="muted" style="font-size:0.85rem;">Bron: <code>${this.escapeHtml(data.source || 'crontab -l')}</code></span>
                                <div style="display:flex;gap:0.5rem;">
                                    <button type="button" class="btn btn-sm" id="refreshCrontabViewerBtn">🔄 Vernieuwen</button>
                                    <button type="button" class="btn btn-sm" id="closeCrontabViewerBtn">✖️ Sluiten</button>
                                </div>
                            </div>
                            <pre style="margin:0;max-height:260px;overflow:auto;font-size:0.82rem;line-height:1.45;white-space:pre-wrap;word-break:break-word;">${lines || '(leeg)'}</pre>
                        </div>
                    `;
                    document.getElementById('refreshCrontabViewerBtn')?.addEventListener('click', renderCrontabViewer);
                    document.getElementById('closeCrontabViewerBtn')?.addEventListener('click', () => {
                        viewer.classList.add('hidden');
                    });
                } catch (err) {
                    viewer.innerHTML = `<p class="muted" style="margin:0;">Kon crontab niet ophalen: ${this.escapeHtml(err.message)}</p>`;
                }
            };

            document.getElementById('viewCrontabBtn')?.addEventListener('click', renderCrontabViewer);

            document.getElementById('deleteCrontabBtn')?.addEventListener('click', async () => {
                try {
                    const res = await this.props.api.post('crontab_delete', { type: activeType, ...getElevationPayload() });
                    if (res?.needs_elevation || res?.ok === false) {
                        handleElevationNeeded(res);
                        this.props.toast.show(res?.message || 'Geen directe schrijfrechten. Vul QNAP SSH/Sudo in.', 'error');
                        return;
                    }
                    const passInput = document.getElementById('cronSshPassInput');
                    if (passInput) passInput.value = '';
                    this.props.toast.show(`${typeLabel} cronjob gewist!`);
                    this.loadCrontabStatus();
                } catch (err) {
                    if (err?.data?.needs_elevation || String(err?.message || '').includes('schrijfrechten')) {
                        handleElevationNeeded(err);
                    }
                    this.props.toast.show(err?.message || 'Wissen van cronjob mislukt.', 'error');
                }
            });

            document.getElementById('restartCrontabBtn')?.addEventListener('click', async ev => {
                const btn = ev.currentTarget;
                const origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Bezig met herstarten...';
                try {
                    const res = await this.props.api.post('restart_cron', {});
                    this.props.toast.show(res?.message || 'Crontab succesvol herstart!');
                } catch (err) {
                    this.props.toast.show(err?.message || 'Crontab herstarten mislukt.', 'error');
                } finally {
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            });

            document.getElementById('testCrontabBtn')?.addEventListener('click', async ev => {
                const btn = ev.currentTarget;
                const origText = btn.textContent;
                btn.disabled = true;
                btn.textContent = 'Bezig met testen...';
                try {
                    const res = await this.props.api.post('test_cron', { mode: activeType });
                    this.props.toast.show(res?.message || `${typeLabel} cronjob-test voltooid!`);
                    await this.loadCrontabStatus();
                } catch (err) {
                    this.props.toast.show(err?.message || 'Cronjob-test mislukt.', 'error');
                } finally {
                    btn.disabled = false;
                    btn.textContent = origText;
                }
            });
        } catch (e) {
            box.innerHTML = `<p class="muted" style="margin:0;">Kon crontab status niet laden: ${this.escapeHtml(e.message)}</p>`;
        }
    }

    buildCronSchedule(interval, hour) {
        const hStr = String(hour).padStart(2, '0');
        if (interval <= 1) {
            return { expr: '0 * * * *', label: 'elke uur' };
        }
        if (interval < 24) {
            return { expr: `0 */${interval} * * *`, label: `elke ${interval} uur` };
        }
        if (interval === 24) {
            return { expr: `0 ${hour} * * *`, label: `dagelijks om ${hStr}:00` };
        }
        const days = Math.max(1, Math.round(interval / 24));
        return {
            expr: `0 ${hour} */${days} * *`,
            label: `elke ${days} dagen (${interval}u) om ${hStr}:00`,
        };
    }
}
