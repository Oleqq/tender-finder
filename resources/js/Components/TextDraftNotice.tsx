import '../../css/text-draft.css';
import type { ReactNode } from 'react';
import { Button } from './ui';
import { Icon } from './Icon';
import type { TextFields } from '../lib/textDraftStorage';

type Recovery = {
    pending: TextFields | null;
    restored: boolean;
    unavailable: boolean;
    dirty: boolean;
    enabled: boolean;
    restore: () => void;
    discard: () => void;
};

export function TextDraftNotice({
    draft,
    onResolve,
    saveLabel = 'Сохранить правки',
    disabled = false,
}: {
    draft: Recovery;
    onResolve?: () => void;
    saveLabel?: string;
    disabled?: boolean;
}) {
    function resolve(action: () => void) {
        onResolve?.();
        action();
    }
    if (!draft.enabled) return null;
    if (draft.pending)
        return (
            <DraftAlert title="Найдены несохранённые правки" tone="warning">
                <p>
                    Сохранённая редакция изменилась. Сравните её с текстом из этой
                    вкладки и выберите, с чем продолжить.
                </p>
                <details>
                    <summary>Посмотреть правки из вкладки</summary>
                    {Object.entries(draft.pending).map(([key, value]) => (
                        <p className="text-draft-notice__text" key={key}>
                            {value}
                        </p>
                    ))}
                </details>
                <div className="text-draft-notice__actions">
                    <Button
                        variant="secondary"
                        disabled={disabled}
                        onClick={() => resolve(draft.restore)}
                    >
                        Восстановить правки
                    </Button>
                    <Button
                        variant="ghost"
                        disabled={disabled}
                        onClick={() => resolve(draft.discard)}
                    >
                        Оставить сохранённую редакцию
                    </Button>
                </div>
                <p>
                    Восстановление меняет только форму. Для записи на сервер сохраните
                    её после проверки.
                </p>
            </DraftAlert>
        );
    if (draft.unavailable && draft.dirty)
        return (
            <DraftAlert
                title="Не удалось сохранить текст в этой вкладке"
                tone="warning"
            >
                Сохраните правки на сервер или скопируйте их перед уходом со страницы.
            </DraftAlert>
        );
    if (!draft.dirty) return null;
    return (
        <div role="status">
            <DraftAlert
                title={
                    draft.restored
                        ? 'Правки восстановлены в форме'
                        : 'Правки сохранены в этой вкладке'
                }
            >
                На сервер они попадут после нажатия «{saveLabel}». Временная копия
                доступна до закрытия вкладки, не дольше суток.
                {draft.restored ? (
                    <div className="text-draft-notice__actions">
                        <Button
                            variant="ghost"
                            disabled={disabled}
                            onClick={() => resolve(draft.discard)}
                        >
                            Отменить восстановленные правки
                        </Button>
                    </div>
                ) : null}
            </DraftAlert>
        </div>
    );
}

function DraftAlert({
    title,
    children,
    tone = 'neutral',
}: {
    title: string;
    children: ReactNode;
    tone?: 'neutral' | 'warning';
}) {
    return (
        <aside
            className={`inline-alert inline-alert--${tone} text-draft-notice`}
            role={tone === 'warning' ? 'alert' : undefined}
        >
            <Icon name={tone === 'warning' ? 'spark' : 'shield'} size={18} />
            <div>
                <strong>{title}</strong>
                <div className="text-draft-notice__content">{children}</div>
            </div>
        </aside>
    );
}
