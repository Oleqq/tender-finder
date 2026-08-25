import { Head } from '@inertiajs/react';
import { AppShell } from '../Components/AppShell';
import { InlineAlert } from '../Components/ui';

type LegalDocumentProps = {
    document: {
        type: 'offer' | 'privacy';
        title: string;
    };
};

const drafts = {
    offer: {
        eyebrow: 'Legal draft / требует проверки юристом',
        sections: [
            {
                title: 'Назначение документа',
                paragraphs: [
                    'Этот текст — общий черновик условий доступа к Tender Finder для первой закрытой beta. Он не является опубликованной офертой, пока ИП не заполнит реквизиты и текст не проверит юрист.',
                    'Сервис помогает пользователю настраивать мониторинги закупок и получать уведомления в Telegram. Trial предоставляется один раз на 72 часа и допускает до трёх активных мониторингов.',
                ],
            },
            {
                title: 'Границы первой beta',
                paragraphs: [
                    'В beta нет Telegram Stars, оплаты, возвратов, обещаний выигрыша тендера и автоматического анализа ТЗ. Данные RSS и уведомления включаются только после отдельной проверки источника ЕИС.',
                    'Перед публикацией юрист должен определить порядок изменения условий, ограничение ответственности, применимое право, реквизиты ИП и канал для юридически значимых обращений.',
                ],
            },
        ],
    },
    privacy: {
        eyebrow: 'Legal draft / требует проверки юристом',
        sections: [
            {
                title: 'Какие данные фактически нужны сервису',
                paragraphs: [
                    'Код Tender Finder обрабатывает только проверенный Telegram ID, минимальные отображаемые поля Telegram, время последнего визита, события принятия или отзыва документов с HMAC-хешем IP, настройки мониторингов и технический журнал доставки уведомлений.',
                    'Сервис не хранит bot token, raw Telegram initData, cookies, полные webhook payload, содержимое личных сообщений Telegram, платёжные данные, документы ТЗ или LLM-prompts.',
                ],
            },
            {
                title: 'Что нужно подтвердить до публикации',
                paragraphs: [
                    'Юрист и ИП должны заполнить цели и правовые основания обработки, сроки хранения, порядок удаления и обращения пользователей, реквизиты оператора, контакты и условия работы с провайдерами VPS, PostgreSQL, Redis и Telegram.',
                    'Для первой beta инфраструктура и постоянные данные выбираются в РФ. Перед публикацией отдельно проверяются требования к передаче данных через Telegram и обязанности оператора персональных данных.',
                ],
            },
        ],
    },
} satisfies Record<
    LegalDocumentProps['document']['type'],
    { eyebrow: string; sections: Array<{ title: string; paragraphs: string[] }> }
>;

export default function LegalDocument({ document }: LegalDocumentProps) {
    const draft = drafts[document.type];

    return (
        <>
            <Head title={document.title} />
            <AppShell
                backHref="/"
                navigationVisible={false}
                title={document.title}
                eyebrow={draft.eyebrow}
            >
                <InlineAlert title="Черновик не опубликован" tone="warning">
                    Этот текст нельзя использовать для принятия условий или запуска
                    trial. Он станет публичным юридическим документом только после
                    заполнения реквизитов ИП и проверки юристом.
                </InlineAlert>
                <article className="legal-document page-enter">
                    {draft.sections.map((section) => (
                        <section key={section.title}>
                            <h2>{section.title}</h2>
                            {section.paragraphs.map((paragraph) => (
                                <p key={paragraph}>{paragraph}</p>
                            ))}
                        </section>
                    ))}
                </article>
            </AppShell>
        </>
    );
}
