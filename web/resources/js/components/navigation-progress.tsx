import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

// Inertia 2.0's default progress markup uses the invalid ARIA role "bar".
// Keep an indeterminate indicator with a valid role and accessible name instead.
export function NavigationProgress() {
    const [visible, setVisible] = useState(false);
    useEffect(() => {
        let timer: ReturnType<typeof setTimeout> | undefined;
        const finish = () => {
            clearTimeout(timer);
            setVisible(false);
        };
        const removeStart = router.on('start', () => {
            clearTimeout(timer);
            timer = setTimeout(() => setVisible(true), 250);
        });
        const removeFinish = router.on('finish', finish);
        const removeException = router.on('exception', finish);
        const removeInvalid = router.on('invalid', finish);
        return () => {
            clearTimeout(timer);
            removeStart();
            removeFinish();
            removeException();
            removeInvalid();
        };
    }, []);
    return visible ? (
        <div role="progressbar" aria-label="Chargement de la page" className="bg-primary/20 pointer-events-none fixed inset-x-0 top-0 z-50 h-1">
            <span aria-hidden="true" className="bg-primary block h-full w-1/3 motion-safe:animate-pulse" />
        </div>
    ) : null;
}
