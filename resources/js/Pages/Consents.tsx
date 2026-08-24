import { Head, Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { GlassCard } from '../Components/ui';

export default function Consents() {
    const [termsAccepted, setTermsAccepted] = useState(false);
    const [notificationsAccepted, setNotificationsAccepted] = useState(false);

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
                        Вы сможете изменить настройки уведомлений в профиле. Серверное
                        сохранение согласий будет добавлено после подключения
                        постоянного хранилища.
                    </p>
                </section>
                <GlassCard className="consent-card page-enter page-enter--delay">
                    <ConsentRow checked={termsAccepted} onChange={setTermsAccepted}>
                        Принимаю условия <span className="future-link">оферты</span> и{' '}
                        <span className="future-link">политики конфиденциальности</span>
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
                        <Link
                            className="button button--primary button--lg"
                            href="/dashboard"
                        >
                            <span>Перейти к обзору</span>
                            <Icon name="arrow-right" size={20} />
                        </Link>
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
                            ? 'Уведомления включены в демо-сценарии'
                            : 'Уведомления можно включить позже'}
                    </p>
                </div>
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
