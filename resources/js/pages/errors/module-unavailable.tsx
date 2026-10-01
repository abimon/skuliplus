import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, ArrowUpRight, Building2, CircleHelp, LockKeyhole } from 'lucide-react';

type UnavailableModule = {
    key: string;
    name: string;
    schoolName: string;
    schoolCode: string;
};

export default function ModuleUnavailable({ module }: { module: UnavailableModule }) {
    return (
        <>
            <Head title={`${module.name} unavailable`} />
            <main className="relative flex min-h-svh items-center justify-center overflow-hidden bg-[#f3f5f0] px-5 py-10 text-[#26392d]" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div aria-hidden="true" className="absolute inset-x-0 top-0 h-2 bg-[#244b35]" />
                <section className="w-full max-w-3xl">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <Link href={route('dashboard')} className="inline-flex items-center gap-2 text-xs font-semibold text-[#57705c] hover:text-[#23452f]"><ArrowLeft aria-hidden="true" className="size-4" /> Return to dashboard</Link>
                        <span className="inline-flex items-center gap-2 border border-[#dce4db] bg-white px-3 py-2 text-xs text-[#68786b]"><Building2 aria-hidden="true" className="size-3.5" />{module.schoolCode}</span>
                    </div>

                    <article className="mt-7 overflow-hidden border border-[#dfe6dc] bg-white shadow-[0_18px_60px_rgba(37,56,42,0.08)]">
                        <div className="grid md:grid-cols-[190px_1fr]">
                            <aside className="flex min-h-40 items-center justify-center bg-[#234934] p-8 text-[#e8bd7b] md:min-h-full">
                                <div className="flex size-20 items-center justify-center border border-white/25 bg-white/[0.06]"><LockKeyhole aria-hidden="true" className="size-9" /></div>
                            </aside>
                            <div className="p-6 sm:p-9">
                                <p className="text-[10px] font-bold uppercase text-[#a06a33]">School module access</p>
                                <h1 className="mt-2 font-serif text-3xl leading-tight text-[#193b2a] sm:text-4xl">{module.name} isn’t enabled for this school.</h1>
                                <p className="mt-4 text-sm leading-7 text-[#68776c]">
                                    The {module.name.toLowerCase()} workspace is currently unavailable for <span className="font-semibold text-[#405544]">{module.schoolName}</span>. This module is switched off in the school’s central configuration; your account and school data are not affected.
                                </p>

                                <section className="mt-6 border-y border-[#e8ece5] py-5" aria-labelledby="next-steps-title">
                                    <h2 id="next-steps-title" className="flex items-center gap-2 text-sm font-semibold text-[#304638]"><CircleHelp aria-hidden="true" className="size-4 text-[#66836b]" /> What to do next</h2>
                                    <ol className="mt-3 grid gap-3 text-xs leading-5 text-[#758178] sm:grid-cols-2">
                                        <li className="flex gap-2"><span className="flex size-5 shrink-0 items-center justify-center bg-[#edf3eb] font-semibold text-[#477550]">1</span><span>Contact your school administrator and confirm this module is needed.</span></li>
                                        <li className="flex gap-2"><span className="flex size-5 shrink-0 items-center justify-center bg-[#edf3eb] font-semibold text-[#477550]">2</span><span>The administrator can ask Central Management to enable it for {module.schoolCode}.</span></li>
                                    </ol>
                                </section>

                                <p className="mt-5 text-[11px] leading-5 text-[#8a958c]">If Central Management has just enabled this module, return to your dashboard and open it again.</p>
                                <Link href={route('dashboard')} className="mt-6 inline-flex h-11 items-center gap-2 bg-[#244b35] px-4 text-sm font-semibold text-white transition hover:bg-[#193c2a]">
                                    Go to dashboard <ArrowUpRight aria-hidden="true" className="size-4" />
                                </Link>
                            </div>
                        </div>
                    </article>
                    <p className="mt-5 text-center text-[10px] text-[#98a198]">SchoolMS · Module availability is managed centrally.</p>
                </section>
            </main>
        </>
    );
}