// Run from the pinned Mancala demo with its local Laravel and Wrangler servers.
const assert = require('node:assert/strict');
const { execFileSync } = require('node:child_process');
const { mkdirSync, readFileSync, renameSync, writeFileSync } = require('node:fs');
const { join } = require('node:path');
const { setTimeout: delay } = require('node:timers/promises');
const { chromium } = require('playwright');

const [mode, state, resultFile, firstArg = '0', countArg = '20'] = process.argv.slice(2);
const first = Number(firstArg);
const count = Number(countArg);
if (!['prepare', 'cold', 'fresh', 'direct'].includes(mode) || !state || !resultFile ||
    !Number.isInteger(first) || first < 0 || !Number.isInteger(count) || count < 1) {
    throw new Error('Usage: node mancala.cjs prepare|cold|fresh|direct STATE_DIR RESULTS.json [FIRST COUNT]');
}
const base = 'http://127.0.0.1:18000';
const worker = 'http://127.0.0.1:18787';
const bearer = execFileSync(process.env.ATOMS_BENCH_CLI || './vendor/bin/atoms', ['token'], {encoding: 'utf8'}).trim();
const roomsFile = join(state, 'rooms.json');
const sessionFile = join(state, 'player-one.json');
const results = [];

async function request(path, args) {
    const response = await fetch(worker + path, {
        method: args === undefined ? 'GET' : 'POST',
        headers: {Authorization: 'Bearer ' + bearer, 'Content-Type': 'application/json'},
        body: args === undefined ? undefined : JSON.stringify({args}),
    });
    assert.equal(response.status, 200, path);
    return response.json();
}

async function info(room) {
    const {info} = await request('/debug/MancalaGame/' + room.id + '/info');
    assert.equal(info.do_id, room.doId);
    return info;
}

async function snapshot(room) {
    const start = performance.now();
    const {result} = await request('/invoke/MancalaGame/' + room.id + '/snapshot', []);
    const elapsed = performance.now() - start;
    assert.equal(result.status, 'active');
    return Number(elapsed.toFixed(3));
}

async function playable(page) {
    await page.locator('.role-note').filter({hasText: 'Player 1'}).waitFor();
    await page.locator('button.pit:not([disabled])').first().waitFor();
}

async function paint(page) {
    return page.evaluate(() => new Promise(resolve =>
        requestAnimationFrame(() => requestAnimationFrame(() => resolve(Math.round(performance.now()))))));
}

async function create(one, two) {
    const p = await one.newPage();
    const q = await two.newPage();
    await p.goto(base);
    const start = performance.now();
    await p.locator('.primary-button').click();
    await p.waitForURL('**/games/*');
    await p.locator('.role-note').filter({hasText: 'Player 1'}).waitFor();
    const url = p.url();
    await q.goto(url);
    await q.locator('.role-note').filter({hasText: 'Player 2'}).waitFor();
    await playable(p);
    await paint(p);
    const createMs = Math.round(performance.now() - start);
    const id = new URL(url).pathname.split('/').pop();
    const before = (await request('/debug/MancalaGame/' + id + '/info')).info;
    assert.ok(before.php_booted && before.guest_memory_bytes > 0);
    return {p, q, room: {url, id, doId: before.do_id}, createMs, before};
}

async function returning(one, room) {
    const page = await one.newPage();
    await page.goto(room.url, {waitUntil: 'commit'});
    await playable(page);
    return {page, browserMs: await paint(page)};
}

function record(row) {
    results.push(row);
    writeFileSync(resultFile + '.tmp', JSON.stringify(results, null, 2) + '\n');
    renameSync(resultFile + '.tmp', resultFile);
}

async function exercise(one, two) {
    if (mode === 'prepare' || mode === 'fresh') {
        const rooms = [];
        for (let i = 0; i < count; i++) {
            const {p, q, room, createMs, before} = await create(one, two);
            await p.close();
            if (mode === 'fresh') {
                const {page, browserMs} = await returning(one, room);
                const after = await info(room);
                assert.equal(after.constructions, before.constructions);
                record({sample: first + i, doId: room.doId, createMs, browserMs,
                    constructionsBefore: before.constructions, constructionsAfter: after.constructions});
                await page.close();
            }
            await q.close();
            rooms.push(room);
            await delay(200);
        }
        if (mode === 'prepare') {
            await one.storageState({path: sessionFile});
            writeFileSync(roomsFile, JSON.stringify(rooms) + '\n');
            writeFileSync(resultFile, JSON.stringify({rooms: rooms.length}) + '\n');
        }
        return;
    }
    const rooms = JSON.parse(readFileSync(roomsFile, 'utf8')).slice(first, first + count);
    assert.equal(rooms.length, count);
    for (const room of rooms) {
        if (mode === 'direct') await snapshot(room);
        else await (await returning(one, room)).page.close();
    }
    const before = [];
    for (const room of rooms) before.push((await info(room)).constructions);
    await delay(30_000);
    for (const [i, room] of rooms.entries()) {
        const navigation = mode === 'direct' ? {coldInvokeMs: await snapshot(room)} : await returning(one, room);
        const after = await info(room);
        assert.ok(after.constructions > before[i], 'room survived the measured idle interval');
        assert.ok(after.php_booted && after.php_parked && after.guest_memory_bytes > 0);
        assert.ok(Number.isFinite(after.php_boot_ms));
        const warmInvokeMs = await snapshot(room);
        const warm = await info(room);
        assert.equal(warm.constructions, after.constructions);
        assert.ok(warm.turns_this_residency > after.turns_this_residency);
        record({sample: first + i, doId: room.doId, browserMs: navigation.browserMs,
            coldInvokeMs: navigation.coldInvokeMs, warmInvokeMs, phpBootMs: after.php_boot_ms,
            guestMemoryBytes: Math.max(after.guest_memory_bytes, warm.guest_memory_bytes),
            constructionsBefore: before[i], constructionsAfter: after.constructions});
        if (navigation.page) await navigation.page.close();
    }
}

async function main() {
    mkdirSync(state, {recursive: true, mode: 0o700});
    if (mode === 'direct') return exercise();
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM, headless: true});
    try {
        const options = {reducedMotion: 'reduce', viewport: {width: 1280, height: 720}};
        const one = await browser.newContext({...options, storageState: mode === 'cold' ? sessionFile : undefined});
        const two = await browser.newContext(options);
        await exercise(one, two);
    } finally {
        await browser.close();
    }
}
main().catch(error => { console.error(error); process.exitCode = 1; });
