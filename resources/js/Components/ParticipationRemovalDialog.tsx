import { useState } from 'react';
import { router } from '@inertiajs/react';
import { BottomSheet, Button, FieldError } from './ui';
import {
    changeParticipations,
    participationError,
    type ParticipationTarget,
} from '../lib/participationLifecycle';

export function ParticipationRemovalDialog({
    items,
    team,
    onClose,
    onRemoved,
}: {
    items: ParticipationTarget[];
    team?: { id: number } | null;
    onClose: () => void;
    onRemoved: (items: ParticipationTarget[]) => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const remove = async () => {
        setBusy(true);
        setError('');
        try {
            onRemoved(await changeParticipations('remove', items, team));
        } catch (failure) {
            setError(participationError(failure));
        } finally {
            setBusy(false);
        }
    };
    return (
        <BottomSheet
            open={items.length > 0}
            onClose={() => {
                if (!busy) onClose();
            }}
            title={
                items.length === 1
                    ? 'Удалить заявку?'
                    : `Удалить заявки: ${items.length}?`
            }
        >
            <div className="participation-removal">
                {items.length === 1 && items[0].title ? (
                    <strong>{items[0].title}</strong>
                ) : null}
                <p>
                    Заявки исчезнут из рабочего списка, статистики и календаря.
                    Напоминания будут выключены.
                </p>
                <p>
                    Задачи и документы сохранятся. Восстановить заявки можно в списке
                    «Удалённые».
                </p>
                {team ? <p>Изменение затронет общий список команды.</p> : null}
                {error ? <FieldError>{error}</FieldError> : null}
                {error ? (
                    <Button
                        variant="secondary"
                        disabled={busy}
                        onClick={() => {
                            onClose();
                            router.reload();
                        }}
                    >
                        Обновить список
                    </Button>
                ) : null}
                <div className="work-actions">
                    <Button
                        variant="danger"
                        disabled={busy}
                        onClick={() => void remove()}
                    >
                        {busy ? 'Удаляем…' : 'Удалить'}
                    </Button>
                    <Button variant="secondary" disabled={busy} onClick={onClose}>
                        Отмена
                    </Button>
                </div>
            </div>
        </BottomSheet>
    );
}
