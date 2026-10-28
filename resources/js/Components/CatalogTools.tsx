import { router } from '@inertiajs/react';
import { useRef, useState, type FormEvent } from 'react';
import { BottomSheet, Button, FieldError } from './ui';
import type { CatalogItem } from '../lib/priceCatalog';

export function CatalogStatus({
    error,
    notice,
    conflict,
}: {
    error: string;
    notice: string;
    conflict: boolean;
}) {
    return (
        <div aria-live="polite" className="catalog-status">
            {error ? <FieldError>{error}</FieldError> : null}
            {notice ? <p role="status">{notice}</p> : null}
            {conflict ? (
                <Button variant="secondary" onClick={() => router.reload()}>
                    Обновить данные
                </Button>
            ) : null}
        </div>
    );
}

export function CatalogUpload({
    busy,
    replace = false,
    specification = false,
    onUpload,
}: {
    busy: boolean;
    replace?: boolean;
    specification?: boolean;
    onUpload: (file: File, complete: boolean) => Promise<boolean>;
}) {
    const [file, setFile] = useState<File | null>(null);
    const [approved, setApproved] = useState(false);
    const [complete, setComplete] = useState(false);
    const input = useRef<HTMLInputElement>(null);
    async function submit(event: FormEvent) {
        event.preventDefault();
        if (!file || (replace && !approved)) return;
        if (await onUpload(file, complete)) {
            setFile(null);
            setApproved(false);
            setComplete(false);
            if (input.current) input.current.value = '';
        }
    }
    return (
        <form className="catalog-upload" onSubmit={submit}>
            <fieldset disabled={busy}>
                <label className="form-field">
                    <span>
                        {specification
                            ? 'Спецификация закупки'
                            : 'Файл с товарами или услугами'}
                    </span>
                    <input
                        type="file"
                        accept=".xlsx"
                        required
                        ref={input}
                        onChange={(e) => setFile(e.target.files?.[0] ?? null)}
                    />
                </label>
                <p>
                    Excel .xlsx, до 5 МБ и 500 позиций. Читается первый лист. В первой
                    строке — заголовки из шаблона; формулы замените значениями.
                </p>
                {specification ? (
                    <label className="catalog-checkbox">
                        <input
                            type="checkbox"
                            checked={complete}
                            onChange={(e) => setComplete(e.target.checked)}
                        />
                        Это полный перечень позиций закупки
                    </label>
                ) : null}
                {replace ? (
                    <label className="catalog-checkbox">
                        <input
                            type="checkbox"
                            checked={approved}
                            onChange={(e) => setApproved(e.target.checked)}
                        />
                        Заменить текущий {specification ? 'перечень' : 'прайс'} и
                        пересмотреть прежние подтверждения
                    </label>
                ) : null}
                <Button type="submit" disabled={!file || (replace && !approved)}>
                    {busy
                        ? 'Читаем файл…'
                        : specification
                          ? 'Сравнить спецификацию'
                          : 'Загрузить прайс'}
                </Button>
                <a className="catalog-link" href="/catalog/template">
                    Скачать Excel-шаблон с примерами
                </a>
            </fieldset>
        </form>
    );
}

export type CatalogDraft = Record<
    'name' | 'characteristics' | 'price' | 'quantity' | 'unit' | 'region',
    string
>;
export function CatalogItemEditor({
    item,
    busy,
    error,
    onClose,
    onSave,
}: {
    item: CatalogItem | null;
    busy: boolean;
    error: string;
    onClose: () => void;
    onSave: (draft: CatalogDraft) => Promise<void>;
}) {
    const [draft, setDraft] = useState<CatalogDraft>({
        name: item?.name ?? '',
        characteristics: item?.characteristics ?? '',
        price: item?.price ?? '',
        quantity: item?.quantity ?? '',
        unit: item?.unit ?? '',
        region: item?.region ?? '',
    });
    const fields: Array<[keyof CatalogDraft, string, number]> = [
        ['name', 'Название товара, услуги или компетенции', 300],
        ['characteristics', 'Характеристики', 1500],
        ['price', 'Цена за единицу, ₽', 40],
        ['quantity', 'Доступное количество', 40],
        ['unit', 'Единица измерения', 40],
        ['region', 'Регион', 255],
    ];
    return (
        <BottomSheet
            open
            onClose={() => {
                if (!busy) onClose();
            }}
            title={item ? 'Изменить позицию' : 'Добавить позицию'}
        >
            <form
                className="catalog-editor"
                onSubmit={(e) => {
                    e.preventDefault();
                    void onSave(draft);
                }}
            >
                <fieldset disabled={busy}>
                    {fields.map(([field, label, max]) => (
                        <label className="form-field" key={field}>
                            <span>{label}</span>
                            <input
                                required={field === 'name'}
                                maxLength={max}
                                value={draft[field]}
                                inputMode={
                                    ['price', 'quantity'].includes(field)
                                        ? 'decimal'
                                        : undefined
                                }
                                onChange={(e) =>
                                    setDraft({ ...draft, [field]: e.target.value })
                                }
                            />
                        </label>
                    ))}
                    <p>
                        Неизвестные цену и количество оставьте пустыми. Для услуг можно
                        указать компетенцию; регион «Все регионы» разрешает любой
                        регион.
                    </p>
                    {error ? <FieldError>{error}</FieldError> : null}
                    <div className="catalog-actions">
                        <Button type="submit">
                            {busy ? 'Сохраняем…' : 'Сохранить позицию'}
                        </Button>
                        <Button variant="secondary" onClick={onClose}>
                            Отмена
                        </Button>
                    </div>
                </fieldset>
            </form>
        </BottomSheet>
    );
}
