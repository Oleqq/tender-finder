import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { Access } from '../types';
import { presentAccess } from './accessPresentation';

export function useAccessPresentation(access: Access | null) {
    const [now, setNow] = useState(() => Date.now());
    const reloadedExpired = useRef(false);

    useEffect(() => {
        const update = () => {
            const current = Date.now();
            setNow(current);
            if (
                access?.state === 'trialing' &&
                access.ends_at &&
                current >= new Date(access.ends_at).getTime() &&
                !reloadedExpired.current
            ) {
                reloadedExpired.current = true;
                router.reload({ only: ['auth'] });
            }
        };
        const interval = window.setInterval(update, 30_000);
        return () => window.clearInterval(interval);
    }, [access?.ends_at, access?.state]);

    return presentAccess(access, new Date(now));
}
