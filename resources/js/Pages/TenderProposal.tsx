import '../../css/proposals.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard, InlineAlert } from '../Components/ui';
import {
    proposalCategories,
    useProposalAction,
    type ProposalBlock,
    type ProposalDraft,
} from '../lib/proposals';
import type { PageProps } from '../types';

type Props = {
    blocks: ProposalBlock[];
    defaultTemplate: string;
    draft: ProposalDraft | null;
    tender: { id: number; title: string; fingerprint: string };
    teamId: number | null;
    canEdit: boolean;
};
export default function TenderProposal() {
    const {
        blocks,
        draft: initial,
        tender,
        teamId,
        canEdit,
    } = usePage<PageProps<Props>>().props;
    const [draft, setDraft] = useState(initial);
    const [body, setBody] = useState(initial?.body ?? '');
    const [selected, setSelected] = useState<number[]>(initial?.block_ids ?? []);
    const [template, setTemplate] = useState(initial?.template_id ?? 0);
    const [fields, setFields] = useState({
        company: initial?.fields.company ?? '',
        price: initial?.fields.price ?? '',
        delivery: initial?.fields.delivery ?? '',
        contacts: initial?.fields.contacts ?? '',
    });
    const [confirmed, setConfirmed] = useState(false);
    const [reviewed, setReviewed] = useState(false);
    const [replace, setReplace] = useState(false);
    const [copied, setCopied] = useState('');
    const action = useProposalAction();
    const suffix = teamId ? `?team_id=${teamId}` : '';
    const root = `/tenders/${tender.id}/proposal`;
    const missing = [
        ...new Set([...body.matchAll(/\{\{\s*([^{}]+?)\s*\}\}/gu)].map((m) => m[1])),
    ];
    const dirty = body !== (draft?.body ?? '');
    async function compose() {
        const chosen = blocks.find((b) => b.id === template);
        const result = await action.run<{ draft: ProposalDraft }>(
            'post',
            root + '/compose' + suffix,
            {
                version: draft?.version ?? 0,
                tender_hash: tender.fingerprint,
                confirmed,
                blocks: selected.map((id) => {
                    const block = blocks.find((b) => b.id === id)!;
                    return { id, version: block.version };
                }),
                fields,
                template_id: chosen?.id ?? null,
                template_version: chosen?.version ?? null,
            },
            'Черновик собран и сохранён. Проверьте его перед отправкой.',
        );
        if (result) {
            setDraft(result.draft);
            setBody(result.draft.body);
            setConfirmed(false);
            setReplace(false);
            setReviewed(false);
            setCopied('');
        }
    }
    async function save() {
        const result = await action.run<{ draft: ProposalDraft }>(
            'patch',
            root + suffix,
            {
                version: draft?.version ?? 0,
                tender_hash: tender.fingerprint,
                confirmed: reviewed,
                body,
            },
            'Правки сохранены.',
        );
        if (result) {
            setDraft(result.draft);
            setReviewed(false);
            setCopied('');
        }
    }
    async function copy() {
        try {
            await navigator.clipboard.writeText(body);
            setCopied('Текст скопирован. Отправьте его самостоятельно после проверки.');
        } catch {
            setCopied(
                'Копирование недоступно. Выделите текст в поле и скопируйте вручную.',
            );
        }
    }
    return (
        <>
            <Head title="Черновик предложения" />
            <AppShell
                title="Предложение"
                eyebrow="Pro / Ultra · личный черновик"
                backHref={`/tenders/${tender.id}/work${suffix}`}
            >
                <section className="proposal-intro">
                    <h2>{tender.title}</h2>
                    <p>
                        Соберите предложение из проверенных материалов. Цена закупки не
                        подставляется как ваша цена. Неизвестные сведения отмечены
                        фигурными скобками.
                    </p>
                    <Link href="/proposals">Открыть библиотеку блоков и шаблонов</Link>
                </section>
                <GlassCard className="proposal-card">
                    <h2>1. Выберите материалы</h2>
                    {!canEdit ? (
                        <InlineAlert title="Только просмотр">
                            Изменения доступны редакторам действующей команды.
                        </InlineAlert>
                    ) : null}
                    <div className="proposal-form">
                        <label>
                            Шаблон
                            <select
                                value={template}
                                disabled={action.busy}
                                onChange={(e) => setTemplate(Number(e.target.value))}
                            >
                                <option value={0}>Стандартный черновик</option>
                                {blocks
                                    .filter((b) => b.category === 'template')
                                    .map((b) => (
                                        <option key={b.id} value={b.id}>
                                            {b.title}
                                        </option>
                                    ))}
                            </select>
                        </label>
                        {blocks
                            .filter((b) => b.category !== 'template')
                            .map((b) => (
                                <label key={b.id} className="proposal-check">
                                    <input
                                        type="checkbox"
                                        checked={selected.includes(b.id)}
                                        disabled={action.busy}
                                        onChange={(e) =>
                                            setSelected((old) =>
                                                e.target.checked
                                                    ? [...old, b.id]
                                                    : old.filter((id) => id !== b.id),
                                            )
                                        }
                                    />
                                    <span>
                                        <strong>{b.title}</strong>
                                        <small>{proposalCategories[b.category]}</small>
                                    </span>
                                </label>
                            ))}
                        {blocks.filter((b) => b.category !== 'template').length ===
                        0 ? (
                            <p>
                                Добавьте материалы в библиотеку или начните с полей
                                ниже.
                            </p>
                        ) : null}
                        {(
                            [
                                { key: 'company', label: 'Компания / исполнитель' },
                                { key: 'price', label: 'Цена предложения, ₽' },
                                { key: 'delivery', label: 'Срок исполнения' },
                                { key: 'contacts', label: 'Контакты' },
                            ] as const
                        ).map((field) => (
                            <label key={field.key}>
                                {field.label}
                                <input
                                    value={fields[field.key]}
                                    inputMode={
                                        field.key === 'price' ? 'decimal' : 'text'
                                    }
                                    maxLength={1000}
                                    placeholder="Пока неизвестно"
                                    onChange={(e) =>
                                        setFields((old) => ({
                                            ...old,
                                            [field.key]: e.target.value,
                                        }))
                                    }
                                />
                            </label>
                        ))}
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                onChange={(e) => setConfirmed(e.target.checked)}
                            />
                            Я проверил выбранные материалы и введённые условия
                        </label>
                        <Button
                            disabled={action.busy || !confirmed || !canEdit}
                            onClick={() => {
                                if (draft) setReplace(true);
                                else void compose();
                            }}
                        >
                            {action.busy
                                ? 'Обработка…'
                                : draft
                                  ? 'Пересобрать черновик'
                                  : 'Собрать черновик'}
                        </Button>
                    </div>
                </GlassCard>
                {draft ? (
                    <GlassCard className="proposal-card">
                        <Badge tone="warning">
                            Черновик · самостоятельная отправка
                        </Badge>
                        <h2>2. Проверьте и дополните текст</h2>
                        {draft.stale_blocks.length ? (
                            <InlineAlert title="Библиотека изменилась">
                                Изменены или удалены: {draft.stale_blocks.join(', ')}.
                                Сохранённый текст остался прежним. Проверьте его или
                                пересоберите.
                            </InlineAlert>
                        ) : null}
                        {draft.tender_changed ? (
                            <InlineAlert title="Карточка закупки изменилась">
                                Проверьте условия и сохраните актуальный текст перед
                                выгрузкой.
                            </InlineAlert>
                        ) : null}
                        {missing.length ? (
                            <InlineAlert title="Нужно заполнить">
                                {missing.join(', ')}. Можно заполнить прямо в тексте;
                                незаполненный документ не выгружается.
                            </InlineAlert>
                        ) : null}
                        <label className="proposal-form">
                            Текст предложения
                            <textarea
                                className="proposal-draft"
                                rows={18}
                                value={body}
                                maxLength={50000}
                                onChange={(e) => {
                                    setBody(e.target.value);
                                    setCopied('');
                                    setReviewed(false);
                                }}
                            />
                        </label>
                        <label className="proposal-check">
                            <input
                                type="checkbox"
                                checked={reviewed}
                                onChange={(e) => setReviewed(e.target.checked)}
                            />
                            Проверил текст, цену и условия перед сохранением
                        </label>
                        <div className="proposal-actions">
                            <Button
                                disabled={action.busy || !reviewed || !canEdit}
                                onClick={() => void save()}
                            >
                                Сохранить правки
                            </Button>
                            <Button
                                variant="secondary"
                                disabled={
                                    action.busy ||
                                    dirty ||
                                    missing.length > 0 ||
                                    draft.tender_changed
                                }
                                onClick={() => void copy()}
                            >
                                Скопировать текст
                            </Button>
                            {!dirty && missing.length === 0 && !draft.tender_changed ? (
                                <a
                                    className="button button--secondary button--md"
                                    href={`${root}/export?version=${draft.version}${teamId ? `&team_id=${teamId}` : ''}`}
                                >
                                    Скачать .txt
                                </a>
                            ) : null}
                        </div>
                        <p>
                            Перед сохранением подтвердите проверку текста. Выгрузка
                            содержит последний сохранённый текст. Проверьте реквизиты и
                            требования площадки; предложение не отправляется
                            автоматически.
                        </p>
                        {dirty ? <p role="status">Есть несохранённые правки.</p> : null}
                        {copied ? <p role="status">{copied}</p> : null}
                    </GlassCard>
                ) : null}
                {action.error ? (
                    <div role="alert">
                        <InlineAlert title="Не удалось продолжить" tone="danger">
                            {action.error}
                        </InlineAlert>
                    </div>
                ) : null}
                {action.message ? <p role="status">{action.message}</p> : null}
                <BottomSheet
                    open={replace}
                    onClose={() => setReplace(false)}
                    title="Пересобрать предложение?"
                >
                    <div className="proposal-form">
                        {action.error ? <p role="alert">{action.error}</p> : null}
                        <p>
                            Текущий текст, включая ручные правки, будет заменён
                            выбранными блоками и полями. Сначала сохраните копию нужного
                            текста.
                        </p>
                        <Button disabled={action.busy} onClick={() => void compose()}>
                            Заменить текст и пересобрать
                        </Button>
                        <Button variant="secondary" onClick={() => setReplace(false)}>
                            Отмена
                        </Button>
                    </div>
                </BottomSheet>
            </AppShell>
        </>
    );
}
