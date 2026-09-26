import axios from 'axios';
import { useRef, useState, type FormEvent } from 'react';
import { scopedUrl } from '../lib/workspace';
import type { ChecklistItem, ParticipationDocument } from '../lib/participation';
import { AssigneeSelect, type TeamScope } from './WorkspacePicker';
import { Badge, Button, GlassCard } from './ui';

const types = [
    ['requirement', 'Требование закупки'],
    ['proposal', 'Предложение'],
    ['qualification', 'Квалификация'],
    ['security', 'Обеспечение'],
    ['contract', 'Договор'],
    ['other', 'Другое'],
] as const;
const statuses = [
    ['needed', 'Нужен'],
    ['ready', 'Готов'],
    ['replace_required', 'Требует замены'],
] as const;

type Request = (
    method: 'post' | 'patch' | 'delete',
    path: string,
    data: unknown,
) => Promise<boolean>;

export function ParticipationDocuments({
    initial,
    root,
    team,
    members,
    tasks,
    canEdit,
}: {
    initial: ParticipationDocument[];
    root: string;
    team: TeamScope['team'];
    members: TeamScope['members'];
    tasks: ChecklistItem[];
    canEdit: boolean;
}) {
    const [documents, setDocuments] = useState(initial);
    const [title, setTitle] = useState('');
    const [type, setType] = useState<ParticipationDocument['type']>('requirement');
    const [status, setStatus] = useState<ParticipationDocument['status']>('needed');
    const [assignee, setAssignee] = useState<number | null>(null);
    const [task, setTask] = useState<number | null>(null);
    const [sourceUrl, setSourceUrl] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const fileInput = useRef<HTMLInputElement>(null);

    const request: Request = async (method, path, data) => {
        setBusy(true);
        setError('');
        try {
            const response = await window.axios.request<{
                documents: ParticipationDocument[];
            }>({ method, url: scopedUrl(root + path, team), data });
            setDocuments(response.data.documents);
            return true;
        } catch (requestError) {
            setError(errorMessage(requestError));
            return false;
        } finally {
            setBusy(false);
        }
    };

    const create = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        const data = new FormData();
        data.append('title', title);
        data.append('type', type);
        data.append('status', status);
        if (assignee !== null) data.append('assignee_id', String(assignee));
        if (task !== null) data.append('checklist_item_id', String(task));
        if (sourceUrl.trim()) data.append('source_url', sourceUrl.trim());
        if (file) data.append('file', file);
        if (await request('post', '/documents', data)) {
            setTitle('');
            setSourceUrl('');
            setFile(null);
            setTask(null);
            if (fileInput.current) fileInput.current.value = '';
        }
    };

    return (
        <GlassCard className="work-card participation-documents">
            <div>
                <h2>Документы заявки</h2>
                <p>
                    Рабочие файлы хранятся приватно. Ссылки открываются только по явному
                    действию, а замены остаются в истории.
                </p>
            </div>
            {error ? <p className="work-error">{error}</p> : null}
            {documents.length === 0 ? (
                <p>Добавьте требование, рабочий файл или ссылку на документ.</p>
            ) : (
                <div className="document-list">
                    {documents.map((document) => (
                        <DocumentCard
                            key={`${document.id}-${document.version}`}
                            document={document}
                            tasks={tasks}
                            members={members}
                            team={team}
                            busy={busy}
                            canEdit={canEdit}
                            request={request}
                        />
                    ))}
                </div>
            )}
            <form className="work-form document-create" onSubmit={create}>
                <h3>Новый документ</h3>
                <label>
                    Название
                    <input
                        required
                        maxLength={240}
                        value={title}
                        disabled={busy || !canEdit}
                        onChange={(event) => setTitle(event.target.value)}
                        placeholder="Например, декларация соответствия"
                    />
                </label>
                <div className="document-fields">
                    <label>
                        Тип
                        <select
                            value={type}
                            disabled={busy || !canEdit}
                            onChange={(event) =>
                                setType(
                                    event.target.value as ParticipationDocument['type'],
                                )
                            }
                        >
                            {types.map(([value, text]) => (
                                <option key={value} value={value}>
                                    {text}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label>
                        Состояние
                        <select
                            value={status}
                            disabled={busy || !canEdit}
                            onChange={(event) =>
                                setStatus(
                                    event.target
                                        .value as ParticipationDocument['status'],
                                )
                            }
                        >
                            {statuses.map(([value, text]) => (
                                <option key={value} value={value}>
                                    {text}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                {team ? (
                    <AssigneeSelect
                        label="Ответственный"
                        value={assignee}
                        onChange={setAssignee}
                        members={members}
                        disabled={busy || !canEdit}
                    />
                ) : null}
                <TaskSelect
                    value={task}
                    onChange={setTask}
                    tasks={tasks}
                    disabled={busy || !canEdit}
                />
                <label>
                    Рабочий файл — до 10 МБ
                    <input
                        ref={fileInput}
                        type="file"
                        accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt,.zip"
                        disabled={busy || !canEdit || Boolean(sourceUrl)}
                        onChange={(event) => setFile(event.target.files?.[0] ?? null)}
                    />
                </label>
                <label>
                    Или HTTPS-ссылка
                    <input
                        type="url"
                        maxLength={2000}
                        value={sourceUrl}
                        disabled={busy || !canEdit || Boolean(file)}
                        onChange={(event) => setSourceUrl(event.target.value)}
                        placeholder="https://…"
                    />
                </label>
                <Button
                    type="submit"
                    disabled={busy || !canEdit || documents.length >= 100}
                >
                    Добавить документ
                </Button>
            </form>
        </GlassCard>
    );
}

function DocumentCard({
    document,
    tasks,
    members,
    team,
    busy,
    canEdit,
    request,
}: {
    document: ParticipationDocument;
    tasks: ChecklistItem[];
    members: TeamScope['members'];
    team: TeamScope['team'];
    busy: boolean;
    canEdit: boolean;
    request: Request;
}) {
    const [editing, setEditing] = useState(false);
    const [replacing, setReplacing] = useState(false);
    const [title, setTitle] = useState(document.title);
    const [type, setType] = useState(document.type);
    const [status, setStatus] = useState(document.status);
    const [assignee, setAssignee] = useState(document.assignee_id);
    const [task, setTask] = useState(document.checklist_item_id);
    const [replacementUrl, setReplacementUrl] = useState('');
    const [replacementFile, setReplacementFile] = useState<File | null>(null);
    const latest = document.versions[0] ?? null;

    const save = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (
            await request('patch', `/documents/${document.id}`, {
                title,
                type,
                status,
                assignee_id: assignee,
                checklist_item_id: task,
                version: document.version,
            })
        )
            setEditing(false);
    };
    const replace = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        const data = new FormData();
        data.append('version', String(document.version));
        if (replacementFile) data.append('file', replacementFile);
        if (replacementUrl.trim()) data.append('source_url', replacementUrl.trim());
        if (await request('post', `/documents/${document.id}/versions`, data)) {
            setReplacing(false);
            setReplacementFile(null);
            setReplacementUrl('');
        }
    };

    return (
        <article
            className={`document-card ${document.archived_at ? 'is-archived' : ''}`}
        >
            <header>
                <div>
                    <Badge
                        tone={
                            document.status === 'ready'
                                ? 'success'
                                : document.status === 'replace_required'
                                  ? 'warning'
                                  : 'accent'
                        }
                    >
                        {label(statuses, document.status)}
                    </Badge>
                    {document.archived_at ? <Badge>Архив</Badge> : null}
                </div>
                <strong>{document.title}</strong>
                <small>{label(types, document.type)}</small>
            </header>
            {team ? (
                <p>
                    Ответственный:{' '}
                    {members.find((member) => member.id === document.assignee_id)
                        ?.name ?? 'Не назначен'}
                </p>
            ) : null}
            {document.checklist_item_id ? (
                <p>
                    Задача:{' '}
                    {tasks.find((item) => item.id === document.checklist_item_id)
                        ?.title ?? 'Удалена'}
                </p>
            ) : null}
            {latest ? (
                <VersionLink version={latest} />
            ) : (
                <p>Файл или ссылка ещё не добавлены.</p>
            )}
            {document.versions.length > 1 ? (
                <details className="document-history">
                    <summary>История замен · {document.versions.length}</summary>
                    <ol>
                        {document.versions.map((version) => (
                            <li key={version.id}>
                                <VersionLink version={version} />
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
            {editing ? (
                <form className="work-form" onSubmit={save}>
                    <label>
                        Название
                        <input
                            required
                            maxLength={240}
                            value={title}
                            onChange={(event) => setTitle(event.target.value)}
                        />
                    </label>
                    <div className="document-fields">
                        <label>
                            Тип
                            <select
                                value={type}
                                onChange={(event) =>
                                    setType(
                                        event.target
                                            .value as ParticipationDocument['type'],
                                    )
                                }
                            >
                                {types.map(([value, text]) => (
                                    <option key={value} value={value}>
                                        {text}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label>
                            Состояние
                            <select
                                value={status}
                                onChange={(event) =>
                                    setStatus(
                                        event.target
                                            .value as ParticipationDocument['status'],
                                    )
                                }
                            >
                                {statuses.map(([value, text]) => (
                                    <option key={value} value={value}>
                                        {text}
                                    </option>
                                ))}
                            </select>
                        </label>
                    </div>
                    {team ? (
                        <AssigneeSelect
                            label="Ответственный"
                            value={assignee}
                            onChange={setAssignee}
                            members={members}
                            disabled={busy}
                        />
                    ) : null}
                    <TaskSelect
                        value={task}
                        onChange={setTask}
                        tasks={tasks}
                        disabled={busy}
                    />
                    <div className="work-actions">
                        <Button type="submit" disabled={busy}>
                            Сохранить
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setEditing(false)}
                        >
                            Отмена
                        </Button>
                    </div>
                </form>
            ) : null}
            {replacing ? (
                <form className="work-form" onSubmit={replace}>
                    <label>
                        Новая версия файла
                        <input
                            type="file"
                            accept=".pdf,.doc,.docx,.xls,.xlsx,.png,.jpg,.jpeg,.txt,.zip"
                            disabled={Boolean(replacementUrl)}
                            onChange={(event) =>
                                setReplacementFile(event.target.files?.[0] ?? null)
                            }
                        />
                    </label>
                    <label>
                        Или HTTPS-ссылка
                        <input
                            type="url"
                            value={replacementUrl}
                            disabled={Boolean(replacementFile)}
                            onChange={(event) => setReplacementUrl(event.target.value)}
                        />
                    </label>
                    <div className="work-actions">
                        <Button
                            type="submit"
                            disabled={
                                busy || (!replacementFile && !replacementUrl.trim())
                            }
                        >
                            Сохранить версию
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            onClick={() => setReplacing(false)}
                        >
                            Отмена
                        </Button>
                    </div>
                </form>
            ) : null}
            {canEdit && !editing && !replacing ? (
                <div className="work-actions">
                    {!document.archived_at ? (
                        <>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setEditing(true)}
                            >
                                Изменить
                            </Button>
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setReplacing(true)}
                            >
                                Новая версия
                            </Button>
                        </>
                    ) : null}
                    <Button
                        type="button"
                        variant="ghost"
                        disabled={busy}
                        onClick={() =>
                            void request('patch', `/documents/${document.id}/archive`, {
                                archived: !document.archived_at,
                                version: document.version,
                            })
                        }
                    >
                        {document.archived_at ? 'Восстановить' : 'В архив'}
                    </Button>
                    {document.archived_at ? (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => {
                                if (
                                    window.confirm(
                                        'Удалить документ и все сохранённые версии?',
                                    )
                                )
                                    void request(
                                        'delete',
                                        `/documents/${document.id}`,
                                        { version: document.version },
                                    );
                            }}
                        >
                            Удалить
                        </Button>
                    ) : null}
                </div>
            ) : null}
        </article>
    );
}

function TaskSelect({
    value,
    onChange,
    tasks,
    disabled,
}: {
    value: number | null;
    onChange: (value: number | null) => void;
    tasks: ChecklistItem[];
    disabled: boolean;
}) {
    return (
        <label>
            Связанная задача
            <select
                value={value ?? ''}
                disabled={disabled}
                onChange={(event) =>
                    onChange(event.target.value ? Number(event.target.value) : null)
                }
            >
                <option value="">Без задачи</option>
                {tasks.map((item) => (
                    <option key={item.id} value={item.id}>
                        {item.title}
                    </option>
                ))}
            </select>
        </label>
    );
}

function VersionLink({
    version,
}: {
    version: ParticipationDocument['versions'][number];
}) {
    const href =
        version.source_kind === 'file' ? version.download_url : version.source_url;
    return (
        <div className="document-version">
            <a
                href={href ?? '#'}
                target={version.source_kind === 'link' ? '_blank' : undefined}
                rel="noreferrer"
            >
                {version.original_name ??
                    (version.source_kind === 'link'
                        ? 'Открыть ссылку'
                        : 'Скачать файл')}
            </a>
            <small>
                {new Date(version.created_at).toLocaleString('ru-RU')}
                {version.uploaded_by ? ` · ${version.uploaded_by}` : ''}
                {version.size_bytes ? ` · ${formatBytes(version.size_bytes)}` : ''}
            </small>
        </div>
    );
}

function label<T extends readonly (readonly [string, string])[]>(
    items: T,
    value: string,
): string {
    return items.find(([key]) => key === value)?.[1] ?? value;
}

function formatBytes(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.ceil(bytes / 1024)} КБ`
        : `${(bytes / 1024 / 1024).toFixed(1)} МБ`;
}

function errorMessage(error: unknown): string {
    if (
        axios.isAxiosError<{ message?: string; errors?: Record<string, string[]> }>(
            error,
        )
    ) {
        return (
            Object.values(error.response?.data.errors ?? {})
                .flat()
                .join(' ') ||
            error.response?.data.message ||
            'Не удалось сохранить документ.'
        );
    }
    return 'Не удалось сохранить документ. Проверьте соединение.';
}
