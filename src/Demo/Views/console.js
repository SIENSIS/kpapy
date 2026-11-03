/**
 * kpapy demo console. External ES module: the page has no inline code (strict CSP).
 */
const api = document.querySelector('[data-kp-request]').dataset.api;
const csrfHeader = document.querySelector('meta[name="csrf-header"]').content;
let csrfHash = document.querySelector('meta[name="csrf-hash"]').content;
let token = null;
let challenge = null;

const $ = (selector) => document.querySelector(selector);

function showToken(info) {
    $('[data-kp-token-info]').innerHTML = '';
    const text = document.createElement('span');
    text.textContent = token ? `Token of ${info}: ${token.slice(0, 12)}…` : 'No token: requests are anonymous.';
    $('[data-kp-token-info]').append(text);
}

function show(status, body) {
    $('[data-kp-status]').textContent = status;
    $('[data-kp-response]').textContent = typeof body === 'string' ? body : JSON.stringify(body, null, 2);
}

async function call(method, path, body) {
    const headers = { Accept: 'application/json' };
    if (token) headers.Authorization = `Bearer ${token}`;
    if (body !== undefined) headers['Content-Type'] = 'application/json';

    const response = await fetch(`${api}/${path.replace(/^\/+/, '')}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
    const text = await response.text();
    let data = text;
    try { data = text ? JSON.parse(text) : ''; } catch { /* not JSON */ }

    return { response, data };
}

// Quick tokens
document.addEventListener('click', async (event) => {
    const button = event.target.closest('[data-kp-token]');
    if (!button) return;

    const response = await fetch(`${location.pathname.replace(/\/$/, '')}/token/${button.dataset.kpToken}`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', [csrfHeader]: csrfHash, 'X-Requested-With': 'XMLHttpRequest' },
        body: JSON.stringify({ scopes: button.dataset.kpScopes || 'all' }),
    });
    const data = await response.json();
    csrfHash = data.csrf || csrfHash;
    token = data.token;
    showToken(`${data.user} (scopes: ${data.scopes.join(', ')})`);
});

// Examples
document.addEventListener('click', (event) => {
    const example = event.target.closest('[data-kp-example]');
    if (!example) return;

    const form = $('[data-kp-request]');
    form.method.value = example.dataset.method;
    form.path.value = example.dataset.path;
    form.body.value = example.dataset.body;
    form.path.focus();
});

// Requests
$('[data-kp-request]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    let body;

    if (['POST', 'PATCH', 'PUT'].includes(form.method.value) && form.body.value.trim() !== '') {
        try { body = JSON.parse(form.body.value); } catch { show('—', 'The body is not valid JSON.'); return; }
    }

    show('…', '');
    const { response, data } = await call(form.method.value, form.path.value, body);
    show(`${response.status} ${response.statusText}${response.headers.get('ETag') ? '  ETag ' + response.headers.get('ETag') : ''}`, data);
});

// Sign in like an app: step 1
$('[data-kp-login]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const select = event.currentTarget.email;
    const option = select.options[select.selectedIndex];
    token = null;
    showToken('');

    const { response, data } = await call('POST', 'auth/token', { email: select.value, password: option.dataset.password, device_name: 'kpapy demo console' });
    show(`${response.status} ${response.statusText}`, data);

    if (response.status === 202) {
        challenge = data.data.challenge;
        const verify = $('[data-kp-verify]');
        verify.hidden = false;
        $('[data-kp-challenge-message]').textContent = `Method: ${data.data.method}. ${data.data.message}`;
        $('[data-kp-profile-link]').href = `${location.pathname.replace(/\/$/, '')}/profile/${select.value.startsWith('admin') ? 'admin' : 'reader'}`;
        verify.code.value = '';
        verify.code.focus();
    }
});

// Sign in like an app: step 2
$('[data-kp-verify]').addEventListener('submit', async (event) => {
    event.preventDefault();
    const form = event.currentTarget;
    const { response, data } = await call('POST', 'auth/token/verify', { challenge, code: form.code.value });
    show(`${response.status} ${response.statusText}`, data);

    if (response.status === 201) {
        token = data.data.token;
        form.hidden = true;
        showToken(`the signed-in user (expires ${data.data.expires_at})`);
    }
});
