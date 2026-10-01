import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, LoaderCircle, LockKeyhole } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface LoginForm {
    [key: string]: string | boolean;
    email: string;
    password: string;
    remember: boolean;
}

interface LoginProps {
    status?: string;
    canResetPassword: boolean;
}

export default function Login({ status, canResetPassword }: LoginProps) {
    const { data, setData, post, processing, errors, reset } = useForm<LoginForm>({
        email: '',
        password: '',
        remember: false,
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('login'), {
            onFinish: () => reset('password'),
        });
    };

    return (
        <AuthLayout title="Welcome back" description="Sign in with your school account to continue.">
            <Head title="Log in" />

            {status && <div role="status" className="mb-5 border-l-2 border-[#42784c] bg-[#e8f1e8] px-3 py-2.5 text-sm text-[#315e38]">{status}</div>}

            <form className="flex flex-col gap-6" onSubmit={submit}>
                <div className="grid gap-5">
                    <div className="grid gap-2">
                        <Label htmlFor="email" className="text-xs font-semibold text-[#526357]">Email address</Label>
                        <Input
                            id="email"
                            type="email"
                            required
                            tabIndex={1}
                            autoComplete="email"
                            value={data.email}
                            onChange={(e) => setData('email', e.target.value)}
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-sm shadow-none focus-visible:ring-[#719276]"
                            placeholder="name@school.org"
                        />
                        <InputError message={errors.email} />
                    </div>

                    <div className="grid gap-2">
                        <div className="flex items-center">
                            <Label htmlFor="password" className="text-xs font-semibold text-[#526357]">Password</Label>
                            {canResetPassword && (
                                <a href={route('password.request')} className="ml-auto text-xs font-semibold text-[#527258] hover:text-[#193b2a]" tabIndex={5}>Forgot password?</a>
                            )}
                        </div>
                        <Input
                            id="password"
                            type="password"
                            required
                            tabIndex={2}
                            autoComplete="current-password"
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-sm shadow-none focus-visible:ring-[#719276]"
                            value={data.password}
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="flex items-center gap-2.5">
                        <Checkbox id="remember" name="remember" checked={data.remember} onCheckedChange={(checked) => setData('remember', checked === true)} tabIndex={3} />
                        <Label htmlFor="remember" className="text-xs text-[#748177]">Keep me signed in</Label>
                    </div>

                    <Button type="submit" className="mt-1 h-12 w-full justify-between rounded-md bg-[#234c35] px-4 text-sm font-semibold text-white hover:bg-[#183e2a]" tabIndex={4} disabled={processing}>
                        <span className="inline-flex items-center gap-2">{processing ? <LoaderCircle className="size-4 animate-spin" /> : <LockKeyhole aria-hidden="true" className="size-4" />} Sign in securely</span>
                        {!processing && <ArrowRight aria-hidden="true" className="size-4" />}
                    </Button>
                </div>
            </form>
        </AuthLayout>
    );
}
