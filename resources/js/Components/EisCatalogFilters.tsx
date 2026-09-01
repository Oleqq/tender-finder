import type { FormEvent } from 'react';
import { useState } from 'react';
import { Button, FieldError } from './ui';

export type EisRegionOption = { code: string; name: string };
export type EisOkpd2Option = { id: string; code: string; name: string };

export function EisCatalogFilters({
    regions,
    selectedRegions,
    onRegionsChange,
    selectedOkpd2,
    onOkpd2Change,
    okpd2WithNested,
    onOkpd2WithNestedChange,
}: {
    regions: EisRegionOption[];
    selectedRegions: EisRegionOption[];
    onRegionsChange: (regions: EisRegionOption[]) => void;
    selectedOkpd2: EisOkpd2Option[];
    onOkpd2Change: (items: EisOkpd2Option[]) => void;
    okpd2WithNested: boolean;
    onOkpd2WithNestedChange: (value: boolean) => void;
}) {
    const [regionCode, setRegionCode] = useState('');
    const [okpd2Search, setOkpd2Search] = useState('');
    const [okpd2Options, setOkpd2Options] = useState<EisOkpd2Option[]>([]);
    const [isSearching, setIsSearching] = useState(false);
    const [error, setError] = useState('');

    const addRegion = (): void => {
        const option = regions.find((item) => item.code === regionCode);

        if (!option || selectedRegions.some((item) => item.code === option.code)) {
            return;
        }

        if (selectedRegions.length >= 5) {
            setError('ЕИС разрешает выбрать не более пяти регионов.');
            return;
        }

        onRegionsChange([...selectedRegions, option]);
        setRegionCode('');
        setError('');
    };

    const searchOkpd2 = async (event: FormEvent<HTMLFormElement>): Promise<void> => {
        event.preventDefault();
        const search = okpd2Search.trim();

        if (search.length < 3) {
            setError('Введите минимум три символа кода или названия ОКПД2.');
            return;
        }

        setIsSearching(true);
        setError('');

        try {
            const response = await window.axios.get<{ options: EisOkpd2Option[] }>(
                '/local/mvp/eis/okpd2-options',
                { params: { search } },
            );
            setOkpd2Options(response.data.options);

            if (response.data.options.length === 0) {
                setError('В справочнике ЕИС ничего не найдено. Уточните код или название.');
            }
        } catch (requestError) {
            const message = (
                requestError as {
                    response?: { data?: { errors?: Record<string, string[]> } };
                }
            ).response?.data?.errors?.search?.[0];
            setError(message ?? 'Не удалось получить справочник ОКПД2 ЕИС.');
        } finally {
            setIsSearching(false);
        }
    };

    const addOkpd2 = (option: EisOkpd2Option): void => {
        if (selectedOkpd2.some((item) => item.id === option.id)) {
            return;
        }

        if (selectedOkpd2.length >= 5) {
            setError('ЕИС разрешает выбрать не более пяти значений ОКПД2.');
            return;
        }

        onOkpd2Change([...selectedOkpd2, option]);
        setError('');
    };

    return (
        <div className="eis-catalog-filters">
            <div className="eis-catalog-filter">
                <strong>Регионы поставки</strong>
                <div className="eis-catalog-filter__picker">
                    <label className="form-field">
                        <span>Субъект РФ из КЛАДР ЕИС</span>
                        <select
                            onChange={(event) => setRegionCode(event.target.value)}
                            value={regionCode}
                        >
                            <option value="">Выберите регион</option>
                            {regions.map((region) => (
                                <option key={region.code} value={region.code}>
                                    {region.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    <Button
                        disabled={!regionCode || selectedRegions.length >= 5}
                        onClick={addRegion}
                        size="sm"
                        variant="secondary"
                    >
                        Добавить
                    </Button>
                </div>
                <SelectedItems
                    items={selectedRegions.map((region) => ({
                        key: region.code,
                        label: region.name,
                    }))}
                    onRemove={(code) =>
                        onRegionsChange(selectedRegions.filter((item) => item.code !== code))
                    }
                />
            </div>

            <div className="eis-catalog-filter">
                <strong>ОКПД2</strong>
                <form className="eis-catalog-filter__lookup" onSubmit={searchOkpd2}>
                    <label className="form-field">
                        <span>Код или название</span>
                        <input
                            onChange={(event) => setOkpd2Search(event.target.value)}
                            placeholder="например, 62.01 или разработка ПО"
                            value={okpd2Search}
                        />
                    </label>
                    <Button disabled={isSearching} size="sm" type="submit" variant="secondary">
                        {isSearching ? 'Ищем…' : 'Найти код'}
                    </Button>
                </form>
                {okpd2Options.length > 0 ? (
                    <div className="eis-okpd2-options" role="list">
                        {okpd2Options.map((option) => (
                            <button
                                disabled={selectedOkpd2.some((item) => item.id === option.id)}
                                key={option.id}
                                onClick={() => addOkpd2(option)}
                                type="button"
                            >
                                <strong>{option.code}</strong>
                                <span>{option.name}</span>
                            </button>
                        ))}
                    </div>
                ) : null}
                <SelectedItems
                    items={selectedOkpd2.map((item) => ({
                        key: item.id,
                        label: `${item.code} · ${item.name}`,
                    }))}
                    onRemove={(id) =>
                        onOkpd2Change(selectedOkpd2.filter((item) => item.id !== id))
                    }
                />
                {selectedOkpd2.length > 0 ? (
                    <label className="eis-catalog-filter__nested">
                        <input
                            checked={okpd2WithNested}
                            onChange={(event) =>
                                onOkpd2WithNestedChange(event.target.checked)
                            }
                            type="checkbox"
                        />
                        Искать с учётом вложенных кодов
                    </label>
                ) : null}
            </div>
            {error ? <FieldError>{error}</FieldError> : null}
        </div>
    );
}

function SelectedItems({
    items,
    onRemove,
}: {
    items: Array<{ key: string; label: string }>;
    onRemove: (key: string) => void;
}) {
    if (items.length === 0) {
        return null;
    }

    return (
        <div className="eis-catalog-filter__selected">
            {items.map((item) => (
                <span key={item.key}>
                    {item.label}
                    <button
                        aria-label={`Убрать ${item.label}`}
                        onClick={() => onRemove(item.key)}
                        type="button"
                    >
                        ×
                    </button>
                </span>
            ))}
        </div>
    );
}
