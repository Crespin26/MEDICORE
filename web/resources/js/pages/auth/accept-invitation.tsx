import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AuthLayout from '@/layouts/auth-layout';
import { Head, useForm } from '@inertiajs/react';
import { type FormEvent } from 'react';
/** Active un compte à partir de son invitation à usage unique et d’un mot de passe confirmé. */
export default function AcceptInvitation({ token }: { token: string }) {
    const form = useForm({ password: '', password_confirmation: '' });
    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.post(`/invitations/${token}`, { onFinish: () => form.reset() });
    };
    return (
        <AuthLayout title="Activer votre compte" description="Choisissez un mot de passe d’au moins 12 caractères pour rejoindre MediCore.">
            <Head title="Activation du compte" />
            <form onSubmit={submit} className="flex flex-col gap-6">
                <div>
                    <label htmlFor="password" className="mb-2 block">
                        Mot de passe
                    </label>
                    <Input
                        id="password"
                        type="password"
                        autoComplete="new-password"
                        minLength={12}
                        maxLength={72}
                        required
                        value={form.data.password}
                        onChange={(e) => form.setData('password', e.target.value)}
                    />
                    <InputError message={form.errors.password} />
                </div>
                <div>
                    <label htmlFor="password_confirmation" className="mb-2 block">
                        Confirmer le mot de passe
                    </label>
                    <Input
                        id="password_confirmation"
                        type="password"
                        autoComplete="new-password"
                        minLength={12}
                        maxLength={72}
                        required
                        value={form.data.password_confirmation}
                        onChange={(e) => form.setData('password_confirmation', e.target.value)}
                    />
                </div>
                <Button disabled={form.processing}>Activer mon compte</Button>
            </form>
        </AuthLayout>
    );
}
