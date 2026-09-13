import { scopedUrl } from '../lib/workspace';
import axios from 'axios';
import { useState, type FormEvent } from 'react';
import { Badge, Button, GlassCard } from './ui';
import type { TeamScope } from './WorkspacePicker';

export type ParticipationComment = {
    id: number;
    author_id: number | null;
    author_name: string;
    body: string | null;
    version: number;
    edited_at: string | null;
    deleted_at: string | null;
    created_at: string;
    can_edit: boolean;
    can_delete: boolean;
    mentions: Array<{ id: number; name: string }>;
    history: Array<{
        version: number;
        action: string;
        body: string | null;
        editor_name: string;
        created_at: string;
    }>;
};

export function ParticipationComments({
    initial,
    root,
    team,
    members,
    canEdit,
}: {
    initial: ParticipationComment[];
    root: string;
    team: TeamScope['team'];
    members: TeamScope['members'];
    canEdit: boolean;
}) {
    const [comments, setComments] = useState(initial);
    const [body, setBody] = useState('');
    const [mentions, setMentions] = useState<number[]>([]);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');

    const request = async (
        method: 'post' | 'patch' | 'delete',
        path: string,
        data: unknown,
    ): Promise<boolean> => {
        setBusy(true);
        setError('');
        try {
            const response = await window.axios.request<{
                comments: ParticipationComment[];
            }>({ method, url: scopedUrl(root + path, team), data });
            setComments(response.data.comments);
            return true;
        } catch (requestError) {
            setError(
                axios.isAxiosError<{ message?: string }>(requestError)
                    ? (requestError.response?.data.message ??
                          'Не удалось сохранить комментарий.')
                    : 'Не удалось сохранить комментарий.',
            );
            return false;
        } finally {
            setBusy(false);
        }
    };
    const add = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (await request('post', '/comments', { body, mention_ids: mentions })) {
            setBody('');
            setMentions([]);
        }
    };

    return (
        <GlassCard className="work-card comments-card">
            <h2>Обсуждение</h2>
            <p>Комментарии видны участникам выбранного рабочего пространства.</p>
            {comments.length === 0 ? (
                <p className="work-help">Обсуждение ещё не началось.</p>
            ) : (
                <div className="comments-list">
                    {comments.map((comment) => (
                        <CommentRow
                            key={`${comment.id}-${comment.version}`}
                            comment={comment}
                            busy={busy}
                            members={members}
                            team={team}
                            canEdit={canEdit}
                            request={request}
                        />
                    ))}
                </div>
            )}
            {canEdit ? (
                <form className="work-form comment-form" onSubmit={add}>
                    <label>
                        Новый комментарий
                        <textarea
                            required
                            maxLength={4000}
                            disabled={busy}
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                            placeholder="Напишите сообщение команде"
                        />
                    </label>
                    {team ? (
                        <MentionPicker
                            members={members}
                            value={mentions}
                            onChange={setMentions}
                            disabled={busy}
                        />
                    ) : null}
                    <Button disabled={busy} type="submit">
                        {busy ? 'Отправляем…' : 'Добавить комментарий'}
                    </Button>
                </form>
            ) : (
                <p className="work-help">
                    В этом рабочем пространстве обсуждение доступно только для чтения.
                </p>
            )}
            {error ? (
                <p className="work-error" role="alert">
                    {error}
                </p>
            ) : null}
        </GlassCard>
    );
}
function CommentRow({
    comment,
    busy,
    members,
    team,
    canEdit,
    request,
}: {
    comment: ParticipationComment;
    busy: boolean;
    members: TeamScope['members'];
    team: TeamScope['team'];
    canEdit: boolean;
    request: (
        method: 'post' | 'patch' | 'delete',
        path: string,
        data: unknown,
    ) => Promise<boolean>;
}) {
    const [editing, setEditing] = useState(false);
    const [body, setBody] = useState(comment.body ?? '');
    const [mentions, setMentions] = useState(
        comment.mentions.map((mention) => mention.id),
    );
    const save = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        if (
            await request('patch', `/comments/${comment.id}`, {
                body,
                mention_ids: mentions,
                version: comment.version,
            })
        )
            setEditing(false);
    };
    return (
        <article className={`comment-row ${comment.deleted_at ? 'is-deleted' : ''}`}>
            <header>
                <strong>{comment.author_name}</strong>
                <time dateTime={comment.created_at}>
                    {new Date(comment.created_at).toLocaleString('ru-RU')}
                </time>
                {comment.edited_at ? <Badge>изменён</Badge> : null}
            </header>
            {editing ? (
                <form className="work-form" onSubmit={save}>
                    <label>
                        Комментарий
                        <textarea
                            required
                            maxLength={4000}
                            value={body}
                            onChange={(event) => setBody(event.target.value)}
                        />
                    </label>
                    {team ? (
                        <MentionPicker
                            members={members}
                            value={mentions}
                            onChange={setMentions}
                            disabled={busy}
                        />
                    ) : null}
                    <div className="work-actions">
                        <Button disabled={busy} type="submit">
                            Сохранить
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setEditing(false)}
                        >
                            Отмена
                        </Button>
                    </div>
                </form>
            ) : (
                <p>{comment.deleted_at ? 'Комментарий удалён.' : comment.body}</p>
            )}
            {!editing && comment.mentions.length > 0 ? (
                <p className="comment-mentions">
                    Упомянуты:{' '}
                    {comment.mentions.map((mention) => mention.name).join(', ')}
                </p>
            ) : null}
            {!editing && canEdit && (comment.can_edit || comment.can_delete) ? (
                <div className="work-actions">
                    {comment.can_edit ? (
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => setEditing(true)}
                        >
                            Изменить
                        </Button>
                    ) : null}
                    {comment.can_delete ? (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={busy}
                            onClick={() =>
                                void request('delete', `/comments/${comment.id}`, {
                                    version: comment.version,
                                })
                            }
                        >
                            Удалить
                        </Button>
                    ) : null}
                </div>
            ) : null}
            {comment.history.length > 1 ? (
                <details className="comment-history">
                    <summary>История · {comment.history.length} версии</summary>
                    <ol>
                        {comment.history.map((entry) => (
                            <li key={entry.version}>
                                <strong>
                                    {entry.action === 'edited'
                                        ? 'Изменено'
                                        : entry.action === 'deleted'
                                          ? 'Удалено'
                                          : 'Создано'}{' '}
                                    · v{entry.version}
                                </strong>
                                <time>
                                    {new Date(entry.created_at).toLocaleString('ru-RU')}
                                </time>
                                {entry.body ? <p>{entry.body}</p> : null}
                            </li>
                        ))}
                    </ol>
                </details>
            ) : null}
        </article>
    );
}

function MentionPicker({
    members,
    value,
    onChange,
    disabled,
}: {
    members: TeamScope['members'];
    value: number[];
    onChange: (ids: number[]) => void;
    disabled: boolean;
}) {
    return (
        <fieldset className="mention-picker">
            <legend>Упомянуть коллег</legend>
            {members.map((member) => (
                <label key={member.id}>
                    <input
                        type="checkbox"
                        disabled={disabled}
                        checked={value.includes(member.id)}
                        onChange={(event) =>
                            onChange(
                                event.target.checked
                                    ? [...value, member.id]
                                    : value.filter((id) => id !== member.id),
                            )
                        }
                    />
                    {member.name}
                </label>
            ))}
        </fieldset>
    );
}
