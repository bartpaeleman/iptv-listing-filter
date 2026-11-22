(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let selectedGroupId = null;
    let currentEpgChannelRowId = null;

    const toastEl = document.getElementById('toast');
    function showToast(msg) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.remove('hidden');
        setTimeout(() => toastEl.classList.add('hidden'), 3000);
    }

    function debounce(fn, delay = 300) {
        let timer;
        return (...args) => {
            clearTimeout(timer);
            timer = setTimeout(() => fn(...args), delay);
        };
    }

    async function apiGet(action, params = {}) {
        const qs = new URLSearchParams({ action, ...params }).toString();
        const res = await fetch(`api.php?${qs}`);
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API fout');
        return json.data;
    }

    async function apiPost(action, body = {}) {
        const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrfToken,
            },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (!json.ok) throw new Error(json.error || 'API fout');
        return json.data;
    }

    // Tabs
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.classList.remove('active'));
            btn.classList.add('active');
            const tab = btn.dataset.tab;
            document.getElementById(`tab-${tab}`)?.classList.add('active');
            if (tab === 'dashboard') loadDashboard();
            if (tab === 'channels') { loadGroups(); loadChannels(); }
            if (tab === 'epg') loadEpgMapping();
            if (tab === 'settings') loadSettings();
        });
    });

    // Theme toggle
    document.getElementById('themeToggle')?.addEventListener('click', () => {
        const html = document.documentElement;
        html.dataset.theme = html.dataset.theme === 'light' ? 'dark' : 'light';
    });

    // Logout
    document.getElementById('logoutBtn')?.addEventListener('click', async () => {
        await apiPost('logout');
        window.location.reload();
    });

    // Copy buttons
    document.querySelectorAll('.copy-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const input = document.getElementById(btn.dataset.copy);
            if (!input) return;
            navigator.clipboard.writeText(input.value);
            showToast('Gekopieerd naar klembord!');
        });
    });

    // Dashboard
    async function loadDashboard() {
        try {
            const d = await apiGet('dashboard');
            document.getElementById('statActiveChannels').textContent = d.active_channels;
            document.getElementById('statTotalChannels').textContent = `van ${d.total_channels} totaal`;
            document.getElementById('statActiveGroups').textContent = d.active_groups;
            document.getElementById('statTotalGroups').textContent = `van ${d.total_groups} totaal`;
            document.getElementById('statLinkedEpg').textContent = d.linked_epg;
            document.getElementById('statEpgChannels').textContent = `${d.epg_channels_count} EPG zenders in cache`;
            document.getElementById('statLastSync').textContent = d.last_m3u_sync || 'Nog nooit';
            document.getElementById('statScheduleInfo').textContent = `Elke ${d.sync_interval_hours}u om ${String(d.sync_preferred_hour).padStart(2, '0')}:00`;

            const base = d.app_url || window.location.origin + window.location.pathname.replace(/\/index\.php$/, '');
            const tok = d.output_token ? `?token=${encodeURIComponent(d.output_token)}` : '';
            document.getElementById('playlistUrlInput').value = `${base}/playlist.php${tok}`;
            document.getElementById('epgUrlInput').value = `${base}/epg.php${tok}`;

            const tbody = document.getElementById('logsTableBody');
            tbody.innerHTML = (d.logs || []).map(l => `
                <tr>
                    <td>${l.created_at}</td>
                    <td>${l.sync_type.toUpperCase()}</td>
                    <td><span class="badge ${l.status === 'success' ? 'badge-success' : 'badge-warning'}">${l.status}</span></td>
                    <td>${escapeHtml(l.message)}</td>
                </tr>
            `).join('');
        } catch (e) {
            showToast(e.message);
        }
    }

    document.querySelectorAll('[data-sync]').forEach(btn => {
        btn.addEventListener('click', async () => {
            const banner = document.getElementById('syncStatusBanner');
            banner.textContent = '⏳ Synchronisatie bezig... Dit kan enkele minuten duren.';
            banner.classList.remove('hidden');
            try {
                await apiPost('sync', { mode: btn.dataset.sync });
                banner.textContent = '✓ Synchronisatie voltooid!';
                loadDashboard();
            } catch (e) {
                banner.textContent = `✗ Fout: ${e.message}`;
            }
        });
    });

    // Groups & Channels
    let allGroups = [];
    async function loadGroups() {
        allGroups = await apiGet('groups');
        renderGroups();
    }

    function renderGroups() {
        const q = (document.getElementById('groupSearchInput')?.value || '').toLowerCase();
        const list = document.getElementById('groupsList');
        const filtered = allGroups.filter(g => (g.custom_name || g.original_name).toLowerCase().includes(q));
        list.innerHTML = filtered.map(g => `
            <div class="group-item ${selectedGroupId === g.id ? 'active' : ''}" data-gid="${g.id}">
                <input type="checkbox" class="group-toggle" ${g.is_enabled ? 'checked' : ''}>
                <span class="group-item-name" title="${escapeHtml(g.original_name)}">${escapeHtml(g.custom_name || g.original_name)}</span>
                <span class="muted" style="font-size:0.8rem;">${g.active_channel_count}/${g.channel_count}</span>
            </div>
        `).join('');

        list.querySelectorAll('.group-item').forEach(el => {
            el.addEventListener('click', ev => {
                if (ev.target.classList.contains('group-toggle')) return;
                selectedGroupId = Number(el.dataset.gid);
                document.getElementById('selectedGroupTitle').textContent = el.querySelector('.group-item-name').textContent;
                renderGroups();
                loadChannels();
            });
            el.querySelector('.group-toggle').addEventListener('change', async ev => {
                await apiPost('update_group', { id: Number(el.dataset.gid), is_enabled: ev.target.checked ? 1 : 0 });
                showToast('Groep bijgewerkt');
                loadGroups();
            });
        });
    }

    document.getElementById('groupSearchInput')?.addEventListener('input', debounce(renderGroups, 200));
    document.getElementById('enableAllGroupsBtn')?.addEventListener('click', async () => {
        await apiPost('bulk_groups', { is_enabled: 1 });
        loadGroups();
    });
    document.getElementById('disableAllGroupsBtn')?.addEventListener('click', async () => {
        await apiPost('bulk_groups', { is_enabled: 0 });
        loadGroups();
    });

    async function loadChannels() {
        const search = document.getElementById('channelSearchInput')?.value || '';
        const params = { search, limit: 150 };
        if (selectedGroupId) params.group_id = selectedGroupId;
        const data = await apiGet('channels', params);
        const tbody = document.getElementById('channelsTableBody');
        tbody.innerHTML = data.items.map(c => `
            <tr data-cid="${c.id}">
                <td><input type="checkbox" class="ch-select"></td>
                <td><input type="checkbox" class="ch-enable" ${c.is_enabled ? 'checked' : ''}></td>
                <td><input type="number" class="ch-num" value="${c.channel_number ?? ''}" style="width:70px;min-height:36px;"></td>
                <td><input type="text" class="ch-name" value="${escapeHtml(c.custom_name || c.tvg_name)}" style="min-height:36px;"></td>
                <td class="muted">${escapeHtml(c.group_name)}</td>
            </tr>
        `).join('');

        tbody.querySelectorAll('tr').forEach(tr => {
            const id = Number(tr.dataset.cid);
            tr.querySelector('.ch-enable').addEventListener('change', async ev => {
                await apiPost('update_channel', { id, is_enabled: ev.target.checked ? 1 : 0 });
            });
            tr.querySelector('.ch-num').addEventListener('change', async ev => {
                await apiPost('update_channel', { id, channel_number: ev.target.value });
                showToast('Kanaalnummer opgeslagen');
            });
            tr.querySelector('.ch-name').addEventListener('change', async ev => {
                await apiPost('update_channel', { id, custom_name: ev.target.value });
                showToast('Kanaalnaam opgeslagen');
            });
        });
    }

    document.getElementById('channelSearchInput')?.addEventListener('input', debounce(loadChannels, 300));
    document.getElementById('selectAllChannels')?.addEventListener('change', ev => {
        document.querySelectorAll('.ch-select').forEach(cb => { cb.checked = ev.target.checked; });
    });

    async function bulkChannels(enable) {
        const ids = Array.from(document.querySelectorAll('#channelsTableBody tr'))
            .filter(tr => tr.querySelector('.ch-select')?.checked)
            .map(tr => Number(tr.dataset.cid));
        if (ids.length === 0) return showToast('Selecteer eerst kanalen.');
        await apiPost('bulk_channels', { ids, is_enabled: enable ? 1 : 0 });
        loadChannels();
    }
    document.getElementById('bulkEnableChannelsBtn')?.addEventListener('click', () => bulkChannels(true));
    document.getElementById('bulkDisableChannelsBtn')?.addEventListener('click', () => bulkChannels(false));

    // EPG Mapping
    async function loadEpgMapping() {
        const search = document.getElementById('epgChannelSearch')?.value || '';
        const epg_status = document.getElementById('epgStatusFilter')?.value || '';
        const data = await apiGet('channels', { active_only: 1, search, epg_status, limit: 150 });
        const tbody = document.getElementById('epgMappingBody');
        tbody.innerHTML = data.items.map(c => {
            const badge = c.epg_channel_id
                ? (c.epg_match_type === 'manual' ? '<span class="badge badge-success">Gekoppeld</span>' : '<span class="badge badge-warning">Auto-matched</span>')
                : '<span class="badge badge-muted">Geen EPG</span>';
            return `
                <tr>
                    <td>${escapeHtml(c.custom_name || c.tvg_name)}</td>
                    <td>${badge}</td>
                    <td>${escapeHtml(c.epg_display_name || c.epg_channel_id || '-')}</td>
                    <td><button class="btn btn-sm btn-secondary pick-epg-btn" data-id="${c.id}" data-name="${escapeHtml(c.custom_name || c.tvg_name)}">Kies EPG</button></td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('.pick-epg-btn').forEach(btn => {
            btn.addEventListener('click', () => openEpgModal(Number(btn.dataset.id), btn.dataset.name));
        });
    }

    document.getElementById('epgChannelSearch')?.addEventListener('input', debounce(loadEpgMapping, 300));
    document.getElementById('epgStatusFilter')?.addEventListener('change', loadEpgMapping);
    document.getElementById('autoMatchEpgBtn')?.addEventListener('click', async () => {
        const res = await apiPost('auto_match_epg');
        showToast(`${res.matched} kanalen automatisch gekoppeld!`);
        loadEpgMapping();
    });

    function openEpgModal(channelId, channelName) {
        currentEpgChannelRowId = channelId;
        document.getElementById('epgModalChannelName').textContent = channelName;
        document.getElementById('epgModalSearch').value = '';
        document.getElementById('epgModal').classList.remove('hidden');
        searchEpgModal('');
    }

    document.getElementById('closeEpgModal')?.addEventListener('click', () => {
        document.getElementById('epgModal').classList.add('hidden');
    });

    async function searchEpgModal(q) {
        const items = await apiGet('epg_search', { q });
        const box = document.getElementById('epgModalResults');
        box.innerHTML = `<div class="epg-option" data-epgid=""><em>Geen EPG (ontkoppelen)</em></div>` + items.map(i => `
            <div class="epg-option" data-epgid="${escapeHtml(i.epg_id)}">
                ${i.icon_url ? `<img src="${escapeHtml(i.icon_url)}" alt="">` : ''}
                <div>
                    <div><strong>${escapeHtml(i.display_name)}</strong></div>
                    <div class="muted" style="font-size:0.8rem;">${escapeHtml(i.epg_id)}</div>
                </div>
            </div>
        `).join('');

        box.querySelectorAll('.epg-option').forEach(opt => {
            opt.addEventListener('click', async () => {
                await apiPost('update_channel', { id: currentEpgChannelRowId, epg_channel_id: opt.dataset.epgid });
                document.getElementById('epgModal').classList.add('hidden');
                showToast('EPG koppeling opgeslagen');
                loadEpgMapping();
            });
        });
    }

    document.getElementById('epgModalSearch')?.addEventListener('input', debounce(e => searchEpgModal(e.target.value), 250));

    // Settings
    async function loadSettings() {
        const s = await apiGet('get_settings');
        document.getElementById('settingM3uUrl').placeholder = s.m3u_source_url_masked || 'https://...';
        document.getElementById('settingEpgUrl').placeholder = s.epg_source_url_masked || 'http://epgguide.ddns.net/epg/tvguide.xml';
        document.getElementById('settingIntervalHours').value = s.sync_interval_hours;
        document.getElementById('settingPreferredHour').value = s.sync_preferred_hour;
        document.getElementById('settingEnforceLan').checked = s.enforce_lan_ip;
        document.getElementById('settingOutputToken').value = s.output_token;
        document.getElementById('qnapCronInput').value = `0 * * * * /mnt/ext/opt/apache/bin/php ${s.app_root}/bin/sync.php >/dev/null 2>&1`;
    }

    document.getElementById('settingsForm')?.addEventListener('submit', async ev => {
        ev.preventDefault();
        await apiPost('save_settings', {
            m3u_source_url: document.getElementById('settingM3uUrl').value,
            epg_source_url: document.getElementById('settingEpgUrl').value,
            sync_interval_hours: Number(document.getElementById('settingIntervalHours').value),
            sync_preferred_hour: Number(document.getElementById('settingPreferredHour').value),
            enforce_lan_ip: document.getElementById('settingEnforceLan').checked,
        });
        showToast('Instellingen opgeslagen!');
        loadSettings();
    });

    document.getElementById('regenTokenBtn')?.addEventListener('click', async () => {
        const res = await apiPost('regenerate_token');
        document.getElementById('settingOutputToken').value = res.output_token;
        showToast('Nieuw token gegenereerd!');
    });

    function escapeHtml(str) {
        return String(str ?? '').replace(/[&<>"']/g, m => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[m]));
    }

    loadDashboard();
})();
