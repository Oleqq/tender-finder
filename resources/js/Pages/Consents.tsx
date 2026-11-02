import { Head, router } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { GlassCard } from '../Components/ui';

export default function Consents({
    temporaryFullAccessEnabled = false,
}: {
    temporaryFullAccessEnabled?: boolean;
}) {
    const [offerAccepted, setOfferAccepted] = useState(false);
    const [privacyAccepted, setPrivacyAccepted] = useState(false);
    const [consentAccepted, setConsentAccepted] = useState(false);
    const [isSubmitting, setIsSubmitting] = useState(false);
    const [error, setError] = useState('');
    const canContinue = offerAccepted && privacyAccepted && consentAccepted;

    const acceptAndContinue = async (): Promise<void> => {
        if (!canContinue || isSubmitting) {
            return;
        }

        setError('');
        setIsSubmitting(true);

        try {
            await window.axios.post('/consents', {
                documents: ['terms', 'offer', 'privacy', 'consent'],
            });
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
                    : 'Не удалось подтвердить условия. Обновите страницу и попробуйте ещё раз.',
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
                        {temporaryFullAccessEnabled
                            ? 'Мы запишем принятие оферты и согласие на обработку персональных данных, а затем откроем полный доступ на время проверки.'
                            : 'Мы запишем принятие оферты и согласие на обработку персональных данных, а затем сразу включим ваш единственный пробный период.'}
                    </p>
                </section>
                <GlassCard className="consent-card page-enter page-enter--delay">
                    <ConsentRow checked={offerAccepted} onChange={setOfferAccepted}>
                        Принимаю условия{' '}
                        <a
                            className="future-link"
                            href="/terms"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            пользовательского соглашения
                        </a>{' '}
                        и{' '}
                        <a
                            className="future-link"
                            href="/offer"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            оферты
                        </a>
                    </ConsentRow>
                    <div className="consent-divider" />
                    <ConsentRow checked={privacyAccepted} onChange={setPrivacyAccepted}>
                        Ознакомился с{' '}
                        <a
                            className="future-link"
                            href="/privacy"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            политикой обработки данных
                        </a>
                    </ConsentRow>
                    <div className="consent-divider" />
                    <ConsentRow checked={consentAccepted} onChange={setConsentAccepted}>
                        Даю отдельное{' '}
                        <a
                            className="future-link"
                            href="/consent"
                            target="_blank"
                            rel="noopener noreferrer"
                        >
                            согласие на обработку персональных данных
                        </a>
                    </ConsentRow>
                </GlassCard>
                <p className="consent-note">
                    Согласие на обработку данных оформляется отдельно от условий
                    использования. Версии всех четырёх документов сохраняются на
                    сервере.
                </p>
                <div className="consent-actions page-enter page-enter--later">
                    {canContinue ? (
                        <button
                            className="button button--primary button--lg"
                            disabled={isSubmitting}
                            onClick={() => void acceptAndContinue()}
                            type="button"
                        >
                            <span>
                                {isSubmitting
                                    ? 'Открываем доступ…'
                                    : temporaryFullAccessEnabled
                                      ? 'Открыть доступ'
                                      : 'Начать 3 дня бесплатно'}
                            </span>
                            <Icon name="arrow-right" size={20} />
                        </button>
                    ) : (
                        <button
                            className="button button--primary button--lg"
                            disabled
                            type="button"
                        >
                            <span>Подтвердите условия и согласие</span>
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
