import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/tenderSummary.ts', import.meta.url), 'utf8');
const compiled = ts.transpile(source, { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 });
const { deadlineSummary, summaryDate, summaryMoney } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));
const now = Date.parse('2026-10-22T12:00:00Z');
const summary = (deadline, published = '2026-10-20T12:00:00Z') => ({
    deadline_at: deadline, published_at: published,
});

test('countdown distinguishes missing deadlines, invalid dates, urgent minutes and an elapsed deadline', () => {
    assert.equal(deadlineSummary(summary(null), now).remaining, null);
    assert.equal(deadlineSummary(summary('invalid'), now).label, 'Срок не указан');
    assert.equal(deadlineSummary(summary('2026-10-22T12:00:30Z'), now).label, 'Меньше минуты');
    assert.equal(deadlineSummary(summary('2026-10-22T12:59:00Z'), now).label, '59 мин');
    assert.equal(deadlineSummary(summary('2026-10-22T14:30:00Z'), now).label, '2 ч 30 мин');
    assert.equal(deadlineSummary(summary('2026-10-24T13:00:00Z'), now).label, '2 дн. 1 ч');
    assert.equal(deadlineSummary(summary('2026-10-22T12:00:00Z'), now).elapsed, true);
});

test('timeline clamps elapsed periods and does not calculate a misleading period for inconsistent dates', () => {
    assert.equal(deadlineSummary(summary('2026-10-24T12:00:00Z'), now).progress, 50);
    assert.equal(deadlineSummary(summary('2026-10-21T12:00:00Z'), now).progress, 100);
    assert.equal(deadlineSummary(summary('2026-10-24T12:00:00Z', null), now).progress, null);
    assert.equal(deadlineSummary(summary('2026-10-21T12:00:00Z', '2026-10-24T12:00:00Z'), now).contradictory, true);
    assert.equal(deadlineSummary(summary('2026-10-24T12:00:00Z', '2026-10-23T12:00:00Z'), now).progress, null);
});

test('absolute deadlines explicitly use Moscow time and absent amounts do not become zero', () => {
    assert.match(summaryDate('2026-10-22T23:30:00Z'), /23 окт.*02:30 МСК/);
    assert.equal(summaryDate('bad-date'), 'Не указана');
    assert.equal(summaryMoney(null, 'RUB'), 'Не указан');
    assert.equal(summaryMoney('', 'RUB'), 'Не указан');
    assert.equal(summaryMoney('bad-price', 'RUB'), 'Не указан');
    assert.match(summaryMoney('0', 'RUB'), /0/);
    assert.match(summaryMoney('1500000.25', 'RUB'), /25/);
    assert.match(summaryMoney('123.45', 'not-a-currency'), /123,45 not-a-currency/);
});
