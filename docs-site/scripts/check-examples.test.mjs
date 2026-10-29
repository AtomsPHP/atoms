import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkExamples } from './check-examples.mjs';

const fenced = (info, content) => `\`\`\`${info}\n${content}\n\`\`\``;

test('accepts valid JSON', () => {
    assert.deepEqual(checkExamples(fenced('json title="atoms.json"', '{"key": true}')), []);
});

test('rejects comments and trailing commas in JSON', () => {
    for (const content of ['{"key": true // comment\n}', '{"key": true,}']) {
        assert.match(checkExamples(fenced('json', content))[0], /invalid JSON/);
    }
});

test('rejects a .json title on a jsonc fence', () => {
    assert.match(checkExamples(fenced('jsonc title="atoms.json"', '{}'))[0], /must use a json fence/);
});

test('ignores JSONC files with comments', () => {
    assert.deepEqual(checkExamples(fenced('jsonc title="wrangler.jsonc"', '{ // comment\n}')), []);
});
