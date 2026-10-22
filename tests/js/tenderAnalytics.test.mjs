import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { test } from 'node:test';
import ts from 'typescript';

const source = await readFile(new URL('../../resources/js/lib/tenderAnalytics.ts', import.meta.url), 'utf8');
const compiled = ts.transpile(source, { module: ts.ModuleKind.ESNext, target: ts.ScriptTarget.ES2022 });
const { priceScenario, scenarioMoney } = await import('data:text/javascript;base64,' + Buffer.from(compiled).toString('base64'));

test('scenario inputs distinguish unknown cost from explicit zero and reject invalid precision and amounts', () => {
    assert.equal(scenarioMoney(''), null);
    assert.equal(scenarioMoney('0'), 0);
    assert.equal(scenarioMoney('1 500,25'), 150025);
    for (const value of ['-1', '1e6', '12.345', 'Infinity', 'NaN', '1000000000001']) assert.equal(scenarioMoney(value), null);
    assert.equal(priceScenario('1000', '', 5).balance, null);
    assert.equal(priceScenario('1000', '0', 5).balance, '950.00');
});

test('price scenarios use kopecks and preserve losses without guessing costs', () => {
    assert.deepEqual(priceScenario('1000.25', '800.10', 10), {offer: '900.23', balance: '100.13', marginPercent: 11.1, breakEvenDiscount: 20});
    assert.equal(priceScenario('1000', '1200', 5).balance, '-250.00');
    assert.equal(priceScenario('1000', '1200', 5).breakEvenDiscount, -20);
    assert.equal(priceScenario('1000', '800', 100).marginPercent, null);
    assert.equal(priceScenario('1000', '999.40', 5).breakEvenDiscount, 0);
    assert.equal(priceScenario('999999999999.99', '', 50).offer, '500000000000.00');
});

test('missing zero oversized and invalid budgets or discounts never produce misleading scenarios', () => {
    for (const budget of [null, '', '0', '-1', 'bad', '1000000000001']) assert.equal(priceScenario(budget, '1', 5), null);
    for (const discount of [-1, 101, NaN, Infinity]) assert.equal(priceScenario('1000', '1', discount), null);
    assert.equal(priceScenario('1000', 'bad-cost', 5).balance, null);
});
