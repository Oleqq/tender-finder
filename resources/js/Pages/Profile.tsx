import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, Button, GlassCard, Toast, Toggle } from '../Components/ui';

export default function Profile() {
    const [alertsEnabled, setAlertsEnabled] = useState(true);
    const [toastVisible, setToastVisible] = useState(false);

    return (
        <>
            <Head title="Профиль" />
            <AppShell activeNav="/profile" eyebrow="Аккаунт" title="Профиль">
                <section className="profile-hero page-enter">
                    <span className="profile-avatar">TF</span>
                    <div>
                        <Badge tone="accent">Демо-профиль</Badge>
                        <h2>
                            Ваш рабочий
                            <br />
                            контур.
                        </h2>
                        <p>
                            Telegram-профиль будет подключён после серверной проверки
                            initData.
                        </p>
                    </div>
                </section>

                <GlassCard
                    className="profile-plan page-enter page-enter--delay"
                    tone="accent"
                >
                    <div className="profile-plan__header">
                        <span>
                            <Icon name="spark" size={19} /> Статус доступа
                        </span>
                        <Badge tone="success">будущий trial</Badge>
                    </div>
                    <h3>72 часа, чтобы оценить фокус</h3>
                    <p>
                        Однократный trial начнёт отсчёт только после безопасной
                        серверной активации.
                    </p>
                    <div className="profile-plan__line">
                        <span>Доступ</span>
                        <strong>Ещё не активирован</strong>
                    </div>
                </GlassCard>

                <section className="profile-section page-enter page-enter--later">
                    <div className="section-heading">
                        <div>
                            <p>Настройки</p>
                            <h2>Ваши сигналы</h2>
                        </div>
                    </div>
                    <GlassCard className="settings-list">
                        <Toggle
                            checked={alertsEnabled}
                            description="Новые совпадения и важные сроки"
                            label="Уведомления в Telegram"
                            onChange={setAlertsEnabled}
                        />
                        <div className="settings-divider" />
                        <button
                            className="settings-row"
                            onClick={() => setToastVisible(true)}
                            type="button"
                        >
                            <span>
                                <strong>Часовой пояс</strong>
                                <small>Определим по Telegram</small>
                            </span>
                            <span>
                                <Icon name="chevron-right" size={19} />
                            </span>
                        </button>
                    </GlassCard>
                </section>

                <section className="profile-help page-enter page-enter--later">
                    <Icon name="shield" size={18} />
                    <p>
                        Клиентские данные Telegram не используются как подтверждение
                        личности.
                    </p>
                </section>
                <Button
                    className="profile-demo-action"
                    icon="check"
                    onClick={() => setToastVisible(true)}
                    variant="secondary"
                >
                    Сохранить демо-настройки
                </Button>
                <Toast
                    message={
                        alertsEnabled
                            ? 'Уведомления включены в демо-сессии'
                            : 'Уведомления приостановлены в демо-сессии'
                    }
                    visible={toastVisible}
                />
            </AppShell>
        </>
    );
}
