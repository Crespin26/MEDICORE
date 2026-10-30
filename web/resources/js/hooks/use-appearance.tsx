import { useEffect, useState } from 'react';

export type Appearance = 'light' | 'dark' | 'system';

let currentAppearance: Appearance | undefined;

/** Read a validated preference; browsers may disable persistent storage. */
function savedAppearance(): Appearance {
    try {
        const value = localStorage.getItem('appearance');
        return value === 'light' || value === 'dark' ? value : 'system';
    } catch {
        return 'system';
    }
}

/** Resolve system mode only in the browser so importing this module also works in SSR. */
function applyTheme(appearance: Appearance) {
    if (typeof window === 'undefined') return;
    const isDark = appearance === 'dark' || (appearance === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    document.documentElement.classList.toggle('dark', isDark);
}

let initialized = false;

/** Install the application-wide system listener once; page unmounts must not remove it. */
export function initializeTheme() {
    if (typeof window === 'undefined' || initialized) return;
    initialized = true;
    currentAppearance = savedAppearance();
    applyTheme(currentAppearance);
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => applyTheme(currentAppearance ?? savedAppearance()));
    window.addEventListener('storage', (event) => {
        if (event.key === 'appearance' || event.key === null) {
            currentAppearance = savedAppearance();
            applyTheme(currentAppearance);
            window.dispatchEvent(new Event('appearance-change'));
        }
    });
}

/** Share theme changes between mounted controls while retaining an in-memory fallback. */
export function useAppearance() {
    const [appearance, setAppearance] = useState<Appearance>('system');

    const updateAppearance = (mode: Appearance) => {
        currentAppearance = mode;
        setAppearance(mode);
        try {
            localStorage.setItem('appearance', mode);
        } catch {
            // The current page still supports theme changes without persistent storage.
        }
        applyTheme(mode);
        window.dispatchEvent(new CustomEvent<Appearance>('appearance-change', { detail: mode }));
    };

    useEffect(() => {
        const synchronize = (event: Event) => setAppearance((event as CustomEvent<Appearance>).detail ?? savedAppearance());
        setAppearance(currentAppearance ?? savedAppearance());
        window.addEventListener('appearance-change', synchronize);
        return () => window.removeEventListener('appearance-change', synchronize);
    }, []);

    return { appearance, updateAppearance };
}
