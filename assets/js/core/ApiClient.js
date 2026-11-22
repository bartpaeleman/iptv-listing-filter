export class ApiClient {
    constructor(toast = null) {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.toast = toast;
    }

    async get(endpoint, params = {}) {
        const qs = new URLSearchParams({ route: 'api', endpoint, ...params }).toString();
        const res = await fetch(`index.php?${qs}`);
        const json = await res.json();
        if (!json.ok) {
            const msg = json.error || 'API fout';
            if (this.toast) this.toast.show(msg);
            throw new Error(msg);
        }
        return json.data;
    }

    async post(endpoint, body = {}) {
        const res = await fetch(`index.php?route=api&endpoint=${encodeURIComponent(endpoint)}`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
            body: JSON.stringify(body),
        });
        const json = await res.json();
        if (!json.ok) {
            const msg = json.error || 'API fout';
            if (this.toast) this.toast.show(msg);
            throw new Error(msg);
        }
        return json.data;
    }
}
