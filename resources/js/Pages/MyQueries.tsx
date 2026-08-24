import { Head, usePage } from '@inertiajs/react';
import { FormEvent, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, Button, FieldError, GlassCard, InlineAlert } from '../Components/ui';
import type { PageProps } from '../types';

type QueryStatus = 'active' | 'paused' | 'frozen';

type QueryDto = {
    id: number;
    name: string;
    keywords: string[];
    minus_keywords: string[] | null;
    region: string | null;
    budget_min: string | null;
    budget_max: string | null;
    deadline_from: string | null;
    deadline_to: string | null;
    status: QueryStatus;
    monitoring_started_at: string | null;
};

type MyQueriesProps = {
    queries: QueryDto[];
};

export default function MyQueries() {
    const { auth, queries: initialQueries } =
        usePage<PageProps<MyQueriesProps>>().props;
    const [queries, setQueries] = useState<QueryDto[]>(initialQueries);
    const [keywords, setKeywords] = useState('');
    const [minusKeywords, setMinusKeywords] = useState('');
    const [region, setRegion] = useState('');
    const [budgetMin, setBudgetMin] = useState('');
    const [budgetMax, setBudgetMax] = useState('');
    const [deadlineFrom, setDeadlineFrom] = useState('');
    const [deadlineTo, setDeadlineTo] = useState('');
    const [error, setError] = useState('');
    const [isSubmitting, setIsSubmitting] = useState(false);

    const createQuery = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();
        const values = keywords
            .split(',')
            .map((keyword) => keyword.trim())
            .filter(Boolean);

        if (values.length === 0) {
            setError('Укажите хотя бы одно ключевое слово через запятую.');
            return;
        }

        const excludedValues = minusKeywords
            .split(',')
            .map((keyword) => keyword.trim())
            .filter(Boolean);

        setError('');
        setIsSubmitting(true);

        try {
            const response = await window.axios.post<{ query: QueryDto }>('/queries', {
                keywords: values,
                minus_keywords: excludedValues,
                region: region || null,
                budget_min: budgetMin || null,
                budget_max: budgetMax || null,
                deadline_from: deadlineFrom || null,
                deadline_to: deadlineTo || null,
            });
            setQueries((current) => [response.data.query, ...current]);
            setKeywords('');
            setMinusKeywords('');
            setRegion('');
            setBudgetMin('');
            setBudgetMax('');
            setDeadlineFrom('');
            setDeadlineTo('');
        } catch {
            setError(
                'Не удалось создать мониторинг. Проверьте доступ и попробуйте ещё раз.',
            );
        } finally {
            setIsSubmitting(false);
        }
    };

    const changeStatus = async (
        query: QueryDto,
        action: 'pause' | 'resume' | 'freeze',
    ): Promise<void> => {
        setError('');

        try {
            const response = await window.axios.post<{ query: QueryDto }>(
                `/queries/${query.id}/${action}`,
            );
            setQueries((current) =>
                current.map((item) =>
                    item.id === query.id ? response.data.query : item,
                ),
            );
        } catch {
            setError(
                'Не удалось изменить состояние мониторинга. Ничего не потеряно — попробуйте ещё раз.',
            );
        }
    };

    const accessText = auth.access?.active_query_limit
        ? `${queries.filter((query) => query.status === 'active').length} из ${auth.access.active_query_limit} активных`
        : 'Нужен server-side trial или Basic-доступ';

    return (
        <>
            <Head title="Мои мониторинги" />
            <AppShell
                activeNav="/tenders"
                eyebrow="Защищённый раздел"
                role={auth.user?.role ?? 'subscriber'}
                title="Мониторинги"
            >
                <GlassCard className="query-access page-enter" tone="quiet">
                    <span className="query-access__icon">
                        <Icon name="layers" size={19} />
                    </span>
                    <div>
                        <p>Лимит Basic / trial</p>
                        <strong>{accessText}</strong>
                    </div>
                    <Badge tone={auth.access ? 'accent' : 'neutral'}>
                        {auth.access?.state ?? 'preview'}
                    </Badge>
                </GlassCard>

                <GlassCard className="query-create page-enter page-enter--delay">
                    <div className="section-heading">
                        <div>
                            <p>Новый мониторинг</p>
                            <h2>Что искать?</h2>
                        </div>
                    </div>
                    <form onSubmit={createQuery}>
                        <label className="form-field">
                            <span>Ключевые слова</span>
                            <input
                                onChange={(event) => setKeywords(event.target.value)}
                                placeholder="например, сайт, поддержка"
                                value={keywords}
                            />
                        </label>
                        <label className="form-field">
                            <span>Минус-слова</span>
                            <input
                                onChange={(event) =>
                                    setMinusKeywords(event.target.value)
                                }
                                placeholder="например, строительство"
                                value={minusKeywords}
                            />
                        </label>
                        <label className="form-field">
                            <span>Регион</span>
                            <input
                                onChange={(event) => setRegion(event.target.value)}
                                placeholder="например, Москва"
                                value={region}
                            />
                        </label>
                        <div className="query-create__grid">
                            <label className="form-field">
                                <span>Бюджет от, ₽</span>
                                <input
                                    inputMode="decimal"
                                    min="0"
                                    onChange={(event) =>
                                        setBudgetMin(event.target.value)
                                    }
                                    placeholder="0"
                                    type="number"
                                    value={budgetMin}
                                />
                            </label>
                            <label className="form-field">
                                <span>Бюджет до, ₽</span>
                                <input
                                    inputMode="decimal"
                                    min="0"
                                    onChange={(event) =>
                                        setBudgetMax(event.target.value)
                                    }
                                    placeholder="Без лимита"
                                    type="number"
                                    value={budgetMax}
                                />
                            </label>
                            <label className="form-field">
                                <span>Дедлайн от</span>
                                <input
                                    onChange={(event) =>
                                        setDeadlineFrom(event.target.value)
                                    }
                                    type="date"
                                    value={deadlineFrom}
                                />
                            </label>
                            <label className="form-field">
                                <span>Дедлайн до</span>
                                <input
                                    onChange={(event) =>
                                        setDeadlineTo(event.target.value)
                                    }
                                    type="date"
                                    value={deadlineTo}
                                />
                            </label>
                        </div>
                        <p className="query-create__hint">
                            Запятая разделяет слова. Keywords обязательны, минус-слова
                            исключают совпадение; неизвестные RSS-поля не угадываются.
                        </p>
                        {error ? <FieldError>{error}</FieldError> : null}
                        <Button disabled={isSubmitting} icon="check" type="submit">
                            {isSubmitting ? 'Создаём…' : 'Включить мониторинг'}
                        </Button>
                    </form>
                </GlassCard>

                {error ? (
                    <InlineAlert title="Можно повторить" tone="warning">
                        Сервер не сохранил действие, если не показал обновлённую
                        карточку ниже.
                    </InlineAlert>
                ) : null}

                <section className="query-list page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Сохранённые настройки</p>
                            <h2>Ваши мониторинги</h2>
                        </div>
                    </div>
                    {queries.length === 0 ? (
                        <GlassCard tone="quiet">
                            <p>
                                Пока нет сохранённых мониторингов. Создайте первый,
                                когда доступ станет активным.
                            </p>
                        </GlassCard>
                    ) : (
                        queries.map((query) => (
                            <GlassCard
                                as="article"
                                className="query-card"
                                key={query.id}
                            >
                                <div>
                                    <Badge
                                        tone={
                                            query.status === 'active'
                                                ? 'success'
                                                : 'neutral'
                                        }
                                    >
                                        {statusLabel(query.status)}
                                    </Badge>
                                    <h3>{query.name}</h3>
                                    <p>{query.keywords.join(' · ')}</p>
                                </div>
                                <div className="query-card__actions">
                                    {query.status === 'active' ? (
                                        <Button
                                            onClick={() => changeStatus(query, 'pause')}
                                            size="sm"
                                            variant="secondary"
                                        >
                                            Пауза
                                        </Button>
                                    ) : (
                                        <Button
                                            onClick={() =>
                                                changeStatus(query, 'resume')
                                            }
                                            size="sm"
                                            variant="secondary"
                                        >
                                            Возобновить
                                        </Button>
                                    )}
                                    {query.status !== 'frozen' ? (
                                        <Button
                                            onClick={() =>
                                                changeStatus(query, 'freeze')
                                            }
                                            size="sm"
                                            variant="ghost"
                                        >
                                            Заморозить
                                        </Button>
                                    ) : null}
                                </div>
                            </GlassCard>
                        ))
                    )}
                </section>
            </AppShell>
        </>
    );
}

function statusLabel(status: QueryStatus): string {
    return {
        active: 'Активен',
        paused: 'На паузе',
        frozen: 'Заморожен',
    }[status];
}
