import { BaseComponent } from '../core/BaseComponent.js';

export class AdminToolsPage extends BaseComponent {
    async load() {
        this.render();
    }

    render() {
        this.container.innerHTML = `
            <div class="card">
                <div class="card-header">
                    <h2>⚙️ Admin & Database Tools</h2>
                </div>
                <p class="muted" style="margin-bottom:1rem;">
                    Voer hier handmatig het <code>database/update.sql</code> (Latest-Only Delta) migratiebestand uit op de database.
                </p>
                <div class="btn-group">
                    <button type="button" class="btn btn-primary" id="runUpdateSqlBtn">▶️ Voer database/update.sql uit</button>
                </div>
                <div id="adminUpdateSqlResult" class="hidden" style="margin-top:1rem;"></div>
            </div>
        `;

        this.bindEvents();
    }

    bindEvents() {
        document.getElementById('runUpdateSqlBtn')?.addEventListener('click', async () => {
            const btn = document.getElementById('runUpdateSqlBtn');
            const resultEl = document.getElementById('adminUpdateSqlResult');
            if (!btn || !resultEl) return;

            btn.disabled = true;
            resultEl.className = 'hidden';
            try {
                const res = await this.props.api.post('run_update_sql');
                const msg = res?.message || 'database/update.sql succesvol uitgevoerd.';
                resultEl.textContent = `✓ ${msg}`;
                resultEl.className = 'alert alert-success';
                this.props.toast.show(msg);
            } catch (e) {
                resultEl.textContent = `✗ ${e.message}`;
                resultEl.className = 'alert alert-error';
            } finally {
                btn.disabled = false;
            }
        });
    }
}
