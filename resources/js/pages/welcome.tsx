import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDown, ArrowRight, Check, GraduationCap, Menu, MoveUpRight, ShieldCheck, Users, Wallet, X } from 'lucide-react';
import { useState } from 'react';

type InquiryKind = 'contact' | 'demo';
type InquiryFormData = {
    [key: string]: string;
    kind: InquiryKind;
    name: string;
    email: string;
    phone: string;
    school: string;
    role: string;
    message: string;
    website: string;
};

const inquiryFields = 'h-12 w-full rounded-sm border border-[#d9e0d9] bg-white px-3.5 text-sm text-[#21382b] outline-none transition focus:border-[#527e5c] focus:ring-2 focus:ring-[#527e5c]/15';

function InquiryForm({ kind, sent }: { kind: InquiryKind; sent?: string }) {
    const form = useForm<InquiryFormData>({
        kind,
        name: '',
        email: '',
        phone: '',
        school: '',
        role: '',
        message: '',
        website: '',
    });

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/inquiries', {
            preserveScroll: true,
            onSuccess: () => form.reset('name', 'email', 'phone', 'school', 'role', 'message'),
        });
    }

    return (
        <form onSubmit={submit} className="space-y-4">
            <input type="hidden" name="kind" value={kind} />
            <div aria-hidden="true" className="absolute -left-[10000px] top-auto h-px w-px overflow-hidden">
                <label htmlFor={`${kind}-website`}>Website</label>
                <input id={`${kind}-website`} tabIndex={-1} autoComplete="off" value={form.data.website} onChange={(event) => form.setData('website', event.target.value)} />
            </div>
            {sent && <p role="status" className="border-l-2 border-[#5b875f] bg-[#eaf1e9] px-3 py-2.5 text-sm text-[#31593a]">{sent}</p>}
            {form.errors.message && <p role="alert" className="text-sm text-[#a14336]">{form.errors.message}</p>}
            <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-xs font-semibold text-[#59695d]">Your name
                    <input required maxLength={120} autoComplete="name" className={`${inquiryFields} mt-1.5`} value={form.data.name} onChange={(event) => form.setData('name', event.target.value)} placeholder="Name" />
                    {form.errors.name && <span className="mt-1 block font-normal text-[#a14336]">{form.errors.name}</span>}
                </label>
                <label className="block text-xs font-semibold text-[#59695d]">Work email
                    <input required type="email" maxLength={190} autoComplete="email" className={`${inquiryFields} mt-1.5`} value={form.data.email} onChange={(event) => form.setData('email', event.target.value)} placeholder="you@school.org" />
                    {form.errors.email && <span className="mt-1 block font-normal text-[#a14336]">{form.errors.email}</span>}
                </label>
                <label className="block text-xs font-semibold text-[#59695d]">Mobile number <span className="font-normal text-[#8a968c]">Optional</span>
                    <input type="tel" maxLength={32} autoComplete="tel" className={`${inquiryFields} mt-1.5`} value={form.data.phone} onChange={(event) => form.setData('phone', event.target.value)} placeholder="+254 7xx xxx xxx" />
                    {form.errors.phone && <span className="mt-1 block font-normal text-[#a14336]">{form.errors.phone}</span>}
                </label>
                {kind === 'demo' && <label className="block text-xs font-semibold text-[#59695d]">School name
                    <input required maxLength={160} className={`${inquiryFields} mt-1.5`} value={form.data.school} onChange={(event) => form.setData('school', event.target.value)} placeholder="Your school" />
                    {form.errors.school && <span className="mt-1 block font-normal text-[#a14336]">{form.errors.school}</span>}
                </label>}
            </div>
            {kind === 'demo' && <label className="block text-xs font-semibold text-[#59695d]">Your role
                <select className={`${inquiryFields} mt-1.5`} value={form.data.role} onChange={(event) => form.setData('role', event.target.value)}>
                    <option value="">Select your role</option>
                    <option>School owner or board member</option>
                    <option>Headteacher or principal</option>
                    <option>School administrator</option>
                    <option>Teacher or academic leader</option>
                    <option>Other</option>
                </select>
            </label>}
            <label className="block text-xs font-semibold text-[#59695d]">{kind === 'demo' ? 'What would you like to see?' : 'How can we help?'}
                <textarea rows={3} maxLength={2000} className="mt-1.5 w-full resize-y rounded-sm border border-[#d9e0d9] bg-white px-3.5 py-3 text-sm text-[#21382b] outline-none transition focus:border-[#527e5c] focus:ring-2 focus:ring-[#527e5c]/15" value={form.data.message} onChange={(event) => form.setData('message', event.target.value)} placeholder={kind === 'demo' ? 'Tell us what your school needs most.' : 'Share a question or tell us a little about your school.'} />
                {form.errors.message && <span className="mt-1 block font-normal text-[#a14336]">{form.errors.message}</span>}
            </label>
            <button type="submit" disabled={form.processing} className="inline-flex h-12 w-full items-center justify-between rounded-sm bg-[#214934] px-4 text-sm font-semibold text-white transition hover:bg-[#173a28] disabled:cursor-wait disabled:opacity-65 sm:w-auto sm:min-w-52">
                <span>{form.processing ? 'Sending request…' : kind === 'demo' ? 'Request a demo' : 'Send message'}</span>
                {form.processing ? <span className="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white" /> : <ArrowRight aria-hidden="true" className="size-4" />}
            </button>
            {form.errors.email && <p role="alert" className="text-xs text-[#a14336]">{form.errors.email}</p>}
        </form>
    );
}

export default function Welcome({ sent }: { sent?: { contact?: string; demo?: string } }) {
    const [menuOpen, setMenuOpen] = useState(false);

    return (
        <>
            <Head title="SchoolMS | School management made clearer">
                <meta name="description" content="SchoolMS brings Kenyan school administration, academics, finance and daily operations into one connected workspace." />
                <meta name="theme-color" content="#173b2a" />
                <link rel="preconnect" href="https://fonts.bunny.net" />
                <link href="https://fonts.bunny.net/css?family=dm-sans:400,500,600,700|dm-serif-display:400" rel="stylesheet" />
            </Head>
            <div className="min-h-screen bg-[#f5f6f1] text-[#24382c]" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <header className="absolute inset-x-0 top-0 z-30 border-b border-white/15 text-white">
                    <div className="mx-auto flex h-[76px] max-w-[1440px] items-center justify-between px-5 sm:px-8 lg:px-12">
                        <a href="#home" className="inline-flex items-center gap-2.5" aria-label="SchoolMS home">
                            <span className="flex size-9 items-center justify-center border border-white/35"><GraduationCap aria-hidden="true" className="size-5" /></span>
                            <span className="text-sm font-semibold">SchoolMS</span>
                        </a>
                        <nav className="hidden items-center gap-8 text-xs font-medium text-white/85 md:flex" aria-label="Main navigation">
                            <a className="transition hover:text-white" href="#about">About us</a>
                            <a className="transition hover:text-white" href="#contact">Contact</a>
                            <a className="transition hover:text-white" href="#demo">Book a demo</a>
                        </nav>
                        <div className="hidden items-center gap-3 md:flex">
                            <Link href={route('login')} className="px-3 py-2 text-xs font-semibold text-white/90 transition hover:text-white">Sign in</Link>
                            <a href="#demo" className="inline-flex h-10 items-center gap-2 bg-[#e6b66c] px-4 text-xs font-bold text-[#21392b] transition hover:bg-[#f0c985]">Book a demo <MoveUpRight aria-hidden="true" className="size-3.5" /></a>
                        </div>
                        <button type="button" onClick={() => setMenuOpen(!menuOpen)} aria-label={menuOpen ? 'Close navigation' : 'Open navigation'} aria-expanded={menuOpen} className="flex size-10 items-center justify-center border border-white/35 text-white md:hidden">
                            {menuOpen ? <X aria-hidden="true" className="size-5" /> : <Menu aria-hidden="true" className="size-5" />}
                        </button>
                    </div>
                    {menuOpen && <nav className="grid gap-px border-t border-white/15 bg-[#173b2a] px-5 py-3 text-sm md:hidden" aria-label="Mobile navigation">
                        <a onClick={() => setMenuOpen(false)} className="py-3 text-white/85" href="#about">About us</a>
                        <a onClick={() => setMenuOpen(false)} className="py-3 text-white/85" href="#contact">Contact</a>
                        <a onClick={() => setMenuOpen(false)} className="py-3 text-white/85" href="#demo">Book a demo</a>
                        <Link className="py-3 font-semibold text-[#e6b66c]" href={route('login')}>Sign in</Link>
                    </nav>}
                </header>

                <main>
                    <section id="home" className="relative flex min-h-[min(82svh,790px)] items-end overflow-hidden bg-[#173b2a] text-white">
                        <img src="https://images.unsplash.com/photo-1509062522246-3755977927d7?auto=format&fit=crop&w=2400&q=85" alt="Learners working together in a bright classroom" fetchPriority="high" className="absolute inset-0 size-full object-cover object-center" />
                        <div aria-hidden="true" className="absolute inset-0 bg-[#102b1f]/60" />
                        <div className="relative mx-auto w-full max-w-[1440px] px-5 pb-20 pt-36 sm:px-8 sm:pb-24 lg:px-12 lg:pb-28">
                            <div className="max-w-[760px]">
                                <p className="mb-6 inline-flex items-center gap-2 text-[11px] font-semibold text-[#f0c77e] uppercase"><span className="size-2 bg-[#f0c77e]" /> School management, rooted in Kenya</p>
                                <h1 className="font-serif text-5xl leading-[0.98] text-white sm:text-6xl lg:text-7xl">Make every<br className="hidden sm:block" /> school day count.</h1>
                                <p className="mt-7 max-w-xl text-base leading-7 text-white/82 sm:text-lg">Bring your people, learning, finances and day-to-day operations together, so your school team can spend more time on what matters.</p>
                                <div className="mt-9 flex flex-wrap items-center gap-3">
                                    <a href="#demo" className="inline-flex h-12 items-center gap-3 bg-[#e6b66c] px-5 text-sm font-bold text-[#21392b] transition hover:bg-[#f0c985]">Book a personal demo <ArrowRight aria-hidden="true" className="size-4" /></a>
                                    <a href="#about" className="inline-flex h-12 items-center gap-2 border border-white/50 px-5 text-sm font-semibold text-white transition hover:bg-white/10">Explore SchoolMS</a>
                                </div>
                            </div>
                            <a href="#about" className="mt-16 inline-flex items-center gap-2 text-[11px] font-medium text-white/75 hover:text-white">Discover a clearer school day <ArrowDown aria-hidden="true" className="size-3.5" /></a>
                        </div>
                        <div className="absolute right-0 bottom-0 left-0 h-px bg-white/30" />
                    </section>

                    <section id="about" className="scroll-mt-6 bg-[#f5f6f1] px-5 py-20 sm:px-8 sm:py-28 lg:px-12">
                        <div className="mx-auto max-w-[1280px]">
                            <div className="grid gap-10 lg:grid-cols-[0.72fr_1.28fr] lg:gap-24">
                                <div>
                                    <p className="text-[11px] font-bold text-[#a06a33] uppercase">About SchoolMS</p>
                                    <h2 className="mt-4 max-w-sm font-serif text-4xl leading-[1.08] text-[#1b3b2a] sm:text-5xl">Built around the real work of a school.</h2>
                                </div>
                                <div className="max-w-2xl">
                                    <p className="text-lg leading-8 text-[#4f6054]">A school runs on thousands of small, important moments. SchoolMS gives Kenyan school teams one connected workspace for learner records, CBC progress, family communication and the operations behind the classroom.</p>
                                    <p className="mt-5 text-sm leading-7 text-[#758178]">Designed for clear responsibilities and least-privilege access, with school data kept in its proper place. From the office to the classroom, teams can work from the same up-to-date picture.</p>
                                </div>
                            </div>
                            <div className="mt-16 grid border-t border-[#dbe1d8] sm:grid-cols-2 lg:grid-cols-4">
                                <article className="border-b border-[#dbe1d8] py-6 sm:pr-6 lg:border-r lg:pr-8">
                                    <Users aria-hidden="true" className="size-5 text-[#477550]" />
                                    <h3 className="mt-4 text-sm font-bold text-[#263c2e]">People & families</h3>
                                    <p className="mt-2 text-sm leading-6 text-[#768279]">Learner profiles, guardians, staff roles and printable school IDs.</p>
                                </article>
                                <article className="border-b border-[#dbe1d8] py-6 sm:pl-6 lg:border-r lg:px-8">
                                    <GraduationCap aria-hidden="true" className="size-5 text-[#477550]" />
                                    <h3 className="mt-4 text-sm font-bold text-[#263c2e]">Learning & progress</h3>
                                    <p className="mt-2 text-sm leading-6 text-[#768279]">CBC learning areas, class planning, assessments and results.</p>
                                </article>
                                <article className="border-b border-[#dbe1d8] py-6 sm:pr-6 lg:border-r lg:px-8">
                                    <Wallet aria-hidden="true" className="size-5 text-[#477550]" />
                                    <h3 className="mt-4 text-sm font-bold text-[#263c2e]">Finance & resources</h3>
                                    <p className="mt-2 text-sm leading-6 text-[#768279]">Fee accounts, payment records, library, stores and labs.</p>
                                </article>
                                <article className="border-b border-[#dbe1d8] py-6 sm:pl-6 lg:border-0 lg:pl-8">
                                    <ShieldCheck aria-hidden="true" className="size-5 text-[#477550]" />
                                    <h3 className="mt-4 text-sm font-bold text-[#263c2e]">Clear accountability</h3>
                                    <p className="mt-2 text-sm leading-6 text-[#768279]">Role-based access and a useful record of everyday school activity.</p>
                                </article>
                            </div>
                        </div>
                    </section>

                    <section id="demo" className="scroll-mt-6 bg-[#e9eee7] px-5 py-20 sm:px-8 sm:py-24 lg:px-12">
                        <div className="mx-auto grid max-w-[1280px] gap-12 lg:grid-cols-[0.8fr_1.2fr] lg:gap-24">
                            <div className="lg:pt-4">
                                <p className="text-[11px] font-bold text-[#a06a33] uppercase">Book a demo</p>
                                <h2 className="mt-4 max-w-md font-serif text-4xl leading-[1.08] text-[#1b3b2a] sm:text-5xl">See how it fits your school.</h2>
                                <p className="mt-5 max-w-md text-sm leading-7 text-[#68776c]">Tell us a little about your school. We’ll arrange a walkthrough focused on the way your team works.</p>
                                <ul className="mt-8 space-y-3 text-sm text-[#536558]">
                                    <li className="flex items-center gap-2.5"><Check aria-hidden="true" className="size-4 text-[#477550]" /> Your school’s day-to-day workflows</li>
                                    <li className="flex items-center gap-2.5"><Check aria-hidden="true" className="size-4 text-[#477550]" /> Curriculum, results and family access</li>
                                    <li className="flex items-center gap-2.5"><Check aria-hidden="true" className="size-4 text-[#477550]" /> A clear conversation about next steps</li>
                                </ul>
                            </div>
                            <div className="border-t border-[#cbd6ca] pt-6 sm:pt-8">
                                <InquiryForm kind="demo" sent={sent?.demo} />
                            </div>
                        </div>
                    </section>

                    <section id="contact" className="scroll-mt-6 bg-[#f5f6f1] px-5 py-20 sm:px-8 sm:py-24 lg:px-12">
                        <div className="mx-auto grid max-w-[1280px] gap-10 lg:grid-cols-[0.72fr_1.28fr] lg:gap-24">
                            <div>
                                <p className="text-[11px] font-bold text-[#a06a33] uppercase">Contact us</p>
                                <h2 className="mt-4 max-w-sm font-serif text-4xl leading-[1.08] text-[#1b3b2a] sm:text-5xl">Let’s start a conversation.</h2>
                                <p className="mt-5 max-w-sm text-sm leading-7 text-[#758178]">Questions, ideas or a specific school challenge? Send a note and our team will get back to you.</p>
                            </div>
                            <div className="border-t border-[#dbe1d8] pt-6 sm:pt-8">
                                <InquiryForm kind="contact" sent={sent?.contact} />
                            </div>
                        </div>
                    </section>
                </main>

                <footer className="bg-[#173b2a] px-5 py-7 text-white sm:px-8 lg:px-12">
                    <div className="mx-auto flex max-w-[1440px] flex-wrap items-center justify-between gap-4">
                        <a href="#home" className="inline-flex items-center gap-2 text-sm font-semibold"><GraduationCap aria-hidden="true" className="size-5" /> SchoolMS</a>
                        <p className="text-xs text-white/60">A clearer school day, together.</p>
                        <Link href={route('login')} className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#e6b66c] hover:text-[#f0c985]">School sign in <MoveUpRight aria-hidden="true" className="size-3.5" /></Link>
                    </div>
                </footer>
            </div>
        </>
    );
}