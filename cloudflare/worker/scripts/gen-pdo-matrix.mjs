#!/usr/bin/env node

/**
 * Generates `cloudflare/docs/pdo-compatibility.md` from a conformance run's
 * PDO differential report (design §5).
 *
 * `renderMatrixDoc(report, pins)` is a PURE function — no filesystem, no
 * clock, no randomness — so `test/conformance.mjs`'s check 30 can import it
 * and byte-compare a fresh render against the committed doc without spawning
 * a second process. The CLI below (`node scripts/gen-pdo-matrix.mjs >
 * ../docs/pdo-compatibility.md`) is a thin wrapper: read
 * `test/results/pdo-matrix.json` (written by check 28) and
 * `test/pdo-expected.json` (the pin file), render, print to stdout.
 *
 * Determinism (design §5.2): the rendered doc contains ONLY the guest PHP
 * version, the case list (sorted by id within each group, groups in the
 * order they first appear in the report — which is Cases::groups()'s
 * declaration order, since that is how the report was assembled), each
 * case's member/title/class, and — for non-match cases — the pinned `why`.
 * No timestamps, durations, atom ids, base URLs, run counts, or rendered
 * `ours`/`theirs` values. Two runs of the same tree produce the same bytes,
 * on a laptop and on a deployed Worker alike.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

/**
 * Internal taxonomy -> published name (design §5.3). Renamed in exactly this
 * one place, so the internal classifier can stay precise while the doc reads
 * like a document.
 */
const PUBLISHED_CLASS = {
    match: 'supported',
    refused_by_us: 'refused',
    refused_by_both: 'refused by both',
    refused_by_comparator: 'comparator-only refusal',
    deviation: 'differs',
    informational: 'undefined',
};

/** Fixed legend, in the order a reader should meet the classes. */
const LEGEND = [
    ['supported', 'Identical to native pdo_sqlite.'],
    ['refused', 'Atoms raises a typed `PDOException`; native pdo_sqlite answers. Never a wrong answer.'],
    ['refused by both', 'Both raise; the SQLSTATEs are noted where they differ.'],
    ['comparator-only refusal', 'Atoms answers a value; native pdo_sqlite raises instead — the opposite asymmetry from `refused`.'],
    ['differs', 'Both answer, and the answers differ. Documented below with the reason.'],
    ['undefined', "PDO's own contract leaves it undefined (see `PDOStatement::rowCount()` on a SELECT)."],
];

/**
 * Per-case-id explanations for the `informational` class (design §2.5),
 * committed here rather than measured. This MUST be
 * a per-id lookup, never a blanket constant applied to every case the runner
 * happens to observe as `informational` — that would let 'informational'
 * become an unbounded escape hatch from the pin rules (which skip it
 * entirely). {@see informationalNote} throws on any id not listed here, so a
 * new informational case without its own reviewed note is a loud generator
 * failure, not a silently-reused explanation.
 */
const INFORMATIONAL_NOTES = {
    'count.rowcount.select':
        "Undefined by PDO's own contract, and measured proof that real pdo_sqlite is not even " +
        'self-consistent: after an INSERT, a SELECT matching zero rows reported rowCount() === 1 (a ' +
        'stale sqlite3_changes). Comparing it would compare two different flavours of undefined; both ' +
        'observed values are recorded here instead.',
};

/**
 * @param {string} id
 * @returns {string}
 */
function informationalNote(id) {
    if (!Object.prototype.hasOwnProperty.call(INFORMATIONAL_NOTES, id)) {
        throw new Error(
            `renderMatrixDoc: no informational note registered for case id ${JSON.stringify(id)} — ` +
                "'informational' is not a blanket status; add a reviewed entry to INFORMATIONAL_NOTES " +
                'or give the case a real classification.'
        );
    }

    return INFORMATIONAL_NOTES[id];
}

/**
 * @param {string} s
 * @returns {string}
 */
function escapePipes(s) {
    return String(s).replace(/\|/g, '\\|');
}

/**
 * @param {{php: string, cases: Array<{id: string, group: string, member: string, title: string, class: string}>}} report
 * @param {{cases?: Record<string, {class: string, why: string}>}} pins
 * @returns {string}
 */
export function renderMatrixDoc(report, pins) {
    const pinCases = (pins && pins.cases) || {};

    // Group order: the order groups first appear in report.cases, which is
    // the order check 28 assembled them in (Cases::groups() declaration
    // order) — fixed by the source tree, not by anything measured at run
    // time.
    /** @type {string[]} */
    const groupOrder = [];
    const byGroup = new Map();
    for (const c of report.cases) {
        if (!byGroup.has(c.group)) {
            byGroup.set(c.group, []);
            groupOrder.push(c.group);
        }
        byGroup.get(c.group).push(c);
    }

    const lines = [];
    lines.push('# PDO compatibility for the Atoms Cloudflare runtime');
    lines.push('');
    lines.push(
        '**Generated — do not edit by hand.** Produced by ' +
            '`cloudflare/worker/scripts/gen-pdo-matrix.mjs` from a conformance run\'s differential ' +
            'report; conformance check 30 fails if this file and a fresh run disagree. Measured on ' +
            `php-wasm PHP ${report.php}, against \`ctx.storage.sql\`, with a native in-guest ` +
            '`pdo_sqlite` connection as the comparator.'
    );
    lines.push('');
    lines.push('## How to read this');
    lines.push('');
    lines.push('| Class | Meaning |');
    lines.push('|---|---|');
    for (const [cls, meaning] of LEGEND) {
        lines.push(`| \`${cls}\` | ${meaning} |`);
    }

    for (const group of groupOrder) {
        const cases = [...byGroup.get(group)].sort((a, b) => (a.id < b.id ? -1 : a.id > b.id ? 1 : 0));

        lines.push('');
        lines.push(`## ${group}`);
        lines.push('');
        lines.push('| Member | Case | Status | Notes |');
        lines.push('|---|---|---|---|');

        for (const c of cases) {
            const status = PUBLISHED_CLASS[c.class] ?? c.class;
            let notes = '';
            if (c.class === 'informational') {
                notes = informationalNote(c.id);
            } else if (c.class !== 'match') {
                const pin = pinCases[c.id];
                notes = pin && typeof pin.why === 'string' ? pin.why : '';
            }
            lines.push(
                `| \`${escapePipes(c.member)}\` | ${escapePipes(c.title)} | ${status} | ${escapePipes(notes)} |`
            );
        }
    }

    lines.push('');
    return lines.join('\n');
}

// ------------------------------------------------------------------- CLI

const isMain = process.argv[1] && import.meta.url === `file://${process.argv[1]}`;

if (isMain) {
    const __dirname = dirname(fileURLToPath(import.meta.url));
    const workerRoot = join(__dirname, '..');
    const reportPath = join(workerRoot, 'test', 'results', 'pdo-matrix.json');
    const pinPath = join(workerRoot, 'test', 'pdo-expected.json');

    const report = JSON.parse(readFileSync(reportPath, 'utf8'));
    const pins = JSON.parse(readFileSync(pinPath, 'utf8'));

    const doc = renderMatrixDoc(report, pins);

    // Default: print to stdout (`node scripts/gen-pdo-matrix.mjs >
    // ../docs/pdo-compatibility.md`, the regeneration command check 30's
    // failure message names). `--write` writes the file directly, for
    // convenience.
    if (process.argv.includes('--write')) {
        const outPath = join(workerRoot, '..', 'docs', 'pdo-compatibility.md');
        writeFileSync(outPath, doc);
        console.error(`Wrote ${outPath}`);
    } else {
        process.stdout.write(doc);
    }
}
