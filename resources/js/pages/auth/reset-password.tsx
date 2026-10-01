import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, LoaderCircle, ShieldCheck } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

interface ResetPasswordProps {
    channel: 'email' | 'sms';
    identity: string;
    status?: string;
}

interface ResetPasswordForm {
    [key: string]: string;
    otp: string;
    password: string;
    password_confirmation: string;
}

export default function ResetPassword({ channel, identity, status }: ResetPasswordProps) {
    const destination = channel === 'email'
        ? identity.replace(/^(.{2})[^@]*(@.*)$/, '$1...$2')
        : `...${identity.slice(-4)}`;
    const { data, setData, post, processing, errors, reset } = useForm<ResetPasswordForm>({
        otp: '',
        password: '',
        password_confirmation: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();
        post(route('password.store'), {
            onFinish: () => reset('password', 'password_confirmation'),
        });
    };

    return (
        <AuthLayout title="Verify and reset" description="Enter the six-digit code, then choose a new password.">
            <Head title="Reset password" />

            {status && <div role="status" className="mb-5 border-l-2 border-[#c58c42] bg-[#f5eedf] px-3 py-2.5 text-sm leading-5 text-[#73562f]">{status}</div>}
            <div className="mb-5 flex items-start gap-2.5 rounded-md border border-[#e0e7dd] bg-white px-3 py-3 text-xs leading-5 text-[#718076]">
                <ShieldCheck aria-hidden="true" className="mt-0.5 size-4 shrink-0 text-[#507a55]" />
                <p>Code sent by {channel === 'email' ? 'email' : 'SMS'} to {destination}. It expires in 10 minutes.</p>
            </div>

            <form onSubmit={submit}>
                <div className="grid gap-4">
                    <div className="grid gap-2">
                        <Label htmlFor="otp" className="text-xs font-semibold text-[#526357]">6-digit verification code</Label>
                        <Input
                            id="otp"
                            type="text"
                            inputMode="numeric"
                            autoComplete="one-time-code"
                            maxLength={6}
                            value={data.otp}
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-base shadow-none focus-visible:ring-[#719276]"
                            onChange={(e) => setData('otp', e.target.value.replace(/\D/g, '').slice(0, 6))}
                            placeholder="000000"
                        />
                        <InputError message={errors.otp} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password" className="text-xs font-semibold text-[#526357]">New password</Label>
                        <Input
                            id="password"
                            type="password"
                            name="password"
                            autoComplete="new-password"
                            value={data.password}
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-sm shadow-none focus-visible:ring-[#719276]"
                            onChange={(e) => setData('password', e.target.value)}
                            placeholder="Password"
                        />
                        <InputError message={errors.password} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="password_confirmation" className="text-xs font-semibold text-[#526357]">Confirm new password</Label>
                        <Input
                            id="password_confirmation"
                            type="password"
                            name="password_confirmation"
                            autoComplete="new-password"
                            value={data.password_confirmation}
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-sm shadow-none focus-visible:ring-[#719276]"
                            onChange={(e) => setData('password_confirmation', e.target.value)}
                            placeholder="Confirm password"
                        />
                        <InputError message={errors.password_confirmation} className="mt-2" />
                    </div>

                    <Button type="submit" className="mt-1 h-12 w-full rounded-md bg-[#234c35] text-sm font-semibold text-white hover:bg-[#183e2a]" disabled={processing}>
                        {processing && <LoaderCircle className="size-4 animate-spin" />}
                        Set new password
                    </Button>
                </div>
            </form>
            <Link href={route('password.request')} className="mt-5 inline-flex items-center gap-1.5 text-xs font-semibold text-[#527258] hover:text-[#193b2a]"><ArrowLeft aria-hidden="true" className="size-3.5" /> Use a different contact</Link>
        </AuthLayout>
    );
}
