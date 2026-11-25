import { BaseComponent } from '../core/BaseComponent.js';

const LOG_LINE_REGEX = /^\[([^\]]+)\]\s+\[([^\]]+)\]\s+\[([^\]]+)\]\s+([^{]+?)(?:\s+(\{.*\}))?$/;

export class SyncLogViewer extends BaseComponent {
    constructor(container, props = {}) {
        super(container, props);
        this.state = {
            lines: Array.isArray(props.lines) ? props.lines : [],
            emptyText: props.emptyText || 'Nog geen logregels aanwezig.',
        };
    }

    setLines(lines) {
        this.setState({ lines: Array.isArray(lines) ? lines : [] });
    }

    getStatusBadgeClass(level) {
        const upper = String(level || '').toUpperCase();
        if (upper === 'SUCCESS') return 'sync-log-badge--success';
        if (upper === 'ERROR' || upper === 'FATAL') return 'sync-log-badge--error';
        if (upper === 'WARNING' || upper === 'SKIPPED') return 'sync-log-badge--warning';
        return 'sync-log-badge--info';
    }

    renderMetaPills(jsonStr) {
        if (!jsonStr) return '';
        try {
            const parsed = JSON.parse(jsonStr);
            if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) {
                return '';
            }
            const entries = Object.entries(parsed).filter(([, v]) => v !== null && v !== undefined && v !== '');
            if (entries.length === 0) return '';
            return `
                <div class="sync-log-meta">
                    ${entries.map(([key, val]) => {
                        const displayVal = typeof val === 'object' ? JSON.stringify(val) : String(val);
                        return `<span class="sync-log-pill"><span class="sync-log-pill__key">${this.escapeHtml(key)}:</span> ${this.escapeHtml(displayVal)}</span>`;
                    }).join('')}
                </div>
            `;
        } catch {
            return '';
        }
    }

    renderLine(rawLine) {
        const line = String(rawLine || '').trim();
        if (!line) return '';

        const match = line.match(LOG_LINE_REGEX);
        if (!match) {
            return `<div class="sync-log-entry sync-log-entry--fallback"><span class="sync-log-fallback">${this.escapeHtml(line)}</span></div>`;
        }

        const [, timestamp, category, level, message, jsonStr] = match;
        const statusClass = this.getStatusBadgeClass(level);
        const metaHtml = this.renderMetaPills(jsonStr);

        return `
            <div class="sync-log-entry">
                <div class="sync-log-header">
                    <span class="sync-log-time">${this.escapeHtml(timestamp)}</span>
                    <span class="sync-log-badge sync-log-badge--category">[${this.escapeHtml(category)}]</span>
                    <span class="sync-log-badge ${statusClass}">[${this.escapeHtml(level)}]</span>
                </div>
                <div class="sync-log-message">${this.escapeHtml(message.trim())}</div>
                ${metaHtml}
            </div>
        `;
    }

    render() {
        const lines = this.state.lines || [];
        if (lines.length === 0) {
            this.container.innerHTML = `<div class="sync-log-viewer"><div class="sync-log-empty">${this.escapeHtml(this.state.emptyText)}</div></div>`;
            return;
        }

        const itemsHtml = lines.map(line => this.renderLine(line)).filter(Boolean).join('');
        this.container.innerHTML = `<div class="sync-log-viewer">${itemsHtml}</div>`;
    }
}
