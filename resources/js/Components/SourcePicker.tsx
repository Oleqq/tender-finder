import '../../css/source-picker.css';

import { tenderSourceOptions } from '../lib/tenderSources';
import { Icon } from './Icon';

export function SourcePicker({
    value,
    onChange,
    available = tenderSourceOptions.map((option) => option.value),
    allLabel = 'Все площадки',
}: {
    value: string[] | null;
    onChange: (value: string[] | null) => void;
    available?: string[];
    allLabel?: string;
}) {
    const options = tenderSourceOptions.filter(
        (option) => available.includes(option.value) || value?.includes(option.value),
    );
    return (
        <div aria-label="Площадки" className="source-picker" role="group">
            <button
                aria-pressed={value === null}
                className="source-picker__all"
                onClick={() => onChange(null)}
                type="button"
            >
                {value === null ? <Icon name="check" size={16} /> : null}
                {allLabel}
            </button>
            <div className="source-picker__options">
                {options.map((option) => {
                    const selected = value?.includes(option.value) ?? false;
                    const connected = available.includes(option.value);
                    return (
                        <button
                            aria-pressed={selected}
                            data-source={option.value}
                            disabled={!connected && !selected}
                            key={option.value}
                            onClick={() =>
                                onChange(
                                    value === null
                                        ? [option.value]
                                        : selected
                                          ? value.filter(
                                                (source) => source !== option.value,
                                            )
                                          : [...value, option.value],
                                )
                            }
                            type="button"
                        >
                            <span className="source-picker__check" aria-hidden="true">
                                {selected ? <Icon name="check" size={14} /> : null}
                            </span>
                            <span>
                                {option.label}
                                {!connected ? <small>Сейчас отключена</small> : null}
                            </span>
                        </button>
                    );
                })}
            </div>
            {value?.length === 0 ? (
                <p className="source-picker__empty">Выберите хотя бы одну площадку.</p>
            ) : null}
        </div>
    );
}
