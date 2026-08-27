import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { GlassCard } from '../Components/ui';

export default function Consents() {
    const [termsAccepted, setTermsAccepted] = useState(false);
    const [notificationsAccepted, setNotificationsAccepted] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState('');

    const acceptAndStartTrial = async (): Promise<void> => {
        setError('');
        setIsSubmitting(true);

        try {
            await window.axios.post('/consents', {
                documents: ['offer', 'privacy'],
            });
            await window.axios.post('/trial/start');
            router.visit('/dashboard');
        } catch (requestError) {
            const response = (
                requestError as {
                    response?: { status?: number; data?: { message?: string } };
                }
            ).response;

            if (response?.status === 419) {
                // A session may have been regenerated while the Mini App was
                // opening. Reloading obtains a fresh server-rendered CSRF token;
                // no consent or trial request was accepted on a 419 response.
                window.location.assign('/consents');

                return;
            }

            const message = response?.data?.message;

            setError(
                typeof message === 'string' && message.trim() !== ''
                    ? message
                    : 'Не удалось начать trial. Откройте Mini App в Telegram и попробуйте ещё раз.',
            );
        } finally {
            setIsSubmitting(false);
        }
    };

    return (
        <>
            <Head title="Согласия" />
            <AppShell
                backHref="/onboarding"
                navigationVisible={false}
                title="Перед стартом"
                eyebrow="02 / 02"
            >
                <section className="consent-intro page-enter">
                    <span className="consent-intro__icon">
                        <Icon name="shield" size={26} />
                    </span>
                    <h2>
                        Всё прозрачно
                        <br />и под контролем.
                    </h2>
                    <p>
                        После принятия оферты и политики сервер запишет согласия и
                        начнёт ваш единственный trial. Уведомления можно изменить позже.
                    </p>
                </section>
                <GlassCard className="consent-card page-enter page-enter--delay">
                    <ConsentRow checked={termsAccepted} onChange={setTermsAccepted}>
                        Принимаю условия{' '}
                        <Link className="future-link" href="/offer">
                            оферты
                        </Link>{' '}
                        и{' '}
                        <Link className="future-link" href="/privacy">
                            политики конфиденциальности
                        </Link>
                    </ConsentRow>
                    <div className="consent-divider" />
                    <ConsentRow
                        checked={notificationsAccepted}
                        onChange={setNotificationsAccepted}
                        optional
                    >
                        Разрешаю получать уведомления о новых тендерах
                    </ConsentRow>
                </GlassCard>
                <p className="consent-note">
                    Ссылки на документы появятся здесь до публичного запуска.
                </p>
                <div className="consent-actions page-enter page-enter--later">
                    {termsAccepted ? (
                        <button
                            className="button button--primary button--lg"
                            disabled={isSubmitting}
                            onClick={() => void acceptAndStartTrial()}
                            type="button"
                        >
                            <span>
                                {isSubmitting ? 'Запускаем trial…' : 'Начать trial'}
                            </span>
                            <Icon name="arrow-right" size={20} />
                        </button>
                    ) : (
                        <button
                            className="button button--primary button--lg"
                            disabled
                            type="button"
                        >
                            <span>Примите условия</span>
                            <Icon name="arrow-right" size={20} />
                        </button>
                    )}
                    <p>
                        {notificationsAccepted
                            ? 'Согласие на уведомления будет доступно в профиле'
                            : 'Уведомления можно включить позже'}
                    </p>
                </div>
                {error ? <p className="consent-note">{error}</p> : null}
            </AppShell>
        </>
    );
}

function ConsentRow({
    checked,
    onChange,
    children,
    optional = false,
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
    optional?: boolean;
}) {
    return (
        <label className="consent-row">
            <input
                checked={checked}
                onChange={(event) => onChange(event.target.checked)}
                type="checkbox"
            />
            <span aria-hidden="true" className="consent-row__box">
                <Icon name="check" size={15} />
            </span>
            <span>
                {children}
                {optional ? <small>Необязательно</small> : null}
            </span>
        </label>
    );
}
