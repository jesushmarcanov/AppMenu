/* Cliente HTTP de la API. Mismo origen, cookie de sesión y CSRF. */

export class ApiError extends Error {
    constructor(message, { status = 0, code = 'NETWORK_ERROR', fields = {} } = {}) {
        super(message);
        this.name = 'ApiError';
        this.status = status;
        this.code = code;
        this.fields = fields || {};
    }

    field(name) {
        return this.fields?.[name] || '';
    }
}

export class ApiClient {
    constructor(base = 'api/v1') {
        this.base = base.replace(/\/+$/, '');
        this.csrf = null;
        this.pendingCsrf = null;
    }

    url(path = '') {
        return `${this.base}/${String(path).replace(/^\/+/, '')}`;
    }

    setCsrf(token) {
        this.csrf = typeof token === 'string' && token !== '' ? token : null;
    }

    async ensureCsrf() {
        if (this.csrf) {
            return this.csrf;
        }

        if (!this.pendingCsrf) {
            this.pendingCsrf = this.request('csrf', { skipCsrf: true })
                .then((data) => {
                    this.setCsrf(data?.csrf_token);
                    return this.csrf;
                })
                .finally(() => {
                    this.pendingCsrf = null;
                });
        }

        return this.pendingCsrf;
    }

    /**
     * Devuelve el sobre completo `{ok, data, meta}`. Útil para listados paginados.
     */
    async requestFull(path, options = {}) {
        const {
            method = 'GET',
            body,
            formData,
            headers = {},
            skipCsrf = false,
            signal,
        } = options;

        const verb = method.toUpperCase();
        const requestHeaders = {
            Accept: 'application/json',
            ...headers,
        };

        const init = {
            method: verb,
            credentials: 'same-origin',
            headers: requestHeaders,
            signal,
        };

        if (body !== undefined && formData) {
            throw new Error('Usa body o formData, no ambos.');
        }

        if (verb !== 'GET' && verb !== 'HEAD' && !skipCsrf) {
            const token = await this.ensureCsrf();
            if (token) {
                requestHeaders['X-CSRF-Token'] = token;
            }
        }

        if (formData) {
            init.body = formData;
        } else if (body !== undefined) {
            requestHeaders['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        let response;
        try {
            response = await fetch(this.url(path), init);
        } catch (error) {
            if (error?.name === 'AbortError') {
                throw error;
            }
            throw new ApiError('No pudimos conectar con el servidor. Revisa tu conexión.', {
                code: 'NETWORK_ERROR',
            });
        }

        if (response.status === 204) {
            return null;
        }

        let payload = null;
        try {
            payload = await response.json();
        } catch {
            payload = null;
        }

        if (!response.ok || payload?.ok === false) {
            const error = payload?.error || {};
            const message = error.message || `Ocurrió un error (${response.status}).`;
            throw new ApiError(message, {
                status: response.status,
                code: error.code || 'SERVER_ERROR',
                fields: error.fields || {},
            });
        }

        return payload;
    }

    /** Devuelve solo `data`, como antes. */
    async request(path, options = {}) {
        const payload = await this.requestFull(path, options);

        if (payload && typeof payload === 'object' && 'ok' in payload) {
            return payload.data ?? null;
        }

        return payload;
    }

    get(path, options = {}) {
        return this.request(path, { ...options, method: 'GET' });
    }

    getFull(path, options = {}) {
        return this.requestFull(path, { ...options, method: 'GET' });
    }

    post(path, body, options = {}) {
        return this.request(path, { ...options, method: 'POST', body });
    }

    put(path, body, options = {}) {
        return this.request(path, { ...options, method: 'PUT', body });
    }

    patch(path, body, options = {}) {
        return this.request(path, { ...options, method: 'PATCH', body });
    }

    delete(path, options = {}) {
        return this.request(path, { ...options, method: 'DELETE' });
    }

    upload(path, formData, options = {}) {
        return this.request(path, { ...options, method: 'POST', formData });
    }
}
