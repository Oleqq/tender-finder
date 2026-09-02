import { Head, Link, usePage } from '@inertiajs/react';
import { type FormEvent, useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, Button, GlassCard, SelectField } from '../Components/ui';
import { presentAccess } from '../lib/accessPresentation';
import type { PageProps } from '../types';

type NotificationPreferences = {
    instant_enabled: boolean;
    digest_enabled: boolean;
    digest_time: string;
    timezone: string;
};

type ProfilePageProps = PageProps<{
    notificationPreferences: NotificationPreferences;
}>;

export default function Profile() {
    const { auth, notificationPreferences } = usePage<ProfilePageProps>().props;
    const [preferences, setPreferences] = useState(notificationPreferences);
    const [saving, setSaving] = useState(false);
    const [message, setMessage] = useState('');
    const access = presentAccess(auth.access);
    const isSuperAdmin = auth.user?.role === 'super_admin';
    const initials = (auth.user?.name ?? 'Tender Finder')
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part.charAt(0))
        .join('')
        .toUpperCase();

    const savePreferences = async (event: FormEvent): Promise<void> => {
        event.preventDefault();
        setSaving(true);
        setMessage('');
        try {
            const response = await window.axios.put<{
                preferences: NotificationPreferences;
            }>('/profile/notification-preferences', preferences);
            setPreferences(response.data.preferences);
            setMessage('Настройки сохранены. Реальная отправка пока не включена.');
        } catch {
            setMessage('Не удалось сохранить настройки. Проверьте время дайджеста.');
        } finally {
            setSaving(false);
        }
    };

    return (
        <>
            <Head title="Профиль" />
            <AppShell activeNav="/profile" eyebrow="Аккаунт" title="Профиль">
                <section className="profile-hero page-enter">
                    <span className="profile-avatar">{initials}</span>
                    <div>
                        <Badge tone={isSuperAdmin ? 'success' : 'accent'}>
                            {isSuperAdmin ? 'Расширенный доступ' : 'Аккаунт'}
                        </Badge>
                        <h2>{auth.user?.name ?? 'Ваш профиль'}</h2>
                        <p>Роль и доступ подтверждаются серверной сессией.</p>
                    </div>
                </section>

                <section className="profile-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Уведомления</p>
                            <h2>Расписание и предпросмотр</h2>
                        </div>
                    </div>
                    <GlassCard>
                        <form
                            className="notification-settings"
                            onSubmit={savePreferences}
                        >
                            <label className="notification-settings__toggle">
                                <span>
                                    <strong>Мгновенные уведомления</strong>
                                    <small>
                                        По одному сообщению о новом совпадении
                                    </small>
                                </span>
                                <input
                                    checked={preferences.instant_enabled}
                                    onChange={(event) =>
                                        setPreferences((current) => ({
                                            ...current,
                                            instant_enabled: event.target.checked,
                                        }))
                                    }
                                    type="checkbox"
                                />
                            </label>
                            <label className="notification-settings__toggle">
                                <span>
                                    <strong>Ежедневный дайджест</strong>
                                    <small>Сводка найденных за сутки карточек</small>
                                </span>
                                <input
                                    checked={preferences.digest_enabled}
                                    onChange={(event) =>
                                        setPreferences((current) => ({
                                            ...current,
                                            digest_enabled: event.target.checked,
                                        }))
                                    }
                                    type="checkbox"
                                />
                            </label>
                            <div className="notification-settings__schedule">
                                <label className="form-field">
                                    <span>Время дайджеста</span>
                                    <input
                                        disabled={!preferences.digest_enabled}
                                        onChange={(event) =>
                                            setPreferences((current) => ({
                                                ...current,
                                                digest_time: event.target.value,
                                            }))
                                        }
                                        type="time"
                                        value={preferences.digest_time}
                                    />
                                </label>
                                <SelectField
                                    label="Часовой пояс"
                                    onChange={(event) =>
                                        setPreferences((current) => ({
                                            ...current,
                                            timezone: event.target.value,
                                        }))
                                    }
                                    options={[
                                        {
                                            value: 'Europe/Moscow',
                                            label: 'Москва (UTC+3)',
                                        },
                                        {
                                            value: 'Asia/Yekaterinburg',
                                            label: 'Екатеринбург (UTC+5)',
                                        },
                                        {
                                            value: 'Asia/Novosibirsk',
                                            label: 'Новосибирск (UTC+7)',
                                        },
                                        {
                                            value: 'Asia/Vladivostok',
                                            label: 'Владивосток (UTC+10)',
                                        },
                                    ]}
                                    value={preferences.timezone}
                                />
                            </div>
                            <Button disabled={saving} type="submit">
                                {saving ? 'Сохраняем…' : 'Сохранить настройки'}
                            </Button>
                            {message ? (
                                <p className="notification-settings__message">
                                    {message}
                                </p>
                            ) : null}
                        </form>
                    </GlassCard>
                    <NotificationPreview preferences={preferences} />
                </section>

                <GlassCard
                    className="profile-plan page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="profile-plan__header">
                        <span>
                            <Icon name="spark" size={19} /> Статус доступа
                        </span>
                        <Badge tone={access.tone}>{access.badge}</Badge>
                    </div>
                    <h3>{access.title}</h3>
                    <p>{access.description}</p>
                    <div className="profile-plan__line">
                        <span>Период</span>
                        <strong>{access.detail}</strong>
                    </div>
                </GlassCard>

                <section className="profile-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Аккаунт</p>
                            <h2>Данные и доступ</h2>
                        </div>
                    </div>
                    <GlassCard className="settings-list">
                        <div className="settings-row">
                            <span>
                                <strong>Безопасность сессии</strong>
                                <small>
                                    Telegram ID и технические данные не показываются в
                                    интерфейсе.
                                </small>
                            </span>
                            <Icon name="shield" size={19} />
                        </div>
                    </GlassCard>
                </section>

                <section className="profile-help page-enter page-enter--later">
                    <Icon name="shield" size={18} />
                    <p>
                        Доступ рассчитывается на сервере. Этот экран не изменяет его
                        локальными настройками.
                    </p>
                </section>

                {isSuperAdmin ? (
                    <GlassCard className="profile-admin" tone="quiet">
                        <span>
                            <Icon name="shield" size={18} /> Инструменты владельца
                        </span>
                        <p>
                            Поиск ЕИС и агрегированная аналитика продукта без
                            персональных данных.
                        </p>
                        <div>
                            <Link href="/mvp/workspace">
                                Поиск ЕИС <Icon name="chevron-right" size={16} />
                            </Link>
                            <Link href="/operations">
                                Открыть аналитику{' '}
                                <Icon name="chevron-right" size={16} />
                            </Link>
                        </div>
                    </GlassCard>
                ) : null}
                <Link className="profile-plans-link" href="/plans">
                    Подробнее о доступе <Icon name="chevron-right" size={17} />
                </Link>
            </AppShell>
        </>
    );
}

function NotificationPreview({
    preferences,
}: {
    preferences: NotificationPreferences;
}) {
    return (
        <GlassCard className="notification-preview" tone="quiet">
            <div className="notification-preview__heading">
                <Badge tone="accent">Пример</Badge>
                <span>Сообщение не отправляется</span>
            </div>
            {preferences.instant_enabled ? (
                <div className="notification-preview__message">
                    <strong>Новая закупка по мониторингу «Разработка сайтов»</strong>
                    <p>Разработка и сопровождение корпоративного портала</p>
                    <span>НМЦК 1 250 000 ₽ · срок подачи 12 сентября</span>
                </div>
            ) : null}
            {preferences.digest_enabled ? (
                <div className="notification-preview__message">
                    <strong>Дайджест за сутки · 5 новых закупок</strong>
                    <p>
                        3 мониторинга дали новые совпадения. Откройте ленту для
                        просмотра.
                    </p>
                    <span>Запланировано на {preferences.digest_time}</span>
                </div>
            ) : null}
            {!preferences.instant_enabled && !preferences.digest_enabled ? (
                <p>
                    Оба типа уведомлений отключены — сообщения формироваться не будут.
                </p>
            ) : null}
        </GlassCard>
    );
}
