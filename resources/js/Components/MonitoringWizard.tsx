import { useEffect, useRef, useState, type FormEvent, type ReactNode } from 'react';
import { Button, FieldError } from './ui';

type Preview = {
    scope: string;
    checked: number;
    matched: number;
    unknown: number;
    excluded: Record<string, number>;
    tenders: Array<{
        title: string;
        url: string;
        budget: string | null;
        region: string | null;
    }>;
};

const steps = ['Ниша', 'Регион', 'Бюджет и сроки', 'Источник', 'Проверка'];
const reasons: Record<string, string> = {
    keyword: 'Не совпали ключевые слова',
    minus_keyword: 'Найдены минус-слова',
    region: 'Другой регион',
    budget: 'Цена вне диапазона',
    deadline: 'Срок вне диапазона',
    customer: 'Исключённый заказчик',
};

export function MonitoringWizard({
    fields,
    payload,
    sourceSelected,
    saving,
    error,
    onSubmit,
}: {
    fields: (step: number) => ReactNode;
    payload: Record<string, unknown> | null;
    sourceSelected: boolean;
    saving: boolean;
    error: string;
    onSubmit: (event: FormEvent<HTMLFormElement>) => Promise<void>;
}) {
    const [step, setStep] = useState(0);
    const formRef = useRef<HTMLFormElement>(null);
    const previousStep = useRef(step);
    useEffect(() => {
        if (previousStep.current === step) return;
        previousStep.current = step;
        const card = formRef.current?.closest('.query-create');
        (card ?? formRef.current)?.scrollIntoView({ block: 'start' });
        formRef.current
            ?.querySelector<HTMLElement>('legend')
            ?.focus({ preventScroll: true });
    }, [step]);
    const [loading, setLoading] = useState(false);
    const [previewError, setPreviewError] = useState('');
    const [result, setResult] = useState<{ key: string; preview: Preview } | null>(
        null,
    );
    const key = JSON.stringify(payload);
    const preview = result?.key === key ? result.preview : null;

    const check = async () => {
        if (!payload) return;
        setLoading(true);
        setPreviewError('');
        setResult(null);
        try {
            const response = await window.axios.post<Preview>(
                '/queries/preview',
                payload,
            );
            setResult({ key, preview: response.data });
        } catch (failure) {
            const response = (
                failure as {
                    response?: {
                        data?: { message?: string; errors?: Record<string, string[]> };
                    };
                }
            ).response;
            setPreviewError(
                Object.values(response?.data?.errors ?? {}).flat()[0] ??
                    response?.data?.message ??
                    'Не удалось проверить источник. Попробуйте ещё раз.',
            );
        } finally {
            setLoading(false);
        }
    };

    return (
        <form
            ref={formRef}
            onSubmit={(event) => {
                if (step < 4) {
                    event.preventDefault();
                    if (payload && (step < 3 || sourceSelected)) setStep(step + 1);
                    return;
                }
                void onSubmit(event);
            }}
        >
            <div
                aria-label="Прогресс настройки мониторинга"
                className="monitoring-wizard__progress"
                role="group"
            >
                <span>
                    Шаг {step + 1} из {steps.length}
                </span>
                <div aria-hidden="true" className="monitoring-wizard__progress-track">
                    <span style={{ width: `${((step + 1) / steps.length) * 100}%` }} />
                </div>
            </div>
            <fieldset
                disabled={saving || loading}
                className="monitoring-wizard__fields"
            >
                <legend tabIndex={-1}>{steps[step]}</legend>
                {step < 4 ? (
                    fields(step)
                ) : (
                    <>
                        <p>
                            Перед включением проверьте небольшую выборку из источника.
                            Она поможет уточнить условия поиска.
                        </p>
                        <Button
                            disabled={!payload || !sourceSelected || loading}
                            onClick={check}
                            variant="secondary"
                        >
                            {loading
                                ? 'Проверяем источник…'
                                : 'Посмотреть предварительную выдачу'}
                        </Button>
                        {preview ? (
                            <div
                                aria-live="polite"
                                className="monitoring-wizard__preview"
                            >
                                <p>{preview.scope}</p>
                                <strong>
                                    Подходит {preview.matched} из {preview.checked}{' '}
                                    проверенных карточек
                                </strong>
                                {preview.checked === 0 ? (
                                    <p>
                                        Источник вернул пустую выборку. Попробуйте более
                                        широкую нишу или другой шаблон.
                                    </p>
                                ) : null}
                                {preview.matched === 0 && preview.checked > 0 ? (
                                    <p>
                                        Все карточки выборки отсеялись. Вернитесь к
                                        условиям и ослабьте ограничения, указанные ниже.
                                    </p>
                                ) : null}
                                <ul>
                                    {Object.entries(preview.excluded).map(
                                        ([reason, count]) => (
                                            <li key={reason}>
                                                {reasons[reason] ?? reason}: {count}
                                            </li>
                                        ),
                                    )}
                                </ul>
                                {preview.unknown > 0 ? (
                                    <p>
                                        У {preview.unknown} подходящих карточек часть
                                        выбранных условий нельзя проверить: источник не
                                        указал соответствующие поля.
                                    </p>
                                ) : null}
                                <ul>
                                    {preview.tenders.map((tender, index) => (
                                        <li key={tender.url + index}>
                                            <a
                                                href={tender.url}
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                {tender.title}
                                            </a>
                                            {tender.region ? ` · ${tender.region}` : ''}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}
                        {previewError ? <FieldError>{previewError}</FieldError> : null}
                    </>
                )}
            </fieldset>
            {error ? <FieldError>{error}</FieldError> : null}
            <div className="query-card__actions">
                {step > 0 ? (
                    <Button
                        disabled={saving || loading}
                        onClick={() => {
                            setStep(step - 1);
                            setPreviewError('');
                        }}
                        variant="secondary"
                    >
                        Назад
                    </Button>
                ) : null}
                <Button
                    disabled={
                        saving || loading || !payload || (step >= 3 && !sourceSelected)
                    }
                    type="submit"
                >
                    {saving
                        ? 'Создаём и ищем…'
                        : step === 4
                          ? 'Включить мониторинг'
                          : 'Далее'}
                </Button>
            </div>
        </form>
    );
}
