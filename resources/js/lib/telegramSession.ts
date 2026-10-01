import { createContext, useContext } from 'react';

export type TelegramSessionState =
    | 'checking'
    | 'ready'
    | 'missing'
    | 'expired'
    | 'failed';

export const TelegramSessionContext = createContext<TelegramSessionState>('checking');

export function useTelegramSessionState(): TelegramSessionState {
    return useContext(TelegramSessionContext);
}
