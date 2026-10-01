// Components
import { Head, useForm } from '@inertiajs/react';
import { LoaderCircle, Mail, MessageSquareText } from 'lucide-react';
import { FormEventHandler } from 'react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AuthLayout from '@/layouts/auth-layout';

type ResetRequest = { [key: string]: string; channel: 'email' | 'sms'; identity: string };

export default function ForgotPassword() {
    const { data, setData, post, processing, errors } = useForm<ResetRequest>({
        channel: 'email',
        identity: '',
    });

    const submit: FormEventHandler = (e) => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <AuthLayout title="Get back in" description="Choose a verified contact method and we’ll send a one-time reset code.">
            <Head title="Forgot password" />

            <div className="space-y-6">
                <form className="space-y-5" onSubmit={submit}>
                    <fieldset>
                        <legend className="mb-2 text-xs font-semibold text-[#526357]">Send code by</legend>
                        <div className="grid grid-cols-2 gap-2">
                            {(['email', 'sms'] as const).map((channel) => <button key={channel} type="button" aria-pressed={data.channel === channel} onClick={() => { setData((current) => ({ ...current, channel, identity: '' })); }} className={`flex h-11 items-center justify-center gap-2 rounded-md border text-sm font-semibold transition-colors ${data.channel === channel ? 'border-[#315c3d] bg-[#eaf1e8] text-[#244c32]' : 'border-[#dce3da] bg-white text-[#758178] hover:bg-[#f8f9f6]'}`}>{channel === 'email' ? <Mail aria-hidden="true" className="size-4" /> : <MessageSquareText aria-hidden="true" className="size-4" />}{channel === 'email' ? 'Email' : 'SMS'}</button>)}
                        </div>
                        <InputError message={errors.channel} />
                    </fieldset>
                    <div className="grid gap-2">
                        <Label htmlFor="identity" className="text-xs font-semibold text-[#526357]">{data.channel === 'email' ? 'School account email' : 'Mobile number'}</Label>
                        <Input
                            id="identity"
                            type={data.channel === 'email' ? 'email' : 'tel'}
                            inputMode={data.channel === 'email' ? 'email' : 'tel'}
                            autoComplete="off"
                            value={data.identity}
                            onChange={(e) => setData('identity', e.target.value)}
                            className="h-12 rounded-md border-[#dce3da] bg-white px-3.5 text-sm shadow-none focus-visible:ring-[#719276]"
                            placeholder={data.channel === 'email' ? 'name@school.org' : '07xx xxx xxx or +2547xx xxx xxx'}
                        />
                        <InputError message={errors.identity} />
                        {data.channel === 'sms' && <p className="text-[11px] leading-5 text-[#89948b]">Use the mobile number registered with your school account.</p>}
                    </div>

                    <div className="pt-1">
                        <Button className="h-12 w-full rounded-md bg-[#234c35] text-sm font-semibold text-white hover:bg-[#183e2a]" disabled={processing}>
                            {processing && <LoaderCircle className="size-4 animate-spin" />}
                            Send one-time code
                        </Button>
                    </div>
                </form>

                <a href={route('login')} className="block text-center text-xs font-semibold text-[#527258] hover:text-[#193b2a]">Back to sign in</a>
            </div>
        </AuthLayout>
    );
}
