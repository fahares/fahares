// The logic of the fahares-failover Worker (backlog item 136, step 14.406), apart from worker.js so that
// node --test can run it with a mocked fetch. See README.md beside it.
//
// Normal times: the site's DNS records are DNS-only (grey), so visitors reach the host directly and the
// Worker's routes never run. Each minute the cron checks the host; when it gives no answer at all twice in
// a row, the records are switched to proxied (orange): visitors then reach Cloudflare, and the Worker
// passes each request to the host or, when the host still gives no answer, serves the maintenance page
// with 503. When the host answers twice in a row again, the records go back to grey.
//
// "No answer" means no HTTP response: a connection error, a timeout, or one of Cloudflare's own 52x/530
// pages. Any response from the host, even 502-504 from Traefik or 503 from fahares-down, means the host is
// up and its own fahares-down page covers the app, so nothing is switched.
//
// Planned maintenance: a TXT record _maintenance.<zone> (scripts/dev/maintenance on) keeps the records
// proxied whatever the checks say, and may carry a note and an expected return time for the page.

export const NO_ANSWER = new Set([520, 521, 522, 523, 524, 525, 526, 527, 530]);
export const MAINTENANCE_PREFIX = 'fahares-maintenance';
const API = 'https://api.cloudflare.com/client/v4';

export function settings(env) {
    const zone = env.ZONE_NAME || 'fahares.net';
    return {
        zoneId: env.ZONE_ID,
        token: env.CF_API_TOKEN,
        hosts: (env.HOSTS || `${zone},www.${zone}`).split(',').map((h) => h.trim()).filter(Boolean),
        maintenanceName: `_maintenance.${zone}`,
        healthUrl: env.HEALTH_URL || `https://${zone}/up`,
        checkTimeout: Number(env.CHECK_TIMEOUT_MS || 10000),
        originTimeout: Number(env.ORIGIN_TIMEOUT_MS || 15000),
        retryDelay: Number(env.RETRY_DELAY_MS || 20000),
        greyTtl: Number(env.DNS_TTL || 60),
        sleep: env.__sleep || ((ms) => new Promise((r) => setTimeout(r, ms))),
    };
}

// Resolves to the promise's value, or rejects after ms; the slow request is not aborted (its body may stream).
export function withTimeout(promise, ms) {
    let timer;
    return Promise.race([
        promise,
        new Promise((_, reject) => { timer = setTimeout(() => reject(new Error(`timeout ${ms} ms`)), ms); }),
    ]).finally(() => clearTimeout(timer));
}

export function answered(response) {
    return !!response && !NO_ANSWER.has(response.status);
}

// ---- Cloudflare API -------------------------------------------------------------------------------------

async function api(s, path, init = {}) {
    const response = await fetch(`${API}/zones/${s.zoneId}${path}`, {
        ...init,
        headers: { Authorization: `Bearer ${s.token}`, 'Content-Type': 'application/json' },
    });
    const body = await response.json();
    if (!body.success) {
        throw new Error(`Cloudflare API ${path}: ${JSON.stringify(body.errors)}`);
    }
    return body.result;
}

export async function siteRecords(s) {
    const all = await api(s, '/dns_records?per_page=100');
    return all.filter((r) => s.hosts.includes(r.name) && ['A', 'AAAA', 'CNAME'].includes(r.type));
}

export async function maintenanceRecord(s) {
    const all = await api(s, `/dns_records?type=TXT&name=${encodeURIComponent(s.maintenanceName)}`);
    return all.find((r) => r.name === s.maintenanceName && unquote(r.content).startsWith(MAINTENANCE_PREFIX)) || null;
}

export async function setProxied(s, records, proxied) {
    for (const r of records) {
        if (r.proxied === proxied) continue;
        await api(s, `/dns_records/${r.id}`, {
            method: 'PATCH',
            body: JSON.stringify({ proxied, ttl: proxied ? 1 : s.greyTtl }),
        });
    }
}

// ---- The maintenance note -------------------------------------------------------------------------------

function unquote(text) {
    return String(text || '').replace(/^"|"$/g, '');
}

// "fahares-maintenance until=<unix seconds> msg=<base64url of UTF-8>", both parts optional
export function parseNote(content) {
    const text = unquote(content);
    if (!text.startsWith(MAINTENANCE_PREFIX)) return null;
    const until = /until=(\d+)/.exec(text);
    const msg = /msg=([A-Za-z0-9_-]+)/.exec(text);
    let message = '';
    if (msg) {
        try {
            const b64 = msg[1].replace(/-/g, '+').replace(/_/g, '/');
            const bytes = Uint8Array.from(atob(b64 + '='.repeat((4 - (b64.length % 4)) % 4)), (c) => c.charCodeAt(0));
            message = new TextDecoder().decode(bytes);
        } catch {
            message = '';
        }
    }
    return { until: until ? Number(until[1]) : null, message };
}

function escapeHtml(text) {
    return text.replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// The time in Tehran, with Persian digits
function tehranTime(unix) {
    return new Intl.DateTimeFormat('fa-IR', { timeZone: 'Asia/Tehran', hour: '2-digit', minute: '2-digit' })
        .format(new Date(unix * 1000));
}

export function renderPage(page, note) {
    let html = page
        .replace('<title>فهارس | موقتاً در دسترس نیست</title>', '<title>فهارس | در حال نگهداری</title>')
        .replace('سایت چند لحظه در دسترس نیست', 'سرور فهارس در حال نگهداری است')
        .replace(/<p>سرور فهارس زیر فشار است[^<]*<\/p>/, '<p>سرور فهارس در حال ارتقا یا نگهداری است و به‌زودی برمی‌گردد.</p>');
    const parts = [];
    if (note && note.message) parts.push(`<p>${escapeHtml(note.message)}</p>`);
    if (note && note.until && note.until * 1000 > Date.now()) {
        parts.push(`<p>زمان تقریبی بازگشت: ساعت ${tehranTime(note.until)} به وقت تهران.</p>`);
    }
    if (parts.length) html = html.replace('<!--maintenance-note-->', parts.join('\n    '));
    return html;
}

// ---- Handlers -------------------------------------------------------------------------------------------

async function cachedNote(s) {
    const cache = globalThis.caches && caches.default;
    const key = new Request(`https://${s.hosts[0]}/__fahares-maintenance-note`);
    if (cache) {
        const hit = await cache.match(key);
        if (hit) return parseNote(await hit.text());
    }
    let content = '';
    try {
        const record = await maintenanceRecord(s);
        content = record ? unquote(record.content) : '';
    } catch {
        content = '';
    }
    if (cache) {
        await cache.put(key, new Response(content, { headers: { 'Cache-Control': 'max-age=60' } }));
    }
    return parseNote(content);
}

export async function handleFetch(request, env, page) {
    const s = settings(env);
    let response = null;
    try {
        response = await withTimeout(fetch(request), s.originTimeout);
    } catch {
        response = null;
    }
    if (answered(response)) return response;

    const html = renderPage(page, await cachedNote(s));
    return new Response(html, {
        status: 503,
        headers: {
            'Content-Type': 'text/html; charset=utf-8',
            'Retry-After': '120',
            'Cache-Control': 'no-store',
            'X-Robots-Tag': 'noindex',
        },
    });
}

export async function hostAnswers(s) {
    try {
        const url = `${s.healthUrl}${s.healthUrl.includes('?') ? '&' : '?'}failover=${Date.now()}`;
        const response = await withTimeout(fetch(url, { redirect: 'manual' }), s.checkTimeout);
        return answered(response);
    } catch {
        return false;
    }
}

// One cron run. Returns what it did, for the log and the tests.
export async function runCheck(env) {
    const s = settings(env);
    if (!s.zoneId || !s.token) throw new Error('ZONE_ID or CF_API_TOKEN is not set');
    const records = await siteRecords(s);
    if (!records.length) throw new Error(`no DNS records for ${s.hosts.join(', ')}`);
    const proxied = records.some((r) => r.proxied);

    if (await maintenanceRecord(s)) {
        await setProxied(s, records, true);
        return 'maintenance';
    }

    if (await hostAnswers(s)) {
        if (!proxied) return 'up';
        await s.sleep(s.retryDelay);
        if (!(await hostAnswers(s))) return 'still-down';
        await setProxied(s, records, false);
        return 'restored';
    }

    if (proxied) {
        // a half-done earlier switch leaves some records grey
        await setProxied(s, records, true);
        return 'still-down';
    }
    await s.sleep(s.retryDelay);
    if (await hostAnswers(s)) return 'blip';
    await setProxied(s, records, true);
    return 'switched';
}
