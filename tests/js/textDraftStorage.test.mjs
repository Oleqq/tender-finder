import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/textDraftStorage.ts', import.meta.url), 'utf8');
const compiled = ts.transpile(source, { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 });
const { readTextDraft, sameTextFields, textDraftKey, textDraftLifetime, clearSavedTextDraft } =
    await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));
const now = Date.now();
const base = { title: 'Уточнение цены', body: 'Сохранённый текст' };
const fields = { title: 'Уточнение монтажа', body: 'Правки: ёж, ₽, 👋\nВторая строка' };
const record = (update = {}) => JSON.stringify({schema: 1, base, fields, revision: 'source-1', updatedAt: now, ...update});

test('recovery preserves exact Unicode, whitespace and explicitly cleared fields', () => {
    assert.deepEqual(readTextDraft(record(), base, now).fields, fields);
    assert.equal(readTextDraft(record({fields: {...fields, body: ''}}), base, now).fields.body, '');
    assert.equal(sameTextFields({body: 'a', title: 'b'}, {title: 'b', body: 'a'}), true);
    assert.equal(sameTextFields(base, {...base, extra: ''}), false);
});

test('recovery expires exactly after 24 hours and rejects future and invalid timestamps', () => {
    assert.ok(readTextDraft(record({updatedAt: now - textDraftLifetime + 1}), base, now));
    for (const updatedAt of [now - textDraftLifetime, now + 1, null, 'yesterday'])
        assert.equal(readTextDraft(record({updatedAt}), base, now), null);
});

test('malformed, wrong-schema, wrong-shape and oversized caches cannot fill a form', () => {
    for (const raw of [null, '', '{', 'null', '[]', record({schema: 2}),
        record({revision: null}), record({revision: 'x'.repeat(501)}),
        record({fields: {title: 'x'}}), record({fields: {...fields, extra: 'x'}}),
        record({fields: {...fields, body: {bad: true}}}), record({base: []}),
        record({fields: {...fields, body: 'x'.repeat(50001)}}), 'x'.repeat(2000001)])
        assert.equal(readTextDraft(raw, base, now), null);
    const escaped = {title: '', body: '\n'.repeat(50000)};
    assert.deepEqual(readTextDraft(record({base: escaped, fields: escaped}), escaped, now).fields, escaped);
});

test('cache keys isolate users, teams, entities and draft types', () => {
    const keys = [textDraftKey(1, 'proposal:personal:42'), textDraftKey(2, 'proposal:personal:42'),
        textDraftKey(1, 'proposal:7:42'), textDraftKey(1, 'proposal:personal:43'),
        textDraftKey(1, 'winner:42'), textDraftKey(1, '/tenders/42/questions:question:1'),
        textDraftKey(1, '/tenders/42/questions:answer:1')];
    assert.equal(new Set(keys).size, keys.length);
});

test('newer server text is distinguishable from the baseline of recovered local edits', () => {
    const row = readTextDraft(record(), base, now);
    assert.equal(sameTextFields(row.base, base), true);
    assert.equal(sameTextFields(row.base, {...base, body: 'Новая сохранённая редакция'}), false);
});

test('successful saves clear only the submitted copy and preserve newer local edits', () => {
    const rows = new Map([['draft', record()]]);
    const storage = {getItem: (key) => rows.get(key) ?? null, removeItem: (key) => rows.delete(key)};
    clearSavedTextDraft(storage, 'draft', base);
    assert.ok(rows.has('draft'));
    clearSavedTextDraft(storage, 'draft', fields);
    assert.equal(rows.has('draft'), false);
    rows.set('draft', record({fields: {...fields, body: 'Правка после начала сохранения'}}));
    clearSavedTextDraft(storage, 'draft', fields);
    assert.ok(rows.has('draft'));
});
