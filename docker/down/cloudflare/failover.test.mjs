// node --test docker/down/cloudflare/failover.test.mjs  (no packages needed)
import { test, beforeEach } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { handleFetch, runCheck, parseNote, renderPage } from './failover.js';

const page = readFileSync(new URL('../index.html', import.meta.url), 'utf8');

// A fake Cloudflare: DNS records and the host's state
let records, txt, host, calls;
const env = { ZONE_ID: 'z1', CF_API_TOKEN: 't', __sleep: async () => {}, CHECK_TIMEOUT_MS: '50', ORIGIN_TIMEOUT_MS: '50' };

function json(result) {
    return new Response(JSON.stringify({ success: true, errors: [], result }), { headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => {
    records = [
        { id: 'a', name: 'fahares.net', type: 'A', proxied: false },
        { id: 'b', name: 'www.fahares.net', type: 'A', proxied: false },
        { id: 'c', name: 'mail.fahares.net', type: 'A', proxied: false },
    ];
    txt = null;
    host = ['up'];
    calls = [];
    globalThis.fetch = async (input, init = {}) => {
        const url = typeof input === 'string' ? input : input.url;
        calls.push(`${init.method || 'GET'} ${url}`);
        if (url.startsWith('https://api.cloudflare.com/')) {
            if (init.method === 'PATCH') {
                const id = url.split('/').pop();
                Object.assign(records.find((r) => r.id === id), JSON.parse(init.body));
                return json({});
            }
            if (url.includes('type=TXT')) return json(txt ? [txt] : []);
            return json(records);
        }
        // the host: the next state in the list, the last one repeating
        const state = host.length > 1 ? host.shift() : host[0];
        if (state === 'up') return new Response('ok', { status: 200 });
        if (state === 'fahares-down') return new Response('down page', { status: 503 });
        if (state === '522') return new Response('cf', { status: 522 });
        if (state === 'hang') return new Promise(() => {});
        throw new TypeError('connection refused');
    };
});

const site = () => records.filter((r) => r.name !== 'mail.fahares.net').map((r) => r.proxied);

test('host up: nothing changes', async () => {
    assert.equal(await runCheck(env), 'up');
    assert.deepEqual(site(), [false, false]);
});

test('an app outage behind fahares-down is not a host outage', async () => {
    host = ['fahares-down'];
    assert.equal(await runCheck(env), 'up');
    assert.deepEqual(site(), [false, false]);
});

test('one missed check is a blip', async () => {
    host = ['refused', 'up'];
    assert.equal(await runCheck(env), 'blip');
    assert.deepEqual(site(), [false, false]);
});

test('two missed checks switch the site records to proxied, not the others', async () => {
    host = ['522', 'hang'];
    assert.equal(await runCheck(env), 'switched');
    assert.deepEqual(site(), [true, true]);
    assert.equal(records[2].proxied, false);
    assert.equal(records[0].ttl, 1);
});

test('while down nothing changes; when back twice, grey with the short TTL', async () => {
    records[0].proxied = records[1].proxied = true;
    host = ['refused'];
    assert.equal(await runCheck(env), 'still-down');
    host = ['up', 'refused'];
    assert.equal(await runCheck(env), 'still-down');
    assert.deepEqual(site(), [true, true]);
    host = ['up'];
    assert.equal(await runCheck(env), 'restored');
    assert.deepEqual(site(), [false, false]);
    assert.equal(records[0].ttl, 60);
});

test('planned maintenance keeps the records proxied even with the host up', async () => {
    txt = { name: '_maintenance.fahares.net', type: 'TXT', content: '"fahares-maintenance until=0"' };
    assert.equal(await runCheck(env), 'maintenance');
    assert.deepEqual(site(), [true, true]);
    assert.ok(!calls.some((c) => c.includes('/up')), 'the host is not checked in maintenance');
});

test('a visitor request goes to the host when it answers', async () => {
    const response = await handleFetch(new Request('https://fahares.net/'), env, page);
    assert.equal(response.status, 200);
    host = ['fahares-down'];
    assert.equal((await handleFetch(new Request('https://fahares.net/'), env, page)).status, 503);
    assert.equal(await (await handleFetch(new Request('https://fahares.net/'), env, page)).text(), 'down page');
});

for (const state of ['refused', '522', 'hang']) {
    test(`a visitor gets the maintenance page when the host gives no answer (${state})`, async () => {
        host = [state];
        const msg = Buffer.from('ارتقای حافظۀ سرور').toString('base64url');
        txt = { name: '_maintenance.fahares.net', type: 'TXT', content: `"fahares-maintenance until=${Math.floor(Date.now() / 1000) + 1800} msg=${msg}"` };
        const response = await handleFetch(new Request('https://fahares.net/x'), env, page);
        assert.equal(response.status, 503);
        assert.equal(response.headers.get('Retry-After'), '120');
        const html = await response.text();
        assert.match(html, /سرور فهارس در حال نگهداری است/);
        assert.match(html, /<title>فهارس \| در حال نگهداری<\/title>/);
        assert.match(html, /ارتقای حافظۀ سرور/);
        assert.match(html, /زمان تقریبی بازگشت/);
    });
}

test('the note is decoded, escaped, and a past time is left out', () => {
    const note = parseNote(`"fahares-maintenance until=100 msg=${Buffer.from('<b>x</b>').toString('base64url')}"`);
    assert.deepEqual(note, { until: 100, message: '<b>x</b>' });
    const html = renderPage(page, note);
    assert.match(html, /&lt;b&gt;x&lt;\/b&gt;/);
    assert.doesNotMatch(html, /زمان تقریبی/);
    assert.equal(parseNote('"v=spf1 -all"'), null);
});
