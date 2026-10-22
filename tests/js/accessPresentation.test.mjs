import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/accessPresentation.ts', import.meta.url), 'utf8');
const compiled = ts.transpile(source, { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 });
const { presentAccess } = await import(`data:text/javascript;base64,${Buffer.from(compiled).toString('base64')}`);

const now = new Date('2026-10-22T12:00:00Z');
const trial = (endsAt) => ({
    state: 'trialing', plan_code: 'basic', active_query_limit: 3,
    ends_at: endsAt, mode: null,
});

test('trial is calm until the final 24 hours and then shows a contextual warning', () => {
    const active = presentAccess(trial('2026-10-23T12:00:01Z'), now);
    const ending = presentAccess(trial('2026-10-23T12:00:00Z'), now);
    const urgent = presentAccess(trial('2026-10-22T14:30:00Z'), now);
    assert.equal(active.phase, 'trial');
    assert.equal(ending.phase, 'trial_ending');
    assert.equal(urgent.phase, 'trial_ending');
    assert.match(urgent.detail, /3 ч/);
});

test('elapsed trial is shown as expired even before the page refresh completes', () => {
    const result = presentAccess(trial('2026-10-22T12:00:00Z'), now);
    assert.equal(result.phase, 'expired');
    assert.match(result.description, /Мониторинги остановлены/);
});

test('owner access and expired access do not turn into a purchase claim', () => {
    const owner = presentAccess({
        state: 'active', plan_code: 'pro', active_query_limit: null,
        ends_at: null, mode: 'owner',
    }, now);
    const expired = presentAccess({
        state: 'expired', plan_code: null, active_query_limit: null,
        ends_at: '2026-10-21T12:00:00Z', mode: null,
    }, now);
    assert.equal(owner.phase, 'active');
    assert.equal(owner.detail, 'без даты окончания');
    assert.equal(expired.phase, 'expired');
    assert.match(expired.detail, /до /);
});
