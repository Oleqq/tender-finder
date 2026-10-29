import { usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { PageProps } from '../types';
import {
    clearSavedTextDraft,
    readTextDraft,
    sameTextFields,
    textDraftKey,
    type TextFields,
} from './textDraftStorage';

/** Temporary recovery in this browser tab. Server saves remain explicit. */
export function useTextDraft<T extends TextFields>(
    scope: string,
    saved: T,
    enabled = true,
    revision = '',
) {
    const { auth } = usePage<PageProps>().props;
    const key = auth.user && enabled ? textDraftKey(auth.user.id, scope) : null;
    const [initial] = useState(() => {
        try {
            const record = key
                ? readTextDraft(sessionStorage.getItem(key), saved)
                : null;
            if (!record || sameTextFields(record.fields, saved)) {
                if (key) sessionStorage.removeItem(key);
                return {
                    fields: saved,
                    pending: null,
                    restored: false,
                    unavailable: false,
                };
            }
            const conflict =
                !sameTextFields(record.base, saved) || record.revision !== revision;
            return {
                fields: conflict ? saved : record.fields,
                pending: conflict ? record.fields : null,
                restored: !conflict,
                unavailable: false,
            };
        } catch {
            return { fields: saved, pending: null, restored: false, unavailable: true };
        }
    });
    const [fields, setFields] = useState<T>(initial.fields);
    const [pending, setPending] = useState<T | null>(initial.pending);
    const [restored, setRestored] = useState(initial.restored);
    const [unavailable, setUnavailable] = useState(initial.unavailable);
    const current = useRef(fields);
    const savedRef = useRef(saved);
    const base = useRef(saved);
    const baseRevision = useRef(revision);
    savedRef.current = saved;

    // A refresh of another card may also bring a colleague's newer revision.
    // Keep local edits separate until the user explicitly chooses a version.
    useEffect(() => {
        if (sameTextFields(base.current, saved) && baseRevision.current === revision)
            return;
        const local = current.current;
        if (!sameTextFields(local, base.current) && !sameTextFields(local, saved)) {
            setPending(local);
        }
        current.current = saved;
        base.current = saved;
        baseRevision.current = revision;
        setFields(saved);
        setRestored(false);
    }, [saved, revision]);

    function persist(value: T) {
        if (!key) return;
        try {
            if (sameTextFields(value, savedRef.current)) sessionStorage.removeItem(key);
            else
                sessionStorage.setItem(
                    key,
                    JSON.stringify({
                        schema: 1,
                        base: base.current,
                        fields: value,
                        revision: baseRevision.current,
                        updatedAt: Date.now(),
                    }),
                );
            setUnavailable(false);
        } catch {
            setUnavailable(true);
        }
    }
    function change(update: Partial<T>) {
        if (pending) return;
        const value = { ...current.current, ...update };
        current.current = value;
        setFields(value);
        persist(value);
    }
    function acceptSaved(value: T, submitted = current.current) {
        if (key) {
            try {
                clearSavedTextDraft(sessionStorage, key, submitted);
            } catch {
                setUnavailable(true);
            }
        }
        base.current = value;
        savedRef.current = value;
        if (sameTextFields(current.current, submitted)) {
            current.current = value;
            setFields(value);
            setRestored(false);
            setPending(null);
        }
    }
    function restore() {
        if (!pending) return;
        current.current = pending;
        base.current = savedRef.current;
        baseRevision.current = revision;
        setFields(pending);
        persist(pending);
        setPending(null);
        setRestored(true);
    }
    function discard() {
        current.current = savedRef.current;
        base.current = savedRef.current;
        baseRevision.current = revision;
        setFields(savedRef.current);
        setPending(null);
        setRestored(false);
        persist(savedRef.current);
    }
    return {
        fields,
        change,
        acceptSaved,
        pending,
        restored,
        unavailable,
        restore,
        discard,
        dirty: !sameTextFields(fields, saved),
        enabled: !!key,
    };
}
