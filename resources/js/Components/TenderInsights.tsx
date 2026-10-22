import { useId, useState } from 'react';
import { priceScenario, type TenderInsights as Insights } from '../lib/tenderAnalytics';
import { summaryDate, summaryMoney, type TenderSummary } from '../lib/tenderSummary';
import { Badge } from './ui';

const priorities = {
    conflict: 'Проверьте даты',
    unknown: 'Уточните срок',
    elapsed: 'Срок по снимку прошёл',
    urgent: 'Сначала проверьте сроки',
    soon: 'Подготовка в ближайшие дни',
    normal: 'Плановая подготовка',
};

export function TenderInsights({
    analytics,
    summary,
}: {
    analytics: Insights;
    summary: TenderSummary;
}) {
    const pro = analytics.pro;
    if (!pro || analytics.tier === 'basic') return null;
    const ultra = analytics.tier === 'ultra' ? analytics.ultra : undefined;

    return (
        <div className={'tender-insights tender-insights--' + analytics.tier}>
            <section className="tender-insights__section" aria-label="Разбор условий">
                <div className="tender-insights__section-heading">
                    <div>
                        <p className="tender-insights__eyebrow">
                            01 / Условия и подготовка
                        </p>
                        <h3>Что проверить в первую очередь</h3>
                    </div>
                    <Badge
                        tone={['normal'].includes(pro.priority) ? 'neutral' : 'warning'}
                    >
                        {priorities[pro.priority]}
                    </Badge>
                </div>
                <div className="tender-insights__stats">
                    <div>
                        <span>Окно подачи</span>
                        <strong>
                            {pro.window_hours === null
                                ? 'Не рассчитано'
                                : pro.window_hours.toLocaleString('ru-RU') + ' ч'}
                        </strong>
                        <small>Между публикацией и дедлайном</small>
                    </div>
                    <div>
                        <span>Полнота карточки</span>
                        <strong>
                            {pro.known_fields} из {pro.total_fields}
                        </strong>
                        <small>Наличие полей, не оценка надёжности</small>
                    </div>
                </div>
                <div
                    className="tender-insights__meter"
                    role="img"
                    aria-label={'Заполнено ' + pro.completeness_percent + '% полей'}
                >
                    <span style={{ width: pro.completeness_percent + '%' }} />
                </div>
                <ul className="tender-insights__checks">
                    {pro.checks.map((check) => (
                        <li
                            key={check.code}
                            className={check.present ? 'is-present' : ''}
                        >
                            <span aria-hidden="true">{check.present ? '✓' : '—'}</span>
                            <span>
                                {check.label}
                                <small>
                                    {check.present
                                        ? 'Передано источником'
                                        : 'Не передано'}
                                </small>
                            </span>
                        </li>
                    ))}
                </ul>
                {pro.buffer_at ? (
                    <p className="tender-insights__buffer">
                        Ориентир внутренней готовности:{' '}
                        <strong>{summaryDate(pro.buffer_at)}</strong>. Это запас в 24
                        часа до подачи, а не официальный срок.
                    </p>
                ) : null}
                {pro.signals.length ? (
                    <div className="tender-insights__signals">
                        {pro.signals.map((signal) => (
                            <div
                                key={signal.title}
                                className={
                                    'tender-insights__signal tender-insights__signal--' +
                                    signal.tone
                                }
                            >
                                <strong>{signal.title}</strong>
                                <p>{signal.detail}</p>
                            </div>
                        ))}
                    </div>
                ) : null}
                <h4>План проверки</h4>
                <ol className="tender-insights__actions">
                    {pro.actions.map((action) => (
                        <li key={action}>{action}</li>
                    ))}
                </ol>
            </section>
            {ultra ? (
                <>
                    <section
                        className="tender-insights__section"
                        aria-label="Сравнение бюджетов"
                    >
                        <p className="tender-insights__eyebrow">
                            02 / Контекст вашей выборки
                        </p>
                        <h3>Как бюджет выглядит среди похожих закупок</h3>
                        <p className="tender-insights__description">
                            Те же мониторинги, общие слова в названии и одна валюта. За
                            последние {ultra.comparison.window_days} дней найдено{' '}
                            {ultra.comparison.sample_count} сопоставимых закупок.
                        </p>
                        {ultra.comparison.median !== null ? (
                            <>
                                <div className="tender-insights__benchmark">
                                    <div>
                                        <span>Нижний квартиль</span>
                                        <strong>
                                            {summaryMoney(
                                                ultra.comparison.lower_quartile,
                                                ultra.comparison.currency,
                                            )}
                                        </strong>
                                    </div>
                                    <div className="is-median">
                                        <span>Медиана выборки</span>
                                        <strong>
                                            {summaryMoney(
                                                ultra.comparison.median,
                                                ultra.comparison.currency,
                                            )}
                                        </strong>
                                    </div>
                                    <div>
                                        <span>Верхний квартиль</span>
                                        <strong>
                                            {summaryMoney(
                                                ultra.comparison.upper_quartile,
                                                ultra.comparison.currency,
                                            )}
                                        </strong>
                                    </div>
                                </div>
                                {ultra.comparison.difference_percent !== null ? (
                                    <p className="tender-insights__position">
                                        Текущий бюджет{' '}
                                        <strong>
                                            {ultra.comparison.difference_percent === 0
                                                ? 'равен медиане'
                                                : 'на ' +
                                                  Math.abs(
                                                      ultra.comparison
                                                          .difference_percent,
                                                  ).toLocaleString('ru-RU') +
                                                  '% ' +
                                                  (ultra.comparison.difference_percent >
                                                  0
                                                      ? 'выше'
                                                      : 'ниже') +
                                                  ' медианы'}
                                        </strong>
                                        . Состав работ может различаться.
                                    </p>
                                ) : null}
                                <p className="tender-insights__footnote">
                                    Между квартилями находится центральная половина
                                    бюджетов выборки. Это начальные цены, а не цены
                                    заключённых контрактов или прогноз выигрыша.
                                </p>
                            </>
                        ) : (
                            <p className="tender-insights__empty">
                                Для расчёта нужно минимум{' '}
                                {ultra.comparison.minimum_sample} сопоставимые закупки.
                                Пока их меньше — статистика не рассчитывается.
                            </p>
                        )}
                        <p className="tender-insights__footnote">
                            {ultra.comparison.scope === 'shared_monitorings'
                                ? 'Только общие мониторинги этой команды.'
                                : 'Только ваши мониторинги.'}{' '}
                            Рассмотрено не больше {ultra.comparison.candidate_limit}{' '}
                            последних карточек; повторы по номеру или ссылке исключены.
                            {ultra.comparison.truncated
                                ? ' Выборка ограничена последними карточками.'
                                : ''}{' '}
                            Сравнение не охватывает весь рынок.
                        </p>
                    </section>
                    <section
                        className="tender-insights__section"
                        aria-label="Сценарии цены"
                    >
                        <p className="tender-insights__eyebrow">
                            03 / Экономика решения
                        </p>
                        <h3>Проверьте свою цену до участия</h3>
                        <TenderPriceScenarios summary={summary} />
                    </section>
                    <section
                        className="tender-insights__section"
                        aria-label="Готовность заявки"
                    >
                        <p className="tender-insights__eyebrow">
                            04 / Готовность заявки
                        </p>
                        <h3>От условий к действиям</h3>
                        {!ultra.readiness.started ? (
                            <p className="tender-insights__empty">
                                Работа над заявкой ещё не начата. Добавьте её в участие
                                и создайте чек-лист — здесь появится прогресс
                                подготовки.
                            </p>
                        ) : ultra.readiness.tasks_total === 0 ? (
                            <p className="tender-insights__empty">
                                Заявка создана, но задач пока нет. Без чек-листа процент
                                готовности не рассчитывается.
                            </p>
                        ) : (
                            <>
                                <div className="tender-insights__stats">
                                    <div>
                                        <span>Задачи выполнены</span>
                                        <strong>
                                            {ultra.readiness.tasks_completed} /{' '}
                                            {ultra.readiness.tasks_total}
                                        </strong>
                                        <small>
                                            {ultra.readiness.completion_percent}%
                                            чек-листа
                                        </small>
                                    </div>
                                    <div>
                                        <span>Открытые просроченные</span>
                                        <strong>{ultra.readiness.tasks_overdue}</strong>
                                        <small>По календарю вашего профиля</small>
                                    </div>
                                </div>
                                <div
                                    className="tender-insights__meter"
                                    role="img"
                                    aria-label={
                                        'Выполнено ' +
                                        ultra.readiness.completion_percent +
                                        '% задач'
                                    }
                                >
                                    <span
                                        style={{
                                            width:
                                                (ultra.readiness.completion_percent ??
                                                    0) + '%',
                                        }}
                                    />
                                </div>
                            </>
                        )}
                        <p className="tender-insights__footnote">
                            {ultra.readiness.scope === 'team'
                                ? 'Чек-лист этой команды.'
                                : 'Ваш личный чек-лист.'}{' '}
                            Выполнение задач не подтверждает допуск заявки или её
                            отправку на площадку.
                        </p>
                    </section>
                    <details className="tender-insights__section tender-insights__history">
                        <summary>
                            <span>
                                <span className="tender-insights__eyebrow">
                                    05 / История условий
                                </span>
                                <strong>Что изменилось после сохранения</strong>
                            </span>
                            <Badge tone="neutral">
                                {ultra.changes.items.length}
                                {ultra.changes.has_more ? '+' : ''}
                            </Badge>
                        </summary>
                        {ultra.changes.items.length === 0 ? (
                            <p className="tender-insights__empty">
                                Изменения цены, срока или этапа пока не зафиксированы.
                                Это не означает, что источник не менял условия до
                                получения данных.
                            </p>
                        ) : (
                            <ol className="tender-insights__events">
                                {ultra.changes.items.map((event, index) => (
                                    <li key={index}>
                                        <time>{summaryDate(event.at)}</time>
                                        {event.fields.map((field) => (
                                            <p key={field.field}>
                                                <strong>
                                                    {(
                                                        {
                                                            budget_amount: 'Бюджет',
                                                            currency: 'Валюта',
                                                            deadline_at: 'Срок подачи',
                                                            stage: 'Этап',
                                                        } as Record<string, string>
                                                    )[field.field] ?? field.field}
                                                </strong>
                                                <span>
                                                    {changeValue(
                                                        field.field,
                                                        field.before,
                                                    )}{' '}
                                                    →{' '}
                                                    {changeValue(
                                                        field.field,
                                                        field.after,
                                                    )}
                                                </span>
                                            </p>
                                        ))}
                                    </li>
                                ))}
                            </ol>
                        )}
                        {ultra.changes.has_more ? (
                            <p className="tender-insights__footnote">
                                Показаны последние 10 записей.
                            </p>
                        ) : null}
                    </details>
                </>
            ) : null}
            <p className="tender-insights__footnote">
                Разбор на {summaryDate(analytics.generated_at)}. Расчёты используют
                доступные данные карточки; условия участия сверяйте с документацией
                закупки.
            </p>
        </div>
    );
}

function changeValue(field: string, value: string | null): string {
    if (value === null) return 'Не указано';
    if (field === 'deadline_at') return summaryDate(value);
    if (field === 'budget_amount' && Number.isFinite(Number(value)))
        return Number(value).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
    return value;
}

function TenderPriceScenarios({ summary }: { summary: TenderSummary }) {
    const id = useId();
    const [cost, setCost] = useState('');
    const [discount, setDiscount] = useState(5);
    const selected = priceScenario(summary.budget_amount, cost, discount);
    const invalidCost = cost.trim() !== '' && selected?.balance === null;
    if (!selected)
        return (
            <p className="tender-insights__empty">
                Для денежных сценариев нужна положительная начальная цена в карточке.
                Уточните бюджет на площадке.
            </p>
        );
    return (
        <div className="tender-insights__calculator">
            <p className="tender-insights__description">
                Укажите все свои затраты на исполнение, налоги, комиссии и стоимость
                обеспечения. Расчёт останется в этой вкладке.
            </p>
            <label htmlFor={id + '-cost'}>
                Ваши совокупные затраты, {summary.currency}
                <input
                    id={id + '-cost'}
                    inputMode="decimal"
                    value={cost}
                    onChange={(event) => setCost(event.target.value)}
                    placeholder="Введите сумму для расчёта остатка"
                    autoComplete="off"
                    aria-invalid={invalidCost}
                    aria-describedby={invalidCost ? id + '-error' : undefined}
                />
            </label>
            {invalidCost ? (
                <p
                    id={id + '-error'}
                    className="tender-insights__input-error"
                    role="alert"
                >
                    Введите неотрицательную сумму до 1 трлн, не больше двух знаков после
                    запятой.
                </p>
            ) : null}
            <label htmlFor={id + '-discount'}>
                Снижение начальной цены: <strong>{discount}%</strong>
                <input
                    id={id + '-discount'}
                    type="range"
                    min="0"
                    max="50"
                    step="1"
                    value={discount}
                    onChange={(event) => setDiscount(Number(event.target.value))}
                />
            </label>
            <div className="tender-insights__stats" aria-live="polite">
                <div>
                    <span>Цена предложения</span>
                    <strong>{summaryMoney(selected.offer, summary.currency)}</strong>
                </div>
                <div>
                    <span>Остаток после ваших затрат</span>
                    <strong
                        className={
                            selected.balance !== null && Number(selected.balance) < 0
                                ? 'is-negative'
                                : ''
                        }
                    >
                        {selected.balance === null
                            ? 'Укажите затраты'
                            : summaryMoney(selected.balance, summary.currency)}
                    </strong>
                    <small>
                        {selected.marginPercent !== null
                            ? selected.marginPercent.toLocaleString('ru-RU') +
                              '% от цены предложения'
                            : 'Без предположений о себестоимости'}
                    </small>
                </div>
            </div>
            <div className="tender-insights__table-wrap">
                <table>
                    <caption>Сравнение сценариев снижения</caption>
                    <thead>
                        <tr>
                            <th scope="col">Снижение</th>
                            <th scope="col">Предложение</th>
                            <th scope="col">Остаток</th>
                        </tr>
                    </thead>
                    <tbody>
                        {[0, 5, 10, 15].map((value) => {
                            const scenario = priceScenario(
                                summary.budget_amount,
                                cost,
                                value,
                            )!;
                            return (
                                <tr key={value}>
                                    <th scope="row">{value}%</th>
                                    <td>
                                        {summaryMoney(scenario.offer, summary.currency)}
                                    </td>
                                    <td>
                                        {scenario.balance === null
                                            ? '—'
                                            : summaryMoney(
                                                  scenario.balance,
                                                  summary.currency,
                                              )}
                                    </td>
                                </tr>
                            );
                        })}
                    </tbody>
                </table>
            </div>
            {selected.breakEvenDiscount !== null ? (
                <p className="tender-insights__buffer">
                    {selected.breakEvenDiscount < 0
                        ? 'Указанные затраты уже выше начальной цены.'
                        : 'Расчётный предел снижения без отрицательного остатка: ' +
                          selected.breakEvenDiscount.toLocaleString('ru-RU') +
                          '%.'}{' '}
                    Учитываются только введённые вами затраты.
                </p>
            ) : null}
            <p className="tender-insights__footnote">
                Сценарии не рекомендуют цену для торгов и не прогнозируют прибыль:
                неизвестные расходы, сроки оплаты и требования закупки нужно оценить
                отдельно.
            </p>
        </div>
    );
}
