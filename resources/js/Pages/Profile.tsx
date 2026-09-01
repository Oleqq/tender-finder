import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import {
    Badge,
    Button,
    GlassCard,
    SegmentedControl,
    Toast,
    Toggle,
} from '../Components/ui';
import {
    demoAccessStateOptions,
    demoAccessStates,
    type DemoAccessState,
} from '../lib/demoAccess';

export default function Profile() {
    const [alertsEnabled, setAlertsEnabled] = useState(true);
    const [toastVisible, setToastVisible] = useState(false);
    const [accessState, setAccessState] = useState<DemoAccessState>('preview');
    const access = demoAccessStates[accessState];

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
                        <Badge tone={access.tone}>{access.label}</Badge>
                    </div>
                    <h3>{access.title}</h3>
                    <p>{access.description}</p>
                    <div className="profile-plan__line">
                        <span>Доступ</span>
                        <strong>{access.detail}</strong>
                    </div>
                    <SegmentedControl
                        label="Demo-состояние доступа в профиле"
                        onChange={(value) => setAccessState(value as DemoAccessState)}
                        options={demoAccessStateOptions}
                        value={accessState}
                    />
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
                <GlassCard className="profile-admin-demo" tone="quiet">
                    <span>
                        <Icon name="shield" size={18} /> Пространство владельца
                    </span>
                    <p>
                        Сводная аналитика аудитории, trial и доступа без персональных
                        данных.
                    </p>
                    <Link href="/operations">
                        Открыть аналитику <Icon name="chevron-right" size={16} />
                    </Link>
                </GlassCard>
                <Button
                    className="profile-demo-action"
                    icon="check"
                    onClick={() => setToastVisible(true)}
                    variant="secondary"
                >
                    Сохранить демо-настройки
                </Button>
                <Link className="profile-plans-link" href="/plans">
                    Посмотреть планы и доступ <Icon name="chevron-right" size={17} />
                </Link>
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
