import '../../css/proposals.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import {
    proposalCategories,
    useProposalAction,
    type ProposalBlock,
} from '../lib/proposals';
import type { PageProps } from '../types';

export default function ProposalLibrary() {
    const { enabled, blocks: initial } =
        usePage<PageProps<{ enabled: boolean; blocks: ProposalBlock[] }>>().props;
    const [blocks, setBlocks] = useState(initial);
    const [editing, setEditing] = useState<ProposalBlock | null | undefined>();
    const [title, setTitle] = useState('');
    const [category, setCategory] = useState('services');
    const [body, setBody] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [deleting, setDeleting] = useState<ProposalBlock | null>(null);
    const action = useProposalAction();
    function edit(block: ProposalBlock | null) {
        setEditing(block);
        setTitle(block?.title ?? '');
        setCategory(block?.category ?? 'services');
        setBody(block?.body ?? '');
        setConfirmed(false);
    }
    async function save() {
        const result = await action.run<{ blocks: ProposalBlock[] }>(
            editing ? 'patch' : 'post',
            editing ? `/proposals/blocks/${editing.id}` : '/proposals/blocks',
            { title, category, body, version: editing?.version ?? 0, confirmed },
            'Материал сохранён.',
        );
        if (result) {
            setBlocks(result.blocks);
            setEditing(undefined);
        }
    }
    async function remove() {
        if (!deleting) return;
        const result = await action.run<{ blocks: ProposalBlock[] }>(
            'delete',
            `/proposals/blocks/${deleting.id}`,
            { version: deleting.version },
            'Блок удалён. Сохранённые черновики остались.',
        );
        if (result) {
            setBlocks(result.blocks);
            setDeleting(null);
        }
    }
    return (
        <>
            <Head title="Библиотека предложений" />
            <AppShell
                title="Предложения"
                eyebrow="Моя библиотека · Pro / Ultra"
                backHref="/profile"
            >
                <section className="proposal-intro">
                    <h2>Ваши материалы для следующих заявок</h2>
                    <p>
                        Сохраните проверенные услуги, опыт, состав работ и условия.
                        Откройте тендер и нажмите «Собрать предложение», чтобы выбрать
                        подходящие блоки.
                    </p>
                    <p>
                        Библиотека и черновики личные. В командной закупке коллеги не
                        видят ваши материалы.
                    </p>
                </section>
                {!enabled ? (
                    <InlineAlert title="Доступно в Pro и Ultra">
                        Для библиотеки нужен действующий тариф.{' '}
                        <Link href="/plans">Мой доступ</Link>
                    </InlineAlert>
                ) : (
                    <>
                        <Button disabled={action.busy} onClick={() => edit(null)}>
                            Добавить блок или шаблон
                        </Button>
                        {blocks.length === 0 ? (
                            <GlassCard className="proposal-card">
                                <h2>Начните с описания услуги</h2>
                                <p>
                                    Добавьте только подтверждённые факты о компании.
                                    Неизвестные цену и сроки можно заполнить в отдельном
                                    предложении.
                                </p>
                            </GlassCard>
                        ) : null}
                        <div className="proposal-library">
                            {blocks.map((block) => (
                                <GlassCard className="proposal-card" key={block.id}>
                                    <span className="proposal-caption">
                                        {proposalCategories[block.category]}
                                    </span>
                                    <h2>{block.title}</h2>
                                    <p className="proposal-text">{block.body}</p>
                                    <div className="proposal-actions">
                                        <Button
                                            variant="secondary"
                                            onClick={() => edit(block)}
                                            disabled={action.busy}
                                        >
                                            Изменить
                                        </Button>
                                        <Button
                                            variant="ghost"
                                            onClick={() => setDeleting(block)}
                                            disabled={action.busy}
                                        >
                                            Удалить
                                        </Button>
                                    </div>
                                </GlassCard>
                            ))}
                        </div>
                    </>
                )}
                {action.error ? (
                    <div role="alert">
                        <InlineAlert title="Не удалось сохранить" tone="danger">
                            {action.error}
                        </InlineAlert>
                    </div>
                ) : null}
                {action.message ? <p role="status">{action.message}</p> : null}
                <BottomSheet
                    open={editing !== undefined}
                    onClose={() => {
                        if (!action.busy) setEditing(undefined);
                    }}
                    title={editing ? 'Изменить материал' : 'Новый материал'}
                >
                    <form
                        className="proposal-form"
                        onSubmit={(event) => {
                            event.preventDefault();
                            void save();
                        }}
                    >
                        <label>
                            Название
                            <input
                                value={title}
                                maxLength={160}
                                required
                                onChange={(e) => setTitle(e.target.value)}
                            />
                        </label>
                        <label>
                            Вид материала
                            <select
                                value={category}
                                onChange={(e) => setCategory(e.target.value)}
                            >
                                {Object.entries(proposalCategories).map(
                                    ([value, label]) => (
                                        <option key={value} value={value}>
                                            {label}
                                        </option>
                                    ),
                                )}
                            </select>
                        </label>
                        <label>
                            Текст
                            <textarea
                                rows={8}
                                value={body}
                                maxLength={8000}
                                required
                                onChange={(e) => setBody(e.target.value)}
                            />
                        </label>
                        {category === 'template' ? (
                            <p>
                                Вставьте {'{{блоки}}'} в месте для выбранных материалов.
                                Поля:{' '}
                                {
                                    '{{компания}}, {{название_закупки}}, {{заказчик}}, {{ссылка}}, {{срок_подачи}}, {{цена_предложения}}, {{срок_исполнения}}, {{контакты}}'
                                }
                                . Незнакомые и незаполненные поля останутся заметными.
                            </p>
                        ) : null}
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                onChange={(e) => setConfirmed(e.target.checked)}
                            />
                            Подтверждаю факты, опыт, сроки и гарантии в этом материале
                        </label>
                        <Button type="submit" disabled={action.busy || !confirmed}>
                            {action.busy ? 'Сохраняем…' : 'Сохранить материал'}
                        </Button>
                        {action.error ? <p role="alert">{action.error}</p> : null}
                    </form>
                </BottomSheet>
                <BottomSheet
                    open={!!deleting}
                    onClose={() => setDeleting(null)}
                    title="Удалить материал?"
                >
                    <div className="proposal-form">
                        {action.error ? <p role="alert">{action.error}</p> : null}
                        <p>
                            «{deleting?.title}» исчезнет из библиотеки. Текст уже
                            собранных черновиков сохранится; они получат отметку об
                            изменении библиотеки.
                        </p>
                        <Button
                            variant="danger"
                            disabled={action.busy}
                            onClick={() => void remove()}
                        >
                            Удалить материал
                        </Button>
                        <Button variant="secondary" onClick={() => setDeleting(null)}>
                            Отмена
                        </Button>
                    </div>
                </BottomSheet>
            </AppShell>
        </>
    );
}
