export class SyncProgressPanel {
    /**
     * @param {HTMLElement} container
     * @param {import('../core/ApiClient.js').ApiClient} api
     */
    constructor(container, api) {
        this.container = container;
        this.api = api;
        this.poller = null;
        this.render();
    }

    render() {
        if (!this.container) return;
        this.container.innerHTML = `
            <div class="sync-progress-panel hidden" style="margin:0.75rem 0;padding:0.75rem;border-radius:8px;background:var(--surface-2, rgba(255,255,255,0.04));border:1px solid var(--border, rgba(255,255,255,0.1));">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.4rem;font-size:0.9rem;">
                    <span data-role="msg">Synchronisatie voorbereiden...</span>
                    <span style="display:flex;gap:0.75rem;align-items:center;white-space:nowrap;">
                        <span data-role="eta" class="muted" style="font-size:0.82rem;">ETA: berekenen...</span>
                        <strong data-role="pct">0%</strong>
                    </span>
                </div>
                <div style="width:100%;height:10px;border-radius:999px;background:var(--border, rgba(255,255,255,0.12));overflow:hidden;">
                    <div data-role="fill" style="width:0%;height:100%;background:var(--primary, #3b82f6);transition:width 0.3s ease;"></div>
                </div>
                <pre data-role="console" class="muted" style="margin:0.5rem 0 0 0;font-size:0.78rem;max-height:90px;overflow:auto;white-space:pre-wrap;"></pre>
            </div>
        `;
    }

    formatEta(secs) {
        if (secs === null || secs === undefined) return 'ETA: berekenen...';
        if (secs < 60) return `ETA: ~${secs}s`;
        const mins = Math.floor(secs / 60);
        const rem = secs % 60;
        return `ETA: ~${mins}m ${rem}s`;
    }

    applyProgress(p) {
        const panel = this.container?.querySelector('.sync-progress-panel');
        if (!panel) return;
        const msgEl = panel.querySelector('[data-role="msg"]');
        const pctEl = panel.querySelector('[data-role="pct"]');
        const etaEl = panel.querySelector('[data-role="eta"]');
        const fillEl = panel.querySelector('[data-role="fill"]');
        const consoleEl = panel.querySelector('[data-role="console"]');

        const pct = Number(p?.percent || 0);
        if (msgEl) msgEl.textContent = p?.message || 'Bezig...';
        if (pctEl) pctEl.textContent = `${pct}%`;
        if (etaEl) etaEl.textContent = pct >= 100 ? 'Voltooid' : this.formatEta(p?.eta_seconds);
        if (fillEl) fillEl.style.width = `${pct}%`;
        if (consoleEl) consoleEl.textContent = (p?.live_lines || []).join('\n');
    }

    async checkActive(callbacks = {}) {
        try {
            const st = await this.api.get('sync_status');
            if (st?.progress?.status === 'running') {
                this.startPolling(st.progress, callbacks);
                return true;
            }
        } catch { /* ignore */ }
        return false;
    }

    startPolling(initialProgress = null, { onStateChange = null, onComplete = null, onLogs = null } = {}) {
        this.stopPolling();
        const panel = this.container?.querySelector('.sync-progress-panel');
        panel?.classList.remove('hidden');

        this.applyProgress(initialProgress || { percent: 5, message: 'Synchronisatie voorbereiden...' });
        if (onStateChange) onStateChange(true);

        this.poller = setInterval(async () => {
            try {
                const st = await this.api.get('sync_status');
                const p = st.progress || {};
                this.applyProgress(p);
                if (onLogs && st.logs) onLogs(st.logs);
                if (p.status === 'completed' || p.status === 'error' || p.status === 'idle') {
                    this.stopPolling();
                    if (onStateChange) onStateChange(false);
                    if (p.status === 'completed' && onComplete) {
                        await onComplete(p);
                    }
                }
            } catch { /* ignore transient poll errors */ }
        }, 800);
    }

    stopPolling() {
        if (this.poller) {
            clearInterval(this.poller);
            this.poller = null;
        }
    }
}
