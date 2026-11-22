export class ApiClient {
    constructor(toast = null) {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.toast = toast;
    }

    buildUrl(endpoint, params = {}) {
        const url = new URL('index.php', window.location.href);
        url.searchParams.set('route', 'api');
        url.searchParams.set('endpoint', String(endpoint));
        for (const [k, v] of Object.entries(params)) {
            if (v !== undefined && v !== null) {
                url.searchParams.set(k, String(v));
            }
        }
        return url.toString();
    }

    async parseResponse(res) {
        const rawText = await res.text();
        let json;
        try {
            json = rawText ? JSON.parse(rawText) : {};
        } catch {
            throw new Error('Ongeldig serverantwoord (geen JSON): ' + rawText.slice(0, 180));
        }
        if (!json.ok) {
            const msg = json.error || `API fout (HTTP ${res.status})`;
            if (this.toast) this.toast.show(msg);
            throw new Error(msg);
        }
        return json.data;
    }

    async get(endpoint, params = {}) {
        const res = await fetch(this.buildUrl(endpoint, params));
        return this.parseResponse(res);
    }

    async post(endpoint, body = {}) {
        const res = await fetch(this.buildUrl(endpoint), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': this.csrfToken,
            },
            body: JSON.stringify(body),
        });
        return this.parseResponse(res);
    }
}
