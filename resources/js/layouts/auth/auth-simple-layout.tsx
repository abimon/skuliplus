import AppLogoIcon from '@/components/app-logo-icon';
import { Link } from '@inertiajs/react';
import { ArrowUpRight, ShieldCheck } from 'lucide-react';

interface AuthLayoutProps {
    children: React.ReactNode;
    name?: string;
    title?: string;
    description?: string;
}

export default function AuthSimpleLayout({ children, title, description }: AuthLayoutProps) {
    return (
        <div className="min-h-svh bg-[#f5f6f1] text-[#20372a] lg:grid lg:grid-cols-[0.92fr_1.08fr]" style={{ fontFamily: 'DM Sans, sans-serif' }}>
            <aside className="relative isolate flex min-h-[250px] flex-col justify-between overflow-hidden bg-[#173b2a] px-6 py-6 text-white sm:px-10 sm:py-8 lg:min-h-svh lg:px-14 lg:py-12">
                <div aria-hidden="true" className="absolute inset-0 -z-10 opacity-[0.13]" style={{ backgroundImage: 'linear-gradient(rgba(255,255,255,.45) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.45) 1px, transparent 1px)', backgroundSize: '38px 38px' }} />
                <div aria-hidden="true" className="absolute right-8 bottom-8 -z-10 hidden h-44 w-44 rotate-12 border border-[#e6a75c]/50 lg:block" />
                <Link href={route('home')} className="inline-flex w-fit items-center gap-3">
                    <span className="flex size-11 items-center justify-center rounded-sm bg-white/10"><AppLogoIcon className="size-8 fill-current text-white" /></span>
                    <span><span className="block text-sm font-semibold uppercase">SchoolMS</span><span className="mt-0.5 block text-[10px] text-white/65">KENYA · SCHOOL OPERATIONS</span></span>
                </Link>
                <div className="max-w-lg py-7 lg:py-0">
                    <p className="mb-4 inline-flex items-center gap-2 text-[10px] font-semibold text-[#e7b46d] uppercase"><ShieldCheck aria-hidden="true" className="size-4" /> A connected school day</p>
                    <h2 className="max-w-md font-serif text-4xl leading-[1.04] text-white sm:text-5xl lg:text-6xl">Every learner.<br />Every day.</h2>
                    <p className="mt-5 max-w-sm text-sm leading-6 text-white/70">One secure place for the people, progress and practical work that keep a school moving.</p>
                </div>
                <p className="hidden items-center gap-2 text-xs text-white/55 lg:flex">A clearer view of school life <ArrowUpRight aria-hidden="true" className="size-3.5" /></p>
            </aside>

            <main className="flex min-h-[calc(100svh-250px)] items-center justify-center px-5 py-10 sm:px-10 lg:min-h-svh lg:px-14">
                <section className="w-full max-w-[430px]">
                    <div className="mb-8">
                        <p className="mb-2 text-[10px] font-semibold text-[#a06a33] uppercase">Secure school access</p>
                        <h1 className="font-serif text-3xl text-[#193b2a] sm:text-4xl">{title}</h1>
                        <p className="mt-2 text-sm leading-6 text-[#758178]">{description}</p>
                    </div>
                    {children}
                    <p className="mt-10 text-center text-[11px] text-[#98a098]">SchoolMS · Built for the rhythm of Kenyan schools</p>
                </section>
            </main>
        </div>
    );
}
