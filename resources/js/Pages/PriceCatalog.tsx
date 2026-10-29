import '../../css/price-catalog.css';
import { Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { AppShell } from '../Components/AppShell';
import { Badge, BottomSheet, Button, GlassCard } from '../Components/ui';
import {
    CatalogItemEditor,
    CatalogStatus,
    CatalogUpload,
    type CatalogDraft,
} from '../Components/CatalogTools';
import {
    catalogMoney,
    useCatalogAction,
    type Catalog,
    type CatalogItem,
} from '../lib/priceCatalog';
import type { PageProps } from '../types';

type Recommendations = {
    examined: number;
    limited: boolean;
    total_found: number;
    items: Array<{
        id: number;
        title: string;
        source_label: string;
        region: string | null;
        hits: Array<{ id: number; name: string; terms: string[] }>;
    }>;
};
export default function PriceCatalogPage() {
    const { enabled, catalog: initial } =
        usePage<PageProps<{ enabled: boolean; catalog: Catalog }>>().props;
    const [catalog, setCatalog] = useState(initial);
    const [editing, setEditing] = useState<CatalogItem | null | undefined>();
    const [deleting, setDeleting] = useState<CatalogItem | 'all' | null>(null);
    const [search, setSearch] = useState('');
    const [visible, setVisible] = useState(50);
    const [results, setResults] = useState<Recommendations | null>(null);
    const action = useCatalogAction();
    const filtered = catalog.items.filter((item) =>
        `${item.name} ${item.characteristics ?? ''}`
            .toLocaleLowerCase('ru')
            .includes(search.toLocaleLowerCase('ru')),
    );
    function changed(next: Catalog) {
        setCatalog(next);
        setResults(null);
    }
    async function save(draft: CatalogDraft) {
        const response = await action.run(
            () =>
                window.axios
                    .request<{ catalog: Catalog }>({
                        method: editing ? 'patch' : 'post',
                        url: editing
                            ? `/catalog/items/${editing.id}`
                            : '/catalog/items',
                        data: { ...draft, version: catalog.version },
                    })
                    .then((r) => r.data),
            'Позиция сохранена.',
        );
        if (response) {
            changed(response.catalog);
            setEditing(undefined);
        }
    }
    async function remove() {
        const response = await action.run(
            () =>
                window.axios
                    .delete<{
                        catalog: Catalog;
                    }>(
                        deleting === 'all'
                            ? '/catalog'
                            : `/catalog/items/${deleting?.id}`,
                        { data: { version: catalog.version } },
                    )
                    .then((r) => r.data),
            'Прайс обновлён.',
        );
        if (response) {
            changed(response.catalog);
            setDeleting(null);
        }
    }
    return (
        <>
            <Head title="Мой прайс" />
            <AppShell
                activeNav="/profile"
                backHref="/profile"
                title="Мой прайс"
                eyebrow="Товары и компетенции"
                className="feature-page catalog-page"
            >
                <GlassCard className="catalog-section" tone="accent">
                    <Badge tone="accent">Pro · Ultra</Badge>
                    <h2>Тендеры под ваши возможности</h2>
                    <p>
                        Загрузите товары или услуги и найдите связанные закупки в своих
                        мониторингах. Прайс виден только вам.
                    </p>
                </GlassCard>
                {!enabled ? (
                    <GlassCard className="catalog-section">
                        <h2>Доступно в Pro и Ultra</h2>
                        <p>
                            Нужна действующая подписка Pro или Ultra. Сохранённый
                            каталог станет доступен при восстановлении доступа.
                        </p>
                        <a className="catalog-link" href="/pricing">
                            Тарифы и цены
                        </a>
                    </GlassCard>
                ) : (
                    <>
                        <CatalogStatus {...action} />
                        <GlassCard className="catalog-section">
                            <h2>
                                {catalog.items.length
                                    ? 'Обновить прайс'
                                    : 'Начните со своего каталога'}
                            </h2>
                            <p>
                                Название обязательно. Характеристики, цена в рублях,
                                доступное количество, единица и регион — по возможности.
                                Примеры в шаблоне замените своими данными.
                            </p>
                            {catalog.filename ? (
                                <p>Последний файл: {catalog.filename}</p>
                            ) : null}
                            <CatalogUpload
                                busy={action.busy}
                                replace={catalog.items.length > 0}
                                onUpload={async (file) => {
                                    const data = new FormData();
                                    data.append('file', file);
                                    data.append('version', String(catalog.version));
                                    const response = await action.run(
                                        () =>
                                            window.axios
                                                .post<{
                                                    catalog: Catalog;
                                                }>('/catalog/import', data)
                                                .then((r) => r.data),
                                        'Прайс загружен. Проверьте позиции ниже.',
                                    );
                                    if (response) changed(response.catalog);
                                    return response !== null;
                                }}
                            />
                        </GlassCard>
                        <GlassCard className="catalog-section">
                            <div className="catalog-heading">
                                <div>
                                    <h2>Позиции · {catalog.items.length} / 500</h2>
                                    <p>
                                        Пустое количество означает, что оно неизвестно.
                                    </p>
                                </div>
                                <Button
                                    disabled={
                                        action.busy || catalog.items.length >= 500
                                    }
                                    variant="secondary"
                                    onClick={() => setEditing(null)}
                                >
                                    Добавить позицию
                                </Button>
                            </div>
                            <label className="form-field">
                                <span>Поиск по прайсу</span>
                                <input
                                    value={search}
                                    onChange={(e) => {
                                        setSearch(e.target.value);
                                        setVisible(50);
                                    }}
                                    placeholder="Товар, услуга или характеристика"
                                />
                            </label>
                            <div className="catalog-item-list">
                                {filtered.slice(0, visible).map((item) => (
                                    <article className="catalog-item" key={item.id}>
                                        <div>
                                            <h3>{item.name}</h3>
                                            <p>
                                                {item.characteristics ||
                                                    'Характеристики не указаны'}
                                            </p>
                                            <p>
                                                {catalogMoney(item.price)} ·{' '}
                                                {item.quantity === null
                                                    ? 'Количество не указано'
                                                    : Number(
                                                          item.quantity,
                                                      ).toLocaleString('ru-RU')}{' '}
                                                {item.unit
                                                    ? `${item.quantity === null ? '· Единица: ' : ''}${item.unit}`
                                                    : ''}{' '}
                                                · {item.region ?? 'Регион не указан'}
                                            </p>
                                        </div>
                                        <div className="catalog-actions">
                                            <Button
                                                size="sm"
                                                variant="secondary"
                                                disabled={action.busy}
                                                onClick={() => setEditing(item)}
                                            >
                                                Изменить
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                disabled={action.busy}
                                                onClick={() => setDeleting(item)}
                                            >
                                                Удалить
                                            </Button>
                                        </div>
                                    </article>
                                ))}
                            </div>
                            {!filtered.length ? (
                                <p>
                                    Позиций пока нет или ничего не найдено по вашему
                                    запросу.
                                </p>
                            ) : null}
                            {filtered.length > visible ? (
                                <Button
                                    variant="secondary"
                                    onClick={() => setVisible(visible + 50)}
                                >
                                    Показать ещё 50
                                </Button>
                            ) : null}
                            {catalog.items.length ? (
                                <Button
                                    variant="ghost"
                                    disabled={action.busy}
                                    onClick={() => setDeleting('all')}
                                >
                                    Очистить прайс
                                </Button>
                            ) : null}
                        </GlassCard>
                        <GlassCard className="catalog-section">
                            <h2>Подобрать тендеры</h2>
                            <p>
                                Сопоставляем слова из названий прайса с названием и
                                описанием последних 300 доступных закупок ваших
                                мониторингов. Показываем до 20 результатов. Это
                                предварительный подбор; соответствие требованиям нужно
                                проверить.
                            </p>
                            <Button
                                disabled={action.busy || !catalog.items.length}
                                onClick={async () => {
                                    const response = await action.run(() =>
                                        window.axios
                                            .get<Recommendations>(
                                                '/catalog/recommendations',
                                            )
                                            .then((r) => r.data),
                                    );
                                    if (response) setResults(response);
                                }}
                            >
                                {action.busy
                                    ? 'Проверяем…'
                                    : 'Найти тендеры под мой прайс'}
                            </Button>
                            {results ? (
                                <>
                                    <p role="status">
                                        Проверено закупок: {results.examined}. Найдено:{' '}
                                        {results.total_found}.
                                        {results.limited
                                            ? ' Проверены последние 300 карточек.'
                                            : ''}
                                    </p>
                                    {!results.items.length ? (
                                        <p>
                                            Совпадений в доступных карточках нет.
                                            Проверьте названия позиций и{' '}
                                            <Link href="/queries">
                                                настройки мониторингов
                                            </Link>
                                            .
                                        </p>
                                    ) : null}
                                    <div className="catalog-item-list">
                                        {results.items.map((tender) => (
                                            <article
                                                className="catalog-item"
                                                key={tender.id}
                                            >
                                                <Badge>{tender.source_label}</Badge>
                                                <h3>
                                                    <Link
                                                        href={`/catalog/tenders/${tender.id}`}
                                                    >
                                                        {tender.title}
                                                    </Link>
                                                </h3>
                                                <p>
                                                    {tender.region ??
                                                        'Регион не передан'}
                                                </p>
                                                <ul>
                                                    {tender.hits.map((hit) => (
                                                        <li key={hit.id}>
                                                            {hit.name} · совпали слова:{' '}
                                                            {hit.terms.join(', ')}
                                                        </li>
                                                    ))}
                                                </ul>
                                                <Link
                                                    className="catalog-link"
                                                    href={`/catalog/tenders/${tender.id}`}
                                                >
                                                    Проверить соответствие →
                                                </Link>
                                            </article>
                                        ))}
                                    </div>
                                </>
                            ) : null}
                        </GlassCard>
                    </>
                )}
            </AppShell>
            {editing !== undefined ? (
                <CatalogItemEditor
                    key={editing?.id ?? 'new'}
                    item={editing}
                    busy={action.busy}
                    error={action.error}
                    onClose={() => setEditing(undefined)}
                    onSave={save}
                />
            ) : null}
            <BottomSheet
                open={deleting !== null}
                onClose={() => {
                    if (!action.busy) setDeleting(null);
                }}
                title={deleting === 'all' ? 'Очистить прайс?' : 'Удалить позицию?'}
            >
                <div className="catalog-editor">
                    <p>
                        {deleting === 'all'
                            ? 'Все позиции будут удалены. После загрузки нового прайса потребуется заново проверить подтверждения.'
                            : deleting?.name}
                    </p>
                    <CatalogStatus {...action} />
                    <div className="catalog-actions">
                        <Button
                            variant="danger"
                            disabled={action.busy}
                            onClick={() => void remove()}
                        >
                            Удалить
                        </Button>
                        <Button
                            variant="secondary"
                            disabled={action.busy}
                            onClick={() => setDeleting(null)}
                        >
                            Отмена
                        </Button>
                    </div>
                </div>
            </BottomSheet>
        </>
    );
}
