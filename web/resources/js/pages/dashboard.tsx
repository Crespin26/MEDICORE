import AppLayout from '@/layouts/app-layout';
import { Head, Link } from '@inertiajs/react';
export default function Dashboard({ patientCount }: { patientCount: number }) {
    return (
        <AppLayout breadcrumbs={[{ title: 'Tableau de bord', href: '/dashboard' }]}>
            <Head title="Tableau de bord" />
            <main className="flex flex-col gap-6 p-6">
                <div>
                    <h1 className="text-3xl font-semibold">Bienvenue sur MediCore</h1>
                    <p className="text-muted-foreground mt-2">Votre espace de gestion clinique.</p>
                </div>
                <Link href="/patients" className="hover:bg-muted rounded-xl border p-6">
                    <p>Patients enregistrés</p>
                    <p className="mt-2 text-4xl font-semibold">{patientCount}</p>
                    <p className="mt-4 text-sm underline">Gérer les patients</p>
                </Link>
                <section className="rounded-xl border p-6">
                    <h2 className="font-semibold">En développement</h2>
                    <p className="text-muted-foreground mt-2">Rendez-vous, consultations, dossiers médicaux, facturation et gestion du personnel.</p>
                </section>
            </main>
        </AppLayout>
    );
}
