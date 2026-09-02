import { Head, Link, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { GlassCard } from '../Components/ui';

export default function Consents() {
    const [offerAccepted, setOfferAccepted] = useState(false);
    const [privacyAccepted, setPrivacyAccepted] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState('');
    const canStartTrial = offerAccepted && privacyAccepted;

    const acceptAndStartTrial = async (): Promise<void> => {
        if (!canStartTrial || isSubmitting) {
            return;
        }

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

            if (response?.status === 401) {
                router.visit('/onboarding');

                return;
            }

            const message = response?.data?.message;

            setError(
                typeof message === 'string' && message.trim() !== ''
                    ? message
                    : 'Не удалось начать пробный период. Откройте Mini App в Telegram и попробуйте ещё раз.',
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
                        Сервер отдельно запишет принятие оферты и согласие на обработку
                        персональных данных, а затем начнёт ваш единственный пробный
                        период.
                    </p>
                </section>
                <GlassCard className="consent-card page-enter page-enter--delay">
                    <ConsentRow checked={offerAccepted} onChange={setOfferAccepted}>
                        Принимаю условия{' '}
                        <Link className="future-link" href="/offer">
                            оферты
                        </Link>
                    </ConsentRow>
                    <div className="consent-divider" />
                    <ConsentRow checked={privacyAccepted} onChange={setPrivacyAccepted}>
                        Даю согласие на обработку персональных данных на условиях{' '}
                        <Link className="future-link" href="/privacy">
                            политики обработки данных
                        </Link>
                    </ConsentRow>
                </GlassCard>
                <p className="consent-note">
                    Согласия независимы: оферта задаёт условия доступа, политика —
                    обработку персональных данных. Их версии сохраняются на сервере.
                </p>
                <div className="consent-actions page-enter page-enter--later">
                    {canStartTrial ? (
                        <button
                            className="button button--primary button--lg"
                            disabled={isSubmitting}
                            onClick={() => void acceptAndStartTrial()}
                            type="button"
                        >
                            <span>
                                {isSubmitting
                                    ? 'Запускаем пробный период…'
                                    : 'Начать пробный период'}
                            </span>
                            <Icon name="arrow-right" size={20} />
                        </button>
                    ) : (
                        <button
                            className="button button--primary button--lg"
                            disabled
                            type="button"
                        >
                            <span>Примите оба условия</span>
                            <Icon name="arrow-right" size={20} />
                        </button>
                    )}
                    <p>Уведомления не включаются этим действием.</p>
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
}: {
    checked: boolean;
    onChange: (checked: boolean) => void;
    children: ReactNode;
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
            <span>{children}</span>
        </label>
    );
}
