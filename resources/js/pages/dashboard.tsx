import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    ArrowDownRight,
    ArrowRight,
    Bell,
    BookOpen,
    Boxes,
    Building2,
    CalendarDays,
    Check,
    ChevronDown,
    CircleDollarSign,
    DoorOpen,
    FlaskConical,
    GraduationCap,
    Library,
    ListTree,
    School,
    Sparkles,
    Trophy,
    Users,
    Wallet,
    type LucideIcon,
} from 'lucide-react';

type ModuleSummary = {
    key: string;
    name: string;
    description: string;
    icon: string;
    value: number;
    unit: string;
    href?: string;
    price_kes?: string | null;
};
type ActivationModule = { key: string; name: string; description: string; price_kes: string | null };
type CurriculumAvailability = { id: number; name: string; code: string; is_available: boolean };
type StudentSnapshot = {
    id: number;
    name: string;
    admission_number: string | null;
    class: string | null;
    stream: string | null;
    fees_balance: number;
    results: { id: number; score: number; grade: string | null; subject?: { name: string }; exam?: { name: string } }[];
    books: { id: number; due_on: string; book?: { title: string } }[];
    clubs: { id: number; name: string; type: string }[];
};
type DashboardProps = {
    auth: { user: { name: string; email: string } };
    roles: string[];
    stats: { label: string; value: number | string }[];
    modules: ModuleSummary[];
    unavailableModules: ActivationModule[];
    curriculumAvailability: CurriculumAvailability[];
    pendingModuleRequests: string[];
    pendingCurriculumRequests: number[];
    panels: {
        school?: {
            name: string;
            county: string | null;
            motto: string | null;
            logo_path: string | null;
            academic_year: string | null;
            curricula: { id: number; name: string; code: string }[];
        };
        profile?: StudentSnapshot;
        active_child?: StudentSnapshot | null;
        children?: { id: number; name: string }[];
        lessons?: { id: number; subject?: { name: string }; stream?: { name: string; school_class?: { name: string } } }[];
        department?: { name: string } | null;
    };
};

function ActivationRequest({
    type,
    subject,
    moduleKey,
    curriculumId,
    requested = false,
}: {
    type: 'module_activation' | 'curriculum_activation';
    subject: string;
    moduleKey?: string;
    curriculumId?: number;
    requested?: boolean;
}) {
    const form = useForm({
        type,
        subject,
        message: `Please enable ${subject} for our school.`,
        module_key: moduleKey ?? '',
        curriculum_id: curriculumId ?? '',
    });

    function submit() {
        form.post('/school/requests', { preserveScroll: true });
    }

    return (
        <button
            type="button"
            onClick={submit}
            disabled={form.processing || requested}
            className={`shrink-0 border px-3 py-2 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-70 ${requested ? 'border-[#dfe5dc] bg-[#f3f5f1] text-[#718076]' : 'border-[#b9cbb9] text-[#3c6745] hover:bg-[#edf4ec]'}`}
        >
            {form.processing ? 'Sending…' : requested ? 'Requested' : 'Request activation'}
        </button>
    );
}

function SchoolMessageForm() {
    const form = useForm({ type: 'inquiry', subject: '', message: '' });

    return (
        <form
            onSubmit={(event) => {
                event.preventDefault();
                form.post('/school/requests', { preserveScroll: true, onSuccess: () => form.reset() });
            }}
            className="mt-4 grid gap-3"
        >
            <select
                aria-label="Message type"
                value={form.data.type}
                onChange={(event) => form.setData('type', event.target.value)}
                className="h-10 rounded-sm border border-[#dfe5dc] bg-white px-3 text-xs text-[#425548]"
            >
                <option value="inquiry">General inquiry</option>
                <option value="feedback">Feedback</option>
                <option value="testimonial">Testimonial</option>
            </select>
            <input
                required
                maxLength={190}
                aria-label="Subject"
                value={form.data.subject}
                onChange={(event) => form.setData('subject', event.target.value)}
                placeholder="Subject"
                className="h-10 rounded-sm border border-[#dfe5dc] bg-white px-3 text-xs outline-none focus:border-[#77947a]"
            />
            <textarea
                required
                maxLength={5000}
                aria-label="Message"
                rows={3}
                value={form.data.message}
                onChange={(event) => form.setData('message', event.target.value)}
                placeholder="Write to Central Management"
                className="resize-y rounded-sm border border-[#dfe5dc] bg-white px-3 py-2.5 text-xs outline-none focus:border-[#77947a]"
            />
            {form.errors.message && (
                <p role="alert" className="text-xs text-red-700">
                    {form.errors.message}
                </p>
            )}
            <button
                disabled={form.processing}
                className="h-10 justify-self-start bg-[#244b35] px-4 text-xs font-semibold text-white disabled:opacity-50"
            >
                {form.processing ? 'Sending…' : 'Send to Central Management'}
            </button>
        </form>
    );
}

const moduleIcons: Record<string, LucideIcon> = {
    users: Users,
    schools: Building2,
    management: Bell,
    curricula: ListTree,
    academics: GraduationCap,
    curriculum: BookOpen,
    finance: Wallet,
    library: Library,
    gate: DoorOpen,
    stores: Boxes,
    activities: Trophy,
    labs: FlaskConical,
};
const moduleColors: Record<string, string> = {
    users: 'bg-[#e4f0e9] text-[#236345]',
    academics: 'bg-[#fff0dc] text-[#a75a1d]',
    curriculum: 'bg-[#e6eff5] text-[#38617a]',
    schools: 'bg-[#e5eef0] text-[#426971]',
    curricula: 'bg-[#e6eff5] text-[#38617a]',
    management: 'bg-[#f3eee2] text-[#92723f]',
    finance: 'bg-[#e5efe3] text-[#397145]',
    library: 'bg-[#f5e9e1] text-[#9c593d]',
    gate: 'bg-[#e5eef0] text-[#426971]',
    stores: 'bg-[#f1eddc] text-[#837328]',
    activities: 'bg-[#f6e5e4] text-[#a34a40]',
    labs: 'bg-[#e9e8f0] text-[#575275]',
};
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }];

function formatValue(label: string, value: number | string) {
    if (label.toLowerCase().includes('fees')) {
        return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', maximumFractionDigits: 0 }).format(Number(value));
    }

    return new Intl.NumberFormat('en-KE').format(Number(value));
}

function StudentOverview({ student, children }: { student: StudentSnapshot; children?: { id: number; name: string }[] }) {
    return (
        <section className="rounded-lg border border-[#e4e8df] bg-white p-5 md:p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div>
                    <p className="text-xs font-semibold tracking-[0.14em] text-[#718076] uppercase">Learner profile</p>
                    <h2 className="mt-2 font-serif text-2xl text-[#193a2b]">{student.name}</h2>
                    <p className="mt-1 text-sm text-[#748078]">
                        {[student.class, student.stream, student.admission_number].filter(Boolean).join(' · ') || 'Enrollment details pending'}
                    </p>
                    <Link
                        href={`/students/${student.id}/results`}
                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-[#43674c] hover:text-[#244d33]"
                    >
                        Results &amp; trends <ArrowRight aria-hidden="true" className="size-3.5" />
                    </Link>
                </div>
                {children && children.length > 1 && (
                    <label className="relative flex items-center gap-2 rounded-md border border-[#dfe5dc] px-3 py-2 text-sm text-[#334a3d]">
                        <span className="sr-only">Select child</span>
                        <select
                            aria-label="Select child"
                            value={student.id}
                            onChange={(event) => router.post('/dashboard/child', { child_id: Number(event.target.value) }, { preserveScroll: true })}
                            className="max-w-40 appearance-none bg-transparent pr-5 outline-none"
                        >
                            {children.map((child) => (
                                <option key={child.id} value={child.id}>
                                    {child.name}
                                </option>
                            ))}
                        </select>
                        <ChevronDown aria-hidden="true" className="pointer-events-none absolute right-3 size-4" />
                    </label>
                )}
            </div>
            <div className="mt-5 grid grid-cols-2 gap-3 lg:grid-cols-4">
                {[
                    ['Fees balance', formatValue('fees', student.fees_balance)],
                    ['Recent results', `${student.results.length} records`],
                    ['Books on loan', student.books.length],
                    ['Activities', `${student.clubs.length} joined`],
                ].map(([label, value]) => (
                    <div key={label} className="rounded-md bg-[#f4f6f1] p-3">
                        <p className="text-xs text-[#758078]">{label}</p>
                        <p className="mt-1 font-semibold text-[#203c2d]">{value}</p>
                    </div>
                ))}
            </div>
            {student.results.length > 0 && (
                <div className="mt-6 overflow-hidden rounded-md border border-[#e8ebe5]">
                    <div className="flex items-center justify-between border-b border-[#e8ebe5] px-4 py-3">
                        <h3 className="text-sm font-semibold text-[#243b2d]">Recent assessment</h3>
                        <span className="text-xs text-[#879087]">Latest entries</span>
                    </div>
                    <div className="divide-y divide-[#edf0eb]">
                        {student.results.slice(0, 4).map((result) => (
                            <div key={result.id} className="flex items-center justify-between gap-4 px-4 py-3 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-[#34463a]">{result.subject?.name ?? 'Learning area'}</p>
                                    <p className="mt-0.5 truncate text-xs text-[#818981]">{result.exam?.name ?? 'Assessment'}</p>
                                </div>
                                <p className="shrink-0 font-semibold text-[#244d36]">
                                    {result.score}
                                    {result.grade ? ` · ${result.grade}` : ''}
                                </p>
                            </div>
                        ))}
                    </div>
                </div>
            )}
        </section>
    );
}

export default function Dashboard() {
    const {
        auth,
        roles,
        stats,
        modules,
        panels,
        unavailableModules = [],
        curriculumAvailability = [],
        pendingModuleRequests = [],
        pendingCurriculumRequests = [],
    } = usePage<DashboardProps>().props;
    const firstName = auth.user.name.trim().split(' ')[0];
    const isSuperAdmin = roles.includes('super_admin');
    const learner = panels.profile ?? panels.active_child;
    const today = new Intl.DateTimeFormat('en-KE', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="School overview">
                <link rel="preconnect" href="https://fonts.googleapis.com" />
                <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
                <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet" />
            </Head>
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1440px]">
                    <header className="mb-7 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-sm font-medium text-[#7b877e]">{today}</p>
                            <h1 className="mt-1 font-serif text-3xl tracking-normal text-[#173b2a] sm:text-4xl">
                                {isSuperAdmin ? 'National overview' : `Good day, ${firstName}`}
                            </h1>
                            <p className="mt-2 text-sm text-[#718076]">
                                {isSuperAdmin
                                    ? 'School network and curriculum administration'
                                    : 'A clear view of what is happening across your school.'}
                            </p>
                        </div>
                        <div className="flex items-center gap-2 rounded-md border border-[#e0e5dc] bg-white px-3 py-2 text-sm text-[#627168]">
                            <CalendarDays aria-hidden="true" className="size-4 text-[#4f755d]" />
                            <span>{panels.school?.academic_year ?? 'Academic year'}</span>
                        </div>
                    </header>

                    {panels.school && (
                        <section className="relative mb-6 overflow-hidden rounded-lg bg-[#193f30] px-5 py-6 text-white sm:px-7 sm:py-7">
                            <div aria-hidden="true" className="absolute -top-16 -right-8 size-64 rounded-full border-[36px] border-white/[0.045]" />
                            <div aria-hidden="true" className="absolute right-36 -bottom-24 size-52 rounded-full border-[28px] border-[#e99a51]/15" />
                            <div className="relative flex flex-wrap items-center justify-between gap-6">
                                <div className="flex min-w-0 items-center gap-4">
                                    <div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-white/10 text-[#f1bf79]">
                                        {panels.school.logo_path ? (
                                            <img src={`/storage/${panels.school.logo_path}`} alt="" className="size-full object-cover" />
                                        ) : (
                                            <School aria-hidden="true" className="size-7" />
                                        )}
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold tracking-[0.16em] text-[#b9d0c0] uppercase">
                                            {panels.school.county ? `${panels.school.county} County` : 'School workspace'}
                                        </p>
                                        <h2 className="mt-1 truncate font-serif text-2xl sm:text-3xl">{panels.school.name}</h2>
                                        {panels.school.motto && <p className="mt-1 text-sm text-[#d8e5dc]">{panels.school.motto}</p>}
                                    </div>
                                </div>
                                <div className="relative flex flex-wrap gap-2">
                                    {panels.school.curricula.map((curriculum) => (
                                        <span
                                            key={curriculum.id}
                                            className="rounded-sm border border-white/20 bg-white/[0.08] px-3 py-1.5 text-xs font-medium text-[#f2f4e9]"
                                        >
                                            {curriculum.code}
                                        </span>
                                    ))}
                                    {!panels.school.curricula.length && <span className="text-sm text-[#c9d8ce]">No curriculum assigned</span>}
                                </div>
                            </div>
                        </section>
                    )}

                    <section aria-label="School indicators" className="mb-8 grid grid-cols-2 gap-3 lg:grid-cols-4">
                        {stats.map((stat, index) => {
                            const accents = ['#dc8251', '#56816a', '#d0a443', '#598092'];
                            const icons = [Users, GraduationCap, CircleDollarSign, DoorOpen];
                            const StatIcon = icons[index % icons.length];

                            return (
                                <article key={stat.label} className="min-w-0 rounded-lg border border-[#e5e9e1] bg-white p-4 sm:p-5">
                                    <div className="flex items-center justify-between gap-2">
                                        <p className="text-[11px] leading-4 font-semibold tracking-[0.03em] text-[#78847b] uppercase">{stat.label}</p>
                                        <StatIcon aria-hidden="true" className="size-4 shrink-0" style={{ color: accents[index % accents.length] }} />
                                    </div>
                                    <p className="mt-4 truncate text-2xl font-semibold tracking-normal text-[#213a2b] sm:text-3xl">
                                        {formatValue(stat.label, stat.value)}
                                    </p>
                                    <div className="mt-3 flex items-center gap-1 text-xs text-[#78857c]">
                                        <span className="inline-flex size-5 items-center justify-center rounded-full bg-[#eef3eb] text-[#4f795b]">
                                            <ArrowDownRight aria-hidden="true" className="size-3" />
                                        </span>
                                        <span>{index === 3 ? 'Recorded today' : 'Current school records'}</span>
                                    </div>
                                </article>
                            );
                        })}
                    </section>

                    <div className="grid items-start gap-7 xl:grid-cols-[minmax(0,1.7fr)_minmax(300px,0.8fr)]">
                        <section id="modules" className="scroll-mt-6">
                            <div className="mb-4 flex items-end justify-between gap-4">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.14em] text-[#859087] uppercase">Workspace</p>
                                    <h2 className="mt-1 font-serif text-2xl text-[#1b3b2b]">
                                        {isSuperAdmin ? 'National modules' : 'School modules'}
                                    </h2>
                                </div>
                                <span className="pb-1 text-xs text-[#859087]">{modules.length} available to you</span>
                            </div>
                            {modules.length > 0 ? (
                                <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-3">
                                    {modules.map((module) => {
                                        const Icon = moduleIcons[module.icon] ?? BookOpen;

                                        return (
                                            <article
                                                key={module.key}
                                                id={module.key}
                                                className="scroll-mt-6 rounded-lg border border-[#e5e9e1] bg-white p-4 transition-transform duration-200 hover:-translate-y-0.5 hover:border-[#cbd7cb] sm:p-5"
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <span
                                                        className={`flex size-10 items-center justify-center rounded-md ${moduleColors[module.key] ?? 'bg-[#edf0ea] text-[#526657]'}`}
                                                    >
                                                        <Icon aria-hidden="true" className="size-5" />
                                                    </span>
                                                    <span className="flex items-center gap-1 text-[10px] font-semibold tracking-[0.1em] text-[#5e8066] uppercase">
                                                        <Check aria-hidden="true" className="size-3" /> Available
                                                    </span>
                                                </div>
                                                <h3 className="mt-4 font-semibold text-[#293c30]">{module.name}</h3>
                                                <p className="mt-1 min-h-10 text-xs leading-5 text-[#7a867d]">{module.description}</p>
                                                <div className="mt-4 flex items-baseline gap-2 border-t border-[#eef0eb] pt-3">
                                                    <span className="text-xl font-semibold text-[#244735]">
                                                        {new Intl.NumberFormat('en-KE').format(module.value)}
                                                    </span>
                                                    <span className="text-xs text-[#7b877e]">{module.unit}</span>
                                                </div>
                                                {!['users', 'schools', 'curricula', 'management'].includes(module.key) && (
                                                    <p className="mt-2 text-[11px] font-semibold text-[#8d6336]">
                                                        {module.price_kes !== null && module.price_kes !== undefined ? (
                                                            <>
                                                                KES {Number(module.price_kes).toLocaleString('en-KE', { minimumFractionDigits: 2 })}{' '}
                                                                <span className="font-normal text-[#8a958c]">/ month</span>
                                                            </>
                                                        ) : (
                                                            <span className="font-normal text-[#8a958c]">Pricing pending Central Management</span>
                                                        )}
                                                    </p>
                                                )}
                                                {module.href && (
                                                    <Link
                                                        href={module.href}
                                                        className="mt-3 inline-flex items-center gap-1.5 text-xs font-semibold text-[#42694b] hover:text-[#244d33]"
                                                    >
                                                        Open workspace <ArrowRight aria-hidden="true" className="size-3.5" />
                                                    </Link>
                                                )}
                                            </article>
                                        );
                                    })}
                                </div>
                            ) : (
                                <div className="rounded-lg border border-dashed border-[#dce3d9] bg-white p-7 text-center">
                                    <Sparkles aria-hidden="true" className="mx-auto size-6 text-[#b17b47]" />
                                    <p className="mt-3 text-sm font-medium text-[#344b3b]">Your workspace is ready</p>
                                    <p className="mt-1 text-xs text-[#7d887f]">School modules will appear here when access is assigned.</p>
                                </div>
                            )}
                            {roles.includes('school_admin') &&
                                (unavailableModules.length > 0 || curriculumAvailability.some((curriculum) => !curriculum.is_available)) && (
                                    <section className="mt-6 border-t border-[#dfe5dc] pt-5">
                                        <div>
                                            <p className="text-xs font-semibold text-[#819087] uppercase">Not enabled</p>
                                            <h2 className="mt-1 font-serif text-xl text-[#1b3b2b]">Available by request</h2>
                                        </div>
                                        <div className="mt-3 divide-y divide-[#e9ede7] border-y border-[#e9ede7] bg-white px-4">
                                            {unavailableModules.map((module) => (
                                                <div key={module.key} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                                    <div>
                                                        <p className="text-sm font-semibold text-[#405544]">
                                                            {module.name}{' '}
                                                            <span className="ml-1 rounded-sm bg-[#f3eee2] px-1.5 py-0.5 text-[9px] font-semibold text-[#92723f] uppercase">
                                                                Inactive
                                                            </span>
                                                        </p>
                                                        <p className="mt-1 text-xs text-[#829087]">
                                                            {module.description}
                                                            {module.price_kes &&
                                                                ` · KES ${Number(module.price_kes).toLocaleString('en-KE', { minimumFractionDigits: 2 })} / month`}
                                                        </p>
                                                    </div>
                                                    <ActivationRequest
                                                        type="module_activation"
                                                        subject={module.name}
                                                        moduleKey={module.key}
                                                        requested={pendingModuleRequests.includes(module.key)}
                                                    />
                                                </div>
                                            ))}
                                            {curriculumAvailability
                                                .filter((curriculum) => !curriculum.is_available)
                                                .map((curriculum) => (
                                                    <div key={curriculum.id} className="flex flex-wrap items-center justify-between gap-3 py-3">
                                                        <div>
                                                            <p className="text-sm font-semibold text-[#405544]">
                                                                {curriculum.name}{' '}
                                                                <span className="ml-1 rounded-sm bg-[#f3eee2] px-1.5 py-0.5 text-[9px] font-semibold text-[#92723f] uppercase">
                                                                    Inactive
                                                                </span>
                                                            </p>
                                                            <p className="mt-1 text-xs text-[#829087]">{curriculum.code} curriculum pathway</p>
                                                        </div>
                                                        <ActivationRequest
                                                            type="curriculum_activation"
                                                            subject={curriculum.name}
                                                            curriculumId={curriculum.id}
                                                            requested={pendingCurriculumRequests.includes(curriculum.id)}
                                                        />
                                                    </div>
                                                ))}
                                        </div>
                                    </section>
                                )}
                        </section>

                        <aside className="space-y-4">
                            {learner && <StudentOverview student={learner} children={panels.children} />}

                            {roles.includes('school_admin') && (
                                <section className="rounded-lg border border-[#e5e9e1] bg-white p-5">
                                    <div>
                                        <p className="text-xs font-semibold text-[#849087] uppercase">Central Management</p>
                                        <h2 className="mt-1 font-serif text-xl text-[#1d3d2c]">Feedback &amp; inquiries</h2>
                                        <p className="mt-2 text-xs leading-5 text-[#7a867d]">
                                            Send feedback, a testimonial, or a general inquiry from your school.
                                        </p>
                                    </div>
                                    <SchoolMessageForm />
                                </section>
                            )}

                            {(roles.includes('teacher') || roles.includes('hod_academics')) && (
                                <section className="rounded-lg border border-[#e5e9e1] bg-white p-5">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <p className="text-xs font-semibold tracking-[0.12em] text-[#849087] uppercase">Teaching</p>
                                            <h2 className="mt-1 font-serif text-xl text-[#1d3d2c]">My lessons</h2>
                                        </div>
                                        <span className="flex size-9 items-center justify-center rounded-md bg-[#e6eff5] text-[#38617a]">
                                            <BookOpen aria-hidden="true" className="size-4" />
                                        </span>
                                    </div>
                                    {panels.department && <p className="mt-3 text-xs text-[#728077]">{panels.department.name} department</p>}
                                    {panels.lessons?.length ? (
                                        <ul className="mt-3 divide-y divide-[#edf0eb]">
                                            {panels.lessons.slice(0, 5).map((lesson) => (
                                                <li key={lesson.id} className="flex items-center justify-between gap-3 py-3 text-sm">
                                                    <span className="truncate font-medium text-[#35463a]">
                                                        {lesson.subject?.name ?? 'Learning area'}
                                                    </span>
                                                    <span className="shrink-0 text-xs text-[#7d887f]">
                                                        {lesson.stream?.school_class?.name ?? lesson.stream?.name ?? 'Class'}
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    ) : (
                                        <p className="mt-4 text-sm text-[#7d887f]">No lessons assigned yet.</p>
                                    )}
                                </section>
                            )}

                            {panels.school && (
                                <section className="rounded-lg border border-[#e5e9e1] bg-[#edf2e9] p-5">
                                    <div className="flex items-center gap-2 text-[#4c7055]">
                                        <CalendarDays aria-hidden="true" className="size-4" />
                                        <p className="text-xs font-semibold tracking-[0.12em] uppercase">Kenyan school calendar</p>
                                    </div>
                                    <p className="mt-3 font-serif text-xl text-[#294a34]">
                                        {panels.school.academic_year ?? 'Set up your academic year'}
                                    </p>
                                    <p className="mt-1 text-sm leading-6 text-[#708074]">
                                        CBC and legacy pathways can run side by side, with learning areas scoped to each class.
                                    </p>
                                    <a
                                        href="#curriculum"
                                        className="mt-4 inline-flex items-center gap-2 text-sm font-semibold text-[#3c6749] hover:text-[#244d33]"
                                    >
                                        View curriculum summary <ArrowRight aria-hidden="true" className="size-4" />
                                    </a>
                                </section>
                            )}

                            {isSuperAdmin && !panels.school && (
                                <section className="rounded-lg bg-[#f0e9da] p-5">
                                    <div className="flex items-center gap-2 text-[#92652e]">
                                        <School aria-hidden="true" className="size-4" />
                                        <p className="text-xs font-semibold tracking-[0.12em] uppercase">National administration</p>
                                    </div>
                                    <h2 className="mt-3 font-serif text-xl text-[#473820]">Curricula &amp; schools</h2>
                                    <p className="mt-2 text-sm leading-6 text-[#756b59]">
                                        Assign KICD pathways to schools and manage the national curriculum catalogue.
                                    </p>
                                </section>
                            )}
                        </aside>
                    </div>

                    {panels.school && (
                        <section id="curriculum" className="mt-7 scroll-mt-6 rounded-lg border border-[#e5e9e1] bg-white p-5 sm:p-6">
                            <div className="flex flex-wrap items-start justify-between gap-4">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.14em] text-[#849087] uppercase">Learning pathways</p>
                                    <h2 className="mt-1 font-serif text-2xl text-[#1d3d2c]">Assigned curricula</h2>
                                </div>
                                <span className="rounded-sm bg-[#edf2e9] px-3 py-1.5 text-xs font-semibold text-[#52705a]">KICD framework</span>
                            </div>
                            <div className="mt-5 grid gap-3 md:grid-cols-2">
                                {panels.school.curricula.map((curriculum, index) => (
                                    <article key={curriculum.id} className="flex items-center gap-4 rounded-md border border-[#e9ede6] p-4">
                                        <span
                                            className={`flex size-11 shrink-0 items-center justify-center rounded-md ${index % 2 ? 'bg-[#fff0dc] text-[#a75a1d]' : 'bg-[#e4f0e9] text-[#236345]'}`}
                                        >
                                            <GraduationCap aria-hidden="true" className="size-5" />
                                        </span>
                                        <div className="min-w-0">
                                            <p className="font-semibold text-[#34463a]">{curriculum.name}</p>
                                            <p className="mt-1 text-xs text-[#818981]">{curriculum.code} · School-assigned pathway</p>
                                        </div>
                                        <Check aria-hidden="true" className="ml-auto size-4 shrink-0 text-[#55815f]" />
                                    </article>
                                ))}
                                {!panels.school.curricula.length && (
                                    <p className="text-sm text-[#78847b]">No curriculum has been assigned to this school yet.</p>
                                )}
                            </div>
                        </section>
                    )}
                </div>
            </main>
        </AppLayout>
    );
}
