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
        this._appRoot = s.app_root || '';

        const base = window.location.origin + window.location.pathname.replace(/\/index\.php$/, '');
        const tok = s.output_token ? `&token=${encodeURIComponent(s.output_token)}` : '';
        const playlistUrl = `${base}/index.php?route=playlist${tok}`;
        const epgUrl = `${base}/index.php?route=epg${tok}`;
        const initCron = this.buildCronSchedule(Number(s.sync_interval_hours || 72), Number(s.sync_preferred_hour || 3));
        const cronCmd = `${initCron.expr} /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php --force >/dev/null 2>&1`;

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
                        <div class="grid-2">
                            <label>Sync Interval (uren)
                                <input type="number" id="settingIntervalHours" value="${s.sync_interval_hours}" min="1" max="720" required>
                            </label>
                            <label>Voorkeursuur (0-23)
                                <input type="number" id="settingPreferredHour" value="${s.sync_preferred_hour}" min="0" max="23" required>
                            </label>
                        </div>
                        <div class="endpoint-row" style="margin-top:0.5rem;">
                            <div class="cron-instruction-line">
                                <span>Crontab</span>
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
                sync_interval_hours: Number(document.getElementById('settingIntervalHours').value),
                sync_preferred_hour: Number(document.getElementById('settingPreferredHour').value),
            });
            this.props.toast.show('Instellingen opgeslagen!');
            this.load();
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
            const currentExpr = st.current_schedule || '0 3 * * *';
            const isPreset = presets.some(p => p.value === currentExpr);
            const selectedPreset = isPreset ? currentExpr : 'custom';

            const settings = this.state.settings || {};
            const execMode = settings.cron_exec_mode || 'direct';
            const hasSudoPass = Boolean(settings.has_cron_sudo_password);
            const cronStatus = settings.cron_status || { can_write: Boolean(st.writable), mode: execMode, message: st.permission_hint || '', fix_command: 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab' };
            const canWrite = Boolean(cronStatus.can_write);

            box.innerHTML = `
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                    <strong>Interactief Crontab-beheer</strong>
                    <span class="badge ${st.raw_line ? 'badge-success' : 'badge-warning'}">
                        ${st.raw_line ? 'Actief' : 'Niet ingesteld'}
                    </span>
                </div>
                ${st.raw_line ? `<div class="muted" style="font-size:0.85rem;">Huidige regel: <code>${this.escapeHtml(st.raw_line)}</code></div>` : ''}
                <div class="cron-exec-box">
                    <label>Executiemodus voor Crontab-acties
                        <select id="cronExecModeSelect">
                            <option value="direct" ${execMode === 'direct' ? 'selected' : ''}>Direct (PHP webserver user httpdusr)</option>
                            <option value="sudo_nopasswd" ${execMode === 'sudo_nopasswd' ? 'selected' : ''}>Via sudo (wachtwoordloos / sudoers)</option>
                            <option value="sudo_password" ${execMode === 'sudo_password' ? 'selected' : ''}>Via sudo / root wachtwoord</option>
                        </select>
                    </label>
                    <div id="cronSudoPassGroup" style="display:${execMode === 'sudo_password' ? 'flex' : 'none'};flex-direction:column;gap:0.5rem;margin-top:0.5rem;">
                        <label>Sudo / Root Wachtwoord
                            <input type="password" id="cronSudoPassInput" placeholder="${hasSudoPass ? '•••••••• (opgeslagen)' : 'Voer sudo/root wachtwoord in'}" autocomplete="new-password">
                        </label>
                        ${hasSudoPass ? `<label style="display:flex;align-items:center;gap:0.4rem;font-size:0.85rem;"><input type="checkbox" id="clearCronSudoPassChk"> Opgeslagen wachtwoord wissen</label>` : ''}
                    </div>
                    <div style="margin-top:0.5rem;">
                        <button type="button" class="btn btn-sm" id="saveCronExecModeBtn" style="min-height:44px;">Executiemodus opslaan</button>
                    </div>
                </div>
                ${!canWrite ? `
                    <div class="alert alert-warning cron-status-banner">
                        <p style="margin:0 0 0.5rem 0;font-size:0.88rem;">${this.escapeHtml(cronStatus.message || st.permission_hint || 'Geen schrijfrechten op /etc/config/crontab.')}</p>
                        ${execMode !== 'direct' ? `<p class="muted" style="margin:0 0 0.5rem 0;font-size:0.82rem;">Tip: voeg eenmalig via <code>visudo</code> of <code>/etc/sudoers</code> rechten toe voor <code>httpdusr</code> als alternatief op <code>chmod 664</code>.</p>` : ''}
                        <div class="copy-group">
                            <input type="text" id="cronPermCmdInput" value="${this.escapeHtml(cronStatus.fix_command || 'chmod 664 /etc/config/crontab && chgrp httpdusr /etc/config/crontab')}" readonly>
                            <button type="button" class="btn btn-sm" id="copyCronPermBtn" style="min-height:44px;">Kopieer SSH-commando</button>
                        </div>
                    </div>
                ` : ''}
                <div class="grid-2">
                    <label>Schema Preset
                        <select id="cronPresetSelect" ${!canWrite ? 'disabled' : ''}>
                            ${presets.map(p => `<option value="${this.escapeHtml(p.value)}" ${p.value === selectedPreset ? 'selected' : ''}>${this.escapeHtml(p.label)}</option>`).join('')}
                        </select>
                    </label>
                    <label>Cron Expressie (5 velden)
                        <input type="text" id="cronExprInput" value="${this.escapeHtml(currentExpr)}" ${!canWrite ? 'disabled' : ''}>
                    </label>
                </div>
                <label>PHP-CLI Pad
                    <input type="text" id="cronPhpPathInput" value="${this.escapeHtml(st.default_php_path || '/usr/bin/php')}" ${!canWrite ? 'disabled' : ''}>
                </label>
                <div class="cron-actions-bar">
                    <button type="button" class="btn btn-primary" id="saveCrontabBtn" style="min-height:44px;" ${!canWrite ? 'disabled' : ''}>Cronjob opslaan / wijzigen</button>
                    <button type="button" class="btn" id="testCrontabBtn" style="min-height:44px;">▶️ Cronjob nu testen</button>
                    <button type="button" class="btn" id="restartCrontabBtn" style="min-height:44px;">🔄 Crontab herstarten</button>
                    ${st.raw_line ? `<button type="button" class="btn btn-danger" id="deleteCrontabBtn" style="min-height:44px;" ${!canWrite ? 'disabled' : ''}>Cronjob wissen</button>` : ''}
                </div>
            `;

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
                const intervalEl = document.getElementById('settingIntervalHours');
                const hourEl = document.getElementById('settingPreferredHour');

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
                if (inputEl && this._cronCmdBase) inputEl.value = `${expr.trim()} ${this._cronCmdBase}`;
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

            if (st.current_schedule) {
                syncFromCronExpression(st.current_schedule);
            }

            document.getElementById('saveCrontabBtn')?.addEventListener('click', async () => {
                const schedule = document.getElementById('cronExprInput')?.value?.trim() || '';
                const phpPath = document.getElementById('cronPhpPathInput')?.value?.trim() || '';
                await this.props.api.post('crontab_save', { schedule, php_path: phpPath });
                this.props.toast.show('Cronjob opgeslagen!');
                this.loadCrontabStatus();
            });

            document.getElementById('deleteCrontabBtn')?.addEventListener('click', async () => {
                await this.props.api.post('crontab_delete', {});
                this.props.toast.show('Cronjob gewist!');
                this.loadCrontabStatus();
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
                    const res = await this.props.api.post('test_cron', {});
                    this.props.toast.show(res?.message || 'Cronjob-test voltooid!');
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
