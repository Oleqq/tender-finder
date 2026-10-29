export type TextFields = Record<string, string>;
export type StoredTextDraft<T extends TextFields> = {
    schema: 1;
    base: T;
    fields: T;
    revision: string;
    updatedAt: number;
};
export const textDraftPrefix = 'tenderfinder:text-draft:1:';
export const textDraftLifetime = 24 * 60 * 60 * 1000;

export function sameTextFields(a: TextFields, b: TextFields): boolean {
    const keys = Object.keys(a);
    return (
        keys.length === Object.keys(b).length && keys.every((key) => a[key] === b[key])
    );
}

export function readTextDraft<T extends TextFields>(
    raw: string | null,
    shape: T,
    now = Date.now(),
): StoredTextDraft<T> | null {
    if (!raw || raw.length > 2000000) return null;
    try {
        const row = JSON.parse(raw);
        const validFields = (value: unknown): value is T => {
            if (!value || typeof value !== 'object' || Array.isArray(value))
                return false;
            const fields = value as TextFields;
            return (
                Object.keys(fields).length === Object.keys(shape).length &&
                Object.keys(shape).every(
                    (key) =>
                        Object.hasOwn(fields, key) &&
                        typeof fields[key] === 'string' &&
                        fields[key].length <= 50000,
                )
            );
        };
        if (
            row.schema !== 1 ||
            typeof row.revision !== 'string' ||
            row.revision.length > 500 ||
            !Number.isFinite(row.updatedAt) ||
            row.updatedAt > now ||
            now - row.updatedAt >= textDraftLifetime ||
            !validFields(row.base) ||
            !validFields(row.fields)
        )
            return null;
        return row;
    } catch {
        return null;
    }
}

export function textDraftKey(userId: number, scope: string): string {
    return textDraftPrefix + userId + ':' + scope;
}

// A late save response must not erase text entered after that request started.
export function clearSavedTextDraft<T extends TextFields>(
    storage: Pick<Storage, 'getItem' | 'removeItem'>,
    key: string,
    submitted: T,
): void {
    const row = readTextDraft(storage.getItem(key), submitted);
    if (row && sameTextFields(row.fields, submitted)) storage.removeItem(key);
}
