import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
export default function Dashboard({ patientCount }: { patientCount: number | null }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Tableau de bord', href: '/dashboard' }]}>
            <Head title="Tableau de bord" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Bienvenue sur MediCore</h1>
                    <p className="text-muted-foreground mt-2">Votre espace de gestion clinique.</p>
                </div>
                {patientCount !== null && (
                    <Link href="/patients" className="hover:bg-muted rounded-xl border p-6">
                        <p>Patients enregistrés</p>
                        <p className="mt-2 text-4xl font-semibold">{patientCount}</p>
                        <p className="mt-4 text-sm underline">Gérer les patients</p>
                    </Link>
                )}
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">En développement</h2>
                    <p className="text-muted-foreground mt-2">Indicateurs administratifs complémentaires et paramètres de l’établissement.</p>
                </section>
            </main>
        </AppLayout>
    );
}
