export class ApiClient {
    constructor(toast = null) {
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
        this.toast = toast;
    }

    buildUrl(endpoint, params = {}) {
        const path = window.location.pathname.endsWith('/')
            ? window.location.pathname + 'index.php'
            : window.location.pathname;
        const url = new URL(window.location.origin + path);
        url.searchParams.set('route', 'api');

        const rawEndpoint = String(endpoint);
        const [cleanEndpoint, ...extraParts] = rawEndpoint.split(/[?&]/);
        url.searchParams.set('endpoint', cleanEndpoint);
        for (const part of extraParts) {
            if (!part) continue;
            const eqIdx = part.indexOf('=');
            if (eqIdx > 0) {
                url.searchParams.set(part.slice(0, eqIdx), decodeURIComponent(part.slice(eqIdx + 1)));
            }
        }

        for (const [k, v] of Object.entries(params)) {
            if (v !== undefined && v !== null) {
                url.searchParams.set(k, String(v));
            }
        }
        return url.toString();
    }

    async parseResponse(res, endpoint, requestUrl) {
        const rawText = await res.text();
        let json;
        try {
            json = rawText ? JSON.parse(rawText) : {};
        } catch {
            throw new Error(`Ongeldig serverantwoord (${endpoint} -> ${requestUrl}): ${rawText.slice(0, 180)}`);
        }
        if (!json.ok) {
            const msg = json.error || `API fout (HTTP ${res.status})`;
            if (this.toast) this.toast.show(msg);
            throw new Error(msg);
        }
        return json.data;
    }

    async get(endpoint, params = {}) {
        const requestUrl = this.buildUrl(endpoint, params);
        try {
            const res = await fetch(requestUrl);
            return await this.parseResponse(res, endpoint, requestUrl);
        } catch (err) {
            throw new Error(`${endpoint} (${requestUrl}): ${err.message}`);
        }
    }

    async post(endpoint, body = {}) {
        const requestUrl = this.buildUrl(endpoint);
        try {
            const res = await fetch(requestUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrfToken,
                },
                body: JSON.stringify(body),
            });
            return await this.parseResponse(res, endpoint, requestUrl);
        } catch (err) {
            throw new Error(`${endpoint} (${requestUrl}): ${err.message}`);
        }
    }
}
