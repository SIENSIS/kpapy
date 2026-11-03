/**
 * kpapy: "Try it" on the API documentation page.
 *
 * External ES module (the page has no inline code, so it works with a strict CSP). Every
 * endpoint has a form; this script turns it into a real request with the token of the panel
 * at the top, and shows the status, the useful headers and the body of the response.
 * The token is kept only in memory: reloading the page forgets it.
 */
const panel = document.querySelector('[data-kp-auth]');
const base = new URL(panel.dataset.server || '/', location.href).pathname.replace(/\/+$/, '');
const tokenInput = panel.querySelector('input[name=token]');
const status = panel.querySelector('[data-kp-auth-status]');
const shownHeaders = ['Content-Type', 'ETag', 'Location', 'Retry-After', 'X-RateLimit-Limit', 'X-RateLimit-Remaining'];
let challenge = null;

const token = () => tokenInput.value.trim();

function setStatus(text, ok = null) {
    status.textContent = text;
    status.classList.toggle('ok', ok === true);
    status.classList.toggle('ko', ok === false);
}

/**
 * Builds the request of an endpoint form: URL (path and query parameters), headers and body.
 */
function build(form) {
    let path = form.dataset.path;
    const query = new URLSearchParams();
    const headers = { Accept: 'application/json' };
    let body;

    for (const field of form.querySelectorAll('[name]')) {
        const value = field.value.trim();
        const [where, ...rest] = field.name.split(':');
        const name = rest.join(':');

        if (value === '' || !name) continue;
        if (where === 'path') path = path.replace(`{${name}}`, encodeURIComponent(value));
        if (where === 'query') query.append(name, value);
        if (where === 'header') headers[name] = value;
    }

    const missing = path.match(/\{([^}]+)\}/);
    if (missing) throw new Error(`Fill in the path parameter "${missing[1]}".`);

    let search = query.toString();
    const extra = form.elements.extra?.value.trim().replace(/^[?&]+/, '');
    if (extra) search += (search ? '&' : '') + extra;

    if (form.elements.body && form.elements.body.value.trim() !== '') {
        try {
            body = JSON.stringify(JSON.parse(form.elements.body.value));
        } catch {
            throw new Error('The body is not valid JSON.');
        }
        headers['Content-Type'] = 'application/json';
    }

    if (token()) headers.Authorization = `Bearer ${token()}`;

    return { method: form.dataset.method, url: `${base}${path}${search ? `?${search}` : ''}`, headers, body };
}

async function send(request) {
    const response = await fetch(request.url, { method: request.method, headers: request.headers, body: request.body, credentials: 'omit' });
    const text = await response.text();
    let data = text;
    try { data = text ? JSON.parse(text) : ''; } catch { /* not JSON */ }

    return { response, data };
}

function show(form, { response, data }) {
    const result = form.querySelector('[data-kp-result]');
    const statusText = result.querySelector('[data-kp-status]');
    result.hidden = false;
    statusText.textContent = `${response.status} ${response.statusText}`;
    statusText.className = `status ${response.ok || response.status === 304 ? 'ok' : 'ko'}`;
    result.querySelector('[data-kp-headers]').textContent = shownHeaders
        .filter((name) => response.headers.has(name))
        .map((name) => `${name}: ${response.headers.get(name)}`)
        .join(' · ');
    result.querySelector('[data-kp-body]').textContent = data === '' ? '(empty body)' : (typeof data === 'string' ? data : JSON.stringify(data, null, 2));
}

function showError(form, message) {
    const result = form.querySelector('[data-kp-result]');
    result.hidden = false;
    result.querySelector('[data-kp-status]').textContent = message;
    result.querySelector('[data-kp-status]').className = 'status ko';
    result.querySelector('[data-kp-headers]').textContent = '';
    result.querySelector('[data-kp-body]').textContent = '';
}

const quote = (value) => `'${String(value).replace(/'/g, `'\\''`)}'`;

function curl(request) {
    const parts = [`curl -X ${request.method} ${quote(new URL(request.url, location.href).href)}`];
    for (const [name, value] of Object.entries(request.headers)) parts.push(`-H ${quote(`${name}: ${value}`)}`);
    if (request.body !== undefined) parts.push(`--data ${quote(request.body)}`);

    return parts.join(' \\\n  ');
}

// Endpoint forms
document.addEventListener('submit', async (event) => {
    const form = event.target.closest('[data-kp-try]');
    if (!form) return;
    event.preventDefault();

    let request;
    try { request = build(form); } catch (error) { showError(form, error.message); return; }

    const button = form.querySelector('button[type=submit]');
    button.disabled = true;
    try {
        show(form, await send(request));
    } catch (error) {
        showError(form, `The request failed: ${error.message}`);
    } finally {
        button.disabled = false;
    }
});

document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-kp-curl]');
    if (!button) return;

    const form = button.closest('[data-kp-try]');
    let command;
    try { command = curl(build(form)); } catch (error) { showError(form, error.message); return; }

    const result = form.querySelector('[data-kp-result]');
    result.hidden = false;
    result.querySelector('[data-kp-body]').textContent = command;
    try {
        await navigator.clipboard.writeText(command);
        result.querySelector('[data-kp-status]').textContent = 'curl command copied';
    } catch {
        result.querySelector('[data-kp-status]').textContent = 'curl command (copy it from here)';
    }
    result.querySelector('[data-kp-status]').className = 'status';
    result.querySelector('[data-kp-headers]').textContent = '';
});

// Token panel
panel.querySelector('[data-kp-show-token]').addEventListener('click', (event) => {
    const visible = tokenInput.type === 'text';
    tokenInput.type = visible ? 'password' : 'text';
    event.currentTarget.textContent = visible ? 'Show' : 'Hide';
});

panel.querySelector('[data-kp-forget]').addEventListener('click', () => {
    tokenInput.value = '';
    setStatus('No token: requests are anonymous.');
});

tokenInput.addEventListener('input', () => setStatus(token() ? 'Requests are sent with this token.' : 'No token: requests are anonymous.'));

// Sign in like an app, in two steps (only when the API has the sign-in endpoints)
const login = panel.querySelector('[data-kp-login]');
const verify = panel.querySelector('[data-kp-verify]');

panel.querySelector('[data-kp-open-login]')?.addEventListener('click', () => {
    login.hidden = !login.hidden;
    verify.hidden = true;
    if (!login.hidden) login.elements.email.focus();
});

login?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const request = {
        method: 'POST',
        url: base + login.dataset.path,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ email: login.elements.email.value, password: login.elements.password.value, device_name: 'API documentation' }),
    };
    const { response, data } = await send(request);
    login.elements.password.value = '';

    if (response.status === 202) {
        challenge = data.data.challenge;
        login.hidden = true;
        verify.hidden = false;
        verify.querySelector('[data-kp-challenge]').textContent = data.data.message || `Enter the code (${data.data.method}).`;
        verify.elements.code.value = '';
        verify.elements.code.focus();
        setStatus('Step 2: enter the code.');
    } else {
        setStatus(`${response.status}: ${data.detail || data.title || response.statusText}`, false);
    }
});

verify?.addEventListener('submit', async (event) => {
    event.preventDefault();
    const request = {
        method: 'POST',
        url: base + verify.dataset.path,
        headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
        body: JSON.stringify({ challenge, code: verify.elements.code.value.replace(/\s+/g, '') }),
    };
    const { response, data } = await send(request);

    if (response.status === 201) {
        tokenInput.value = data.data.token;
        verify.hidden = true;
        challenge = null;
        setStatus(`Signed in. The token expires ${data.data.expires_at ?? 'never'}; it is kept only in this tab.`, true);
    } else {
        setStatus(`${response.status}: ${data.detail || data.title || response.statusText}`, false);
    }
});
