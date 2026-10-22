import '../../css/requisites.css';

import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Icon } from '../Components/Icon';
import { Badge, Button, GlassCard } from '../Components/ui';
import type { PageProps } from '../types';

type Company = {
    name: string;
    short_name: string;
    legal_address: string;
    inn: string;
    ogrnip: string;
    bank: {
        name: string;
        settlement_account: string;
        bic: string;
        inn: string;
        correspondent_account: string;
        legal_address: string;
    };
};

type Detail = { label: string; value: string; numeric?: boolean };

export default function Requisites() {
    const { company, auth } = usePage<PageProps<{ company: Company }>>().props;
    const [copying, setCopying] = useState(false);
    const [message, setMessage] = useState('');
    const signedIn = auth.user !== null;
    const registration: Detail[] = [
        { label: 'Название организации', value: company.name },
        { label: 'ИНН', value: company.inn, numeric: true },
        { label: 'ОГРНИП', value: company.ogrnip, numeric: true },
        { label: 'Юридический адрес', value: company.legal_address },
    ];
    const bank: Detail[] = [
        { label: 'Банк', value: company.bank.name },
        {
            label: 'Расчётный счёт',
            value: company.bank.settlement_account,
            numeric: true,
        },
        { label: 'БИК банка', value: company.bank.bic, numeric: true },
        { label: 'ИНН банка', value: company.bank.inn, numeric: true },
        {
            label: 'Корреспондентский счёт',
            value: company.bank.correspondent_account,
            numeric: true,
        },
        { label: 'Юридический адрес банка', value: company.bank.legal_address },
    ];

    const copyDetails = async (): Promise<void> => {
        setCopying(true);
        setMessage('');
        try {
            await navigator.clipboard.writeText(
                [...registration, ...bank]
                    .map(({ label, value }) => `${label}: ${value}`)
                    .join('\n'),
            );
            setMessage('Реквизиты скопированы.');
        } catch {
            setMessage(
                'Не удалось скопировать. Выделите нужные данные на странице или скачайте PDF.',
            );
        } finally {
            setCopying(false);
        }
    };

    return (
        <>
            <Head title="Реквизиты" />
            <AppShell
                activeNav="/profile"
                backHref={signedIn ? '/profile' : '/'}
                backToPrevious
                className="requisites-page"
                eyebrow="О сервисе"
                navigationVisible={signedIn}
                title="Реквизиты"
            >
                <GlassCard className="requisites-hero page-enter" tone="accent">
                    <Badge tone="accent">Tender Finder</Badge>
                    <h2>{company.short_name}</h2>
                    <p>Регистрационные и банковские данные сервиса.</p>
                </GlassCard>

                <div className="requisites-actions">
                    <Button disabled={copying} icon="copy" onClick={copyDetails}>
                        {copying ? 'Копируем…' : 'Скопировать всё'}
                    </Button>
                    <a
                        className="button button--secondary button--md"
                        download="Tender-Finder-requisites.pdf"
                        href="/documents/requisites.pdf"
                    >
                        <Icon name="download" size={19} />
                        <span>Скачать PDF</span>
                    </a>
                </div>
                <p aria-live="polite" className="requisites-copy-status" role="status">
                    {message}
                </p>

                <RequisitesSection
                    details={registration}
                    subtitle="Регистрационные данные"
                    title="Организация"
                />
                <RequisitesSection
                    details={bank}
                    subtitle="Банковские данные"
                    title="Банк и счёт"
                />
            </AppShell>
        </>
    );
}

function RequisitesSection({
    details,
    subtitle,
    title,
}: {
    details: Detail[];
    subtitle: string;
    title: string;
}) {
    return (
        <GlassCard className="requisites-section page-enter">
            <header>
                <p>{subtitle}</p>
                <h2>{title}</h2>
            </header>
            <dl className="requisites-details">
                {details.map(({ label, value, numeric }) => (
                    <div key={label}>
                        <dt>{label}</dt>
                        <dd className={numeric ? 'requisites-details__number' : ''}>
                            {value}
                        </dd>
                    </div>
                ))}
            </dl>
        </GlassCard>
    );
}
