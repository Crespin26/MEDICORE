import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
export default function Exports({
    exports,
    timezone,
}: {
    exports: {
        data: {
            id: number;
            actor_name: string;
            date_from: string;
            date_to: string;
            reason: string;
            row_count: number;
            sha256: string;
            created_at: string;
        }[];
        current_page: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    timezone: string;
}) {
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Tableau de bord', href: '/dashboard' },
                { title: 'Journal des exports', href: '/administration/exports' },
            ]}
        >
            <Head title="Journal des exports" />
            <main className="flex flex-col gap-6 p-6">
                <h1 className="text-3xl font-semibold">Journal des exports statistiques</h1>
                <p className="text-muted-foreground">
                    Exports agrégés sans identité patient ni données cliniques. L’empreinte permet de vérifier le fichier téléchargé ; aucune copie du
                    CSV n’est conservée ici.
                </p>
                <div className="space-y-4">
                    {exports.data.map((item) => (
                        <details className="rounded-xl border p-4" key={item.id}>
                            <summary className="cursor-pointer">
                                Export #{item.id} · {item.actor_name} · {new Date(item.created_at).toLocaleString('fr-FR', { timeZone: timezone })}
                            </summary>
                            <p className="mt-3">
                                Du {item.date_from} au {item.date_to} · {item.row_count} lignes
                            </p>
                            <p className="mt-2 break-words whitespace-pre-wrap">{item.reason}</p>
                            <p className="text-muted-foreground mt-3 text-sm break-all">SHA-256 : {item.sha256}</p>
                        </details>
                    ))}
                </div>
                {!exports.data.length && <p>Aucun export enregistré.</p>}
                <nav aria-label="Journal des exports" className="flex gap-4">
                    {exports.prev_page_url && <Link href={exports.prev_page_url}>Précédent</Link>}
                    <span>Page {exports.current_page}</span>
                    {exports.next_page_url && <Link href={exports.next_page_url}>Suivant</Link>}
                </nav>
            </main>
        </AppLayout>
    );
}
