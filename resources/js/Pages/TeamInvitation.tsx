import { Head, router, usePage } from '@inertiajs/react';
import axios from 'axios';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Button, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';
import '../../css/tender-work.css';

export default function TeamInvitation() {
    const { invitation, auth } = usePage<
        PageProps<{
            invitation: { team_name: string; role: string; token: string };
        }>
    >().props;
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    return (
        <>
            <Head title="Приглашение в команду" />
            <AppShell title="Приглашение" className="work-page">
                <GlassCard className="work-card">
                    <h2>{invitation.team_name}</h2>
                    <p>
                        Вас приглашают как{' '}
                        {invitation.role === 'viewer'
                            ? 'наблюдателя с правом просмотра'
                            : 'участника с правом редактирования'}
                        .
                    </p>
                    <p>Личные заявки, заметки и задачи останутся личными.</p>
                    {error && <p role="alert">{error}</p>}
                    {auth.user ? (
                        <Button
                            disabled={busy}
                            onClick={async () => {
                                setBusy(true);
                                try {
                                    const r = await window.axios.post(
                                        `/team-invitations/${invitation.token}`,
                                    );
                                    router.get('/teams', { team_id: r.data.team_id });
                                } catch (e) {
                                    setError(
                                        axios.isAxiosError(e)
                                            ? (e.response?.data.message ??
                                                  'Приглашение недействительно.')
                                            : 'Проверьте соединение.',
                                    );
                                    setBusy(false);
                                }
                            }}
                        >
                            Присоединиться
                        </Button>
                    ) : (
                        <p>
                            Войдите в TenderFinder через Telegram, затем откройте эту
                            ссылку снова.
                        </p>
                    )}
                </GlassCard>
            </AppShell>
        </>
    );
}
