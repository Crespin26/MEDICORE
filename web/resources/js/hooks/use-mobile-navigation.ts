import { useCallback } from 'react';

/** Rétablit les interactions après fermeture de la navigation mobile. */
export function useMobileNavigation() {
    const cleanup = useCallback(() => {
        // Remove pointer-events style from body...
        document.body.style.removeProperty('pointer-events');
    }, []);

    return cleanup;
}
