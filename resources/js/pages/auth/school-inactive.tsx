import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Building2, ShieldAlert } from 'lucide-react';

export default function SchoolInactive({ schoolName }: { schoolName?: string | null }) {
    return (
        <>
            <Head title="School access paused" />
            <main className="flex min-h-svh items-center justify-center bg-[#f5f6f1] px-5 py-10 text-[#24382c]" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <section className="w-full max-w-xl border-t-4 border-[#bd7650] bg-white p-6 shadow-[0_18px_60px_rgba(37,56,42,0.1)] sm:p-10">
                    <div className="flex size-12 items-center justify-center bg-[#f6e9e2] text-[#a2573c]"><ShieldAlert aria-hidden="true" className="size-6" /></div>
                    <p className="mt-7 text-[11px] font-bold uppercase text-[#a06a33]">Account access</p>
                    <h1 className="mt-2 font-serif text-3xl leading-tight text-[#193b2a] sm:text-4xl">School access is currently paused.</h1>
                    <p className="mt-4 text-sm leading-7 text-[#68776c]">
                        {schoolName ? <><span className="font-semibold text-[#3e5143]">{schoolName}</span> is marked inactive in SchoolMS. </> : 'Your school is marked inactive in SchoolMS. '}
                        Please contact Central Management to restore access. School accounts cannot sign in while their school is inactive.
                    </p>
                    <div className="mt-7 flex items-center gap-3 border-y border-[#e8ece5] py-4 text-xs text-[#7a877d]">
                        <Building2 aria-hidden="true" className="size-4 shrink-0 text-[#66836b]" />
                        <span>School status is managed by Central Management.</span>
                    </div>
                    <Link href={route('login')} className="mt-7 inline-flex h-11 items-center gap-2 bg-[#234c35] px-4 text-sm font-semibold text-white hover:bg-[#183e2a]">
                        <ArrowLeft aria-hidden="true" className="size-4" /> Return to sign in
                    </Link>
                </section>
            </main>
        </>
    );
}