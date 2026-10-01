import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Building2, Check, ChevronDown, Plus, School, X } from 'lucide-react';
import { useState } from 'react';

type CurriculumOption = { id: number; code: string; name: string; is_active?: boolean };
type SchoolRecord = {
    id: number;
    name: string;
    code: string;
    type: string;
    level: string;
    county: string | null;
    sub_county: string | null;
    ward: string | null;
    address: string | null;
    moe_code: string | null;
    knec_code: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    po_box: string | null;
    motto: string | null;
    mission: string | null;
    vision: string | null;
    aim: string | null;
    primary_color: string;
    secondary_color: string;
    accent_color: string;
    logo_path: string | null;
    enabled_modules: string[] | null;
    is_active: boolean;
    users_count: number;
    curricula: CurriculumOption[];
};
type SchoolUpdateData = {
    name: string;
    code: string;
    type: string;
    level: string;
    moe_code: string;
    knec_code: string;
    county: string;
    sub_county: string;
    ward: string;
    address: string;
    phone: string;
    email: string;
    website: string;
    po_box: string;
    motto: string;
    mission: string;
    vision: string;
    aim: string;
    primary_color: string;
    secondary_color: string;
    accent_color: string;
    logo: File | null;
    remove_logo: boolean;
    is_active: boolean;
    curriculum_ids: number[];
    enabled_modules: string[];
};
type SchoolData = {
    name: string;
    code: string;
    type: string;
    level: string;
    county: string;
    sub_county: string;
    phone: string;
    email: string;
    enabled_modules: string[];
    curriculum_ids: number[];
    admin_name: string;
    admin_email: string;
    admin_password: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'Schools', href: '/admin/schools' },
];

function CurriculumChecks({ options, selected, onChange }: { options: CurriculumOption[]; selected: number[]; onChange: (id: number, checked: boolean) => void }) {
    return (
        <div className="grid gap-2 sm:grid-cols-2">
            {options.map((curriculum) => (
                <label key={curriculum.id} className="flex cursor-pointer items-start gap-3 rounded-md border border-[#e4e9e1] p-3 hover:bg-[#f7f8f4]">
                    <input type="checkbox" checked={selected.includes(curriculum.id)} onChange={(event) => onChange(curriculum.id, event.target.checked)} className="mt-1 accent-[#356344]" />
                    <span className="min-w-0">
                        <span className="block text-sm font-medium text-[#34463a]">{curriculum.name}</span>
                        <span className="mt-0.5 block text-xs text-[#829087]">{curriculum.code}</span>
                    </span>
                </label>
            ))}
            {!options.length && <p className="text-sm text-[#89928a]">Add a curriculum before registering a school.</p>}
        </div>
    );
}

const schoolTextFields = [
    ['name', 'School name'], ['code', 'School code'], ['type', 'School type'], ['level', 'Education levels'],
    ['moe_code', 'Ministry code'], ['knec_code', 'KNEC code'], ['county', 'County'], ['sub_county', 'Sub-county'],
    ['ward', 'Ward'], ['address', 'Postal / physical address'], ['phone', 'Phone'], ['email', 'Email'],
    ['website', 'Website'], ['po_box', 'P.O. box'], ['motto', 'Motto'],
] as const;
const schoolStoryFields = [['mission', 'Mission'], ['vision', 'Vision'], ['aim', 'Aim']] as const;
const allModuleKeys = (modules: Record<string, string>) => Object.keys(modules);

function SchoolRow({ school, curricula, modules }: { school: SchoolRecord; curricula: CurriculumOption[]; modules: Record<string, string> }) {
    const form = useForm<SchoolUpdateData>({
        name: school.name,
        code: school.code,
        type: school.type,
        level: school.level,
        moe_code: school.moe_code ?? '',
        knec_code: school.knec_code ?? '',
        county: school.county ?? '',
        sub_county: school.sub_county ?? '',
        ward: school.ward ?? '',
        address: school.address ?? '',
        phone: school.phone ?? '',
        email: school.email ?? '',
        website: school.website ?? '',
        po_box: school.po_box ?? '',
        motto: school.motto ?? '',
        mission: school.mission ?? '',
        vision: school.vision ?? '',
        aim: school.aim ?? '',
        primary_color: school.primary_color ?? '#0f766e',
        secondary_color: school.secondary_color ?? '#f59e0b',
        accent_color: school.accent_color ?? '#0369a1',
        logo: null,
        remove_logo: false,
        is_active: school.is_active,
        curriculum_ids: school.curricula.map((curriculum) => curriculum.id),
        enabled_modules: school.enabled_modules ?? allModuleKeys(modules),
    });
    const updateCurriculum = (id: number, checked: boolean) => form.setData('curriculum_ids', checked ? [...form.data.curriculum_ids, id] : form.data.curriculum_ids.filter((curriculumId) => curriculumId !== id));
    const updateModule = (key: string, checked: boolean) => form.setData('enabled_modules', checked ? [...form.data.enabled_modules, key] : form.data.enabled_modules.filter((module) => module !== key));
    const setSchoolActive = () => router.patch(`/admin/schools/${school.id}/status`, { is_active: !school.is_active }, { preserveScroll: true });

    return (
        <article className="rounded-lg border border-[#e3e8df] bg-white p-5 sm:p-6">
            <div className="flex flex-wrap items-start justify-between gap-4">
                <div className="flex min-w-0 items-center gap-3">
                    <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-md bg-[#e7f0e8] text-[#356344]">{school.logo_path ? <img src={`/storage/${school.logo_path}`} alt="" className="size-full object-cover" /> : <School aria-hidden="true" className="size-5" />}</span>
                    <div className="min-w-0">
                        <h2 className="truncate font-semibold text-[#263c2d]">{school.name}</h2>
                        <p className="mt-1 text-xs text-[#7b877e]">{school.code} · {school.county || 'County not set'}</p>
                    </div>
                </div>
                <div className="flex items-center gap-2"><span className={`rounded-sm px-2.5 py-1 text-xs font-semibold ${school.is_active ? 'bg-[#e7f1e6] text-[#3c7147]' : 'bg-[#f3e9e5] text-[#a05642]'}`}>{school.is_active ? 'Active' : 'Inactive'}</span><button type="button" onClick={setSchoolActive} className="h-8 border border-[#dfe5dc] px-2.5 text-xs font-semibold text-[#536659] hover:bg-[#f5f7f2]">{school.is_active ? 'Suspend' : 'Activate'}</button></div>
            </div>
            <div className="mt-5 flex flex-wrap items-center gap-2">
                {school.curricula.map((curriculum) => <span key={curriculum.id} className={`rounded-sm border px-2.5 py-1 text-xs ${curriculum.is_active === false ? 'border-[#eadcc4] bg-[#f7f0e3] text-[#8b6d38]' : 'border-[#e1e8df] bg-[#f6f8f3] text-[#59705d]'}`}>{curriculum.code}{curriculum.is_active === false && ' · Inactive'}</span>)}
                <span className="ml-auto text-xs text-[#849087]">{school.users_count} accounts</span>
            </div>
            <details className="group mt-5 border-t border-[#edf0eb] pt-4">
                <summary className="flex cursor-pointer list-none items-center justify-between gap-3 text-sm font-medium text-[#3a5140]">
                    Update school profile, modules and curricula
                    <ChevronDown aria-hidden="true" className="size-4 transition-transform group-open:rotate-180" />
                </summary>
                <form className="mt-4 space-y-4" onSubmit={(event) => { event.preventDefault(); form.transform((data) => ({ ...data, _method: 'PUT' })); form.post(`/admin/schools/${school.id}`, { forceFormData: true, preserveScroll: true }); }}>
                    <div className="grid gap-3 sm:grid-cols-2">
                        {schoolTextFields.map(([field, label]) => <label key={field} className="text-xs font-medium text-[#68776c]">{label}<input value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]" />{form.errors[field] && <span className="mt-1 block text-xs text-red-700">{form.errors[field]}</span>}</label>)}
                    </div>
                    <fieldset className="grid gap-3 border-t border-[#edf0eb] pt-4 sm:grid-cols-3"><legend className="mb-2 text-xs font-semibold uppercase text-[#56675a]">School colours</legend>{([['primary_color', 'Primary'], ['secondary_color', 'Secondary'], ['accent_color', 'Accent']] as const).map(([field, label]) => <label key={field} className="flex items-center gap-3 rounded-sm border border-[#e4e9e1] p-3 text-xs font-medium text-[#68776c]"><input type="color" value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className="size-9 cursor-pointer border-0 bg-transparent p-0" />{label}<span className="ml-auto font-mono text-[10px]">{form.data[field]}</span></label>)}</fieldset>
                    <div className="grid gap-3">{schoolStoryFields.map(([field, label]) => <label key={field} className="text-xs font-medium text-[#68776c]">{label}<textarea rows={2} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} className="mt-1.5 w-full resize-y rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]" />{form.errors[field] && <span className="mt-1 block text-xs text-red-700">{form.errors[field]}</span>}</label>)}</div>
                    <div className="border-t border-[#edf0eb] pt-4"><label className="block text-xs font-medium text-[#68776c]">School logo<input type="file" accept="image/*" onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)} className="mt-1.5 block w-full text-xs file:mr-2 file:rounded-sm file:border-0 file:bg-[#eaf1e8] file:px-3 file:py-2 file:text-xs file:font-semibold file:text-[#355e3c]" />{form.errors.logo && <span className="mt-1 block text-xs text-red-700">{form.errors.logo}</span>}</label>{school.logo_path && <label className="mt-2 flex items-center gap-2 text-xs text-[#68776c]"><input type="checkbox" checked={form.data.remove_logo} onChange={(event) => form.setData('remove_logo', event.target.checked)} className="accent-[#356344]" />Remove current logo</label>}</div>
                    <fieldset className="space-y-2 border-t border-[#edf0eb] pt-4"><legend className="mb-2 text-xs font-semibold uppercase text-[#56675a]">Enabled modules</legend><div className="grid gap-2 sm:grid-cols-2">{Object.entries(modules).map(([key, label]) => <label key={key} className="flex cursor-pointer items-center gap-3 rounded-sm border border-[#e4e9e1] p-3 text-sm text-[#34463a]"><input type="checkbox" checked={form.data.enabled_modules.includes(key)} onChange={(event) => updateModule(key, event.target.checked)} className="size-4 accent-[#356344]" /><span>{label}</span></label>)}</div></fieldset>
                    <CurriculumChecks options={curricula} selected={form.data.curriculum_ids} onChange={updateCurriculum} />
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <label className="flex items-center gap-2 text-sm text-[#58685c]"><input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="accent-[#356344]" /> School is active</label>
                        <button disabled={form.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-60">Save changes</button>
                    </div>
                </form>
            </details>
        </article>
    );
}

export default function SchoolsIndex({ schools, curricula, modules, availableModules }: { schools: SchoolRecord[]; curricula: CurriculumOption[]; modules: Record<string, string>; availableModules: { key: string; name: string; description: string | null; price_kes: string | null }[] }) {
    const [showCreate, setShowCreate] = useState(false);
    const form = useForm<SchoolData>({
        name: '', code: '', type: 'mixed', level: 'comprehensive', county: '', sub_county: '', phone: '', email: '',
        enabled_modules: availableModules.map((module) => module.key),
        curriculum_ids: [], admin_name: '', admin_email: '', admin_password: '',
    });
    const updateCurriculum = (id: number, checked: boolean) => form.setData('curriculum_ids', checked ? [...form.data.curriculum_ids, id] : form.data.curriculum_ids.filter((curriculumId) => curriculumId !== id));
    const updateInitialModule = (key: string, checked: boolean) => form.setData('enabled_modules', checked ? [...form.data.enabled_modules, key] : form.data.enabled_modules.filter((module) => module !== key));

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/admin/schools', { onSuccess: () => { form.reset(); setShowCreate(false); } });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Schools" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1280px]">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">National administration</p>
                            <h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">School registry</h1>
                            <p className="mt-2 text-sm text-[#718076]">Register schools and assign their Kenyan curriculum pathways.</p>
                        </div>
                        <button onClick={() => setShowCreate(true)} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a]"><Plus aria-hidden="true" className="size-4" /> Register school</button>
                    </header>

                    <section className="mt-7 grid gap-3 sm:grid-cols-3">
                        {[
                            ['Registered schools', schools.length],
                            ['Active schools', schools.filter((school) => school.is_active).length],
                            ['Curriculum pathways', curricula.length],
                        ].map(([label, value]) => <div key={label} className="rounded-lg border border-[#e3e8df] bg-white p-4"><p className="text-xs font-semibold tracking-[0.08em] text-[#7c887f] uppercase">{label}</p><p className="mt-3 text-2xl font-semibold text-[#203a2a]">{value}</p></div>)}
                    </section>

                    <section className="mt-7 space-y-3">
                        <div className="mb-3 flex items-center justify-between"><h2 className="font-serif text-2xl text-[#1b3b2b]">Schools</h2><span className="text-xs text-[#859087]">{schools.length} records</span></div>
                        {schools.map((school) => <SchoolRow key={school.id} school={school} curricula={curricula} modules={modules} />)}
                        {!schools.length && <div className="rounded-lg border border-dashed border-[#dce3d9] bg-white p-9 text-center"><Building2 aria-hidden="true" className="mx-auto size-7 text-[#729078]" /><p className="mt-3 text-sm font-medium">No schools registered</p><p className="mt-1 text-xs text-[#7d887f]">Register a school to create its first administrator account.</p></div>}
                    </section>
                </div>
            </main>

            {showCreate && (
                <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowCreate(false); }}>
                    <section role="dialog" aria-modal="true" aria-labelledby="register-school-title" className="my-auto w-full max-w-3xl rounded-lg bg-[#fbfcf8] p-5 shadow-2xl sm:p-7">
                        <div className="flex items-start justify-between gap-4">
                            <div><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">New tenant</p><h2 id="register-school-title" className="mt-1 font-serif text-2xl text-[#183b2a]">Register a school</h2></div>
                            <button aria-label="Close" onClick={() => setShowCreate(false)} className="rounded-md p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button>
                        </div>
                        <form onSubmit={submit} className="mt-6 space-y-5">
                            <div className="grid gap-3 sm:grid-cols-2">
                                {([
                                    ['name', 'School name', 'e.g. Umoja Heights School'], ['code', 'School code', 'e.g. UHS001'],
                                    ['type', 'School type', 'mixed'], ['level', 'Education levels', 'comprehensive'],
                                    ['county', 'County', 'Nairobi'], ['sub_county', 'Sub-county', 'Westlands'],
                                    ['phone', 'Phone', '+254 7xx xxx xxx'], ['email', 'School email', 'office@school.ke'],
                                ] as const).map(([field, label, placeholder]) => (
                                    <label key={field} className="text-xs font-medium text-[#68776c]">{label}<input value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} placeholder={placeholder} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]" />{form.errors[field] && <span className="mt-1 block text-xs text-red-700">{form.errors[field]}</span>}</label>
                                ))}
                            </div>
                            <fieldset className="space-y-2"><legend className="mb-2 text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Assigned curricula</legend><CurriculumChecks options={curricula} selected={form.data.curriculum_ids} onChange={updateCurriculum} />{form.errors.curriculum_ids && <p className="text-xs text-red-700">{form.errors.curriculum_ids}</p>}</fieldset>
                            <fieldset className="space-y-2 border-t border-[#e8ece5] pt-4"><legend className="mb-1 text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Initial modules</legend><p className="mb-3 text-xs text-[#829087]">Select the modules available to this school at launch. People is included for every school.</p><div className="grid gap-2 sm:grid-cols-2">{availableModules.map((module) => <label key={module.key} className="flex cursor-pointer items-start gap-3 rounded-md border border-[#e4e9e1] p-3 hover:bg-[#f7f8f4]"><input type="checkbox" checked={form.data.enabled_modules.includes(module.key)} disabled={module.key === 'users'} onChange={(event) => updateInitialModule(module.key, event.target.checked)} className="mt-1 accent-[#356344]" /><span className="min-w-0 flex-1"><span className="block text-sm font-medium text-[#34463a]">{module.name}{module.key === 'users' && <span className="ml-2 text-[10px] text-[#819087]">Required</span>}</span>{module.description && <span className="mt-0.5 block text-xs text-[#829087]">{module.description}</span>}</span>{module.key !== 'users' && <span className="shrink-0 text-[10px] font-semibold text-[#8d6336]">{module.price_kes ? `KES ${Number(module.price_kes).toLocaleString('en-KE')} / mo` : 'Pricing pending'}</span>}</label>)}</div>{form.errors.enabled_modules && <p className="text-xs text-red-700">{form.errors.enabled_modules}</p>}</fieldset>
                            <fieldset className="space-y-3 border-t border-[#e8ece5] pt-4">
                                <legend className="text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Initial school administrator</legend>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    {([
                                        ['admin_name', 'Administrator name', 'Full name'],
                                        ['admin_email', 'Administrator email', 'admin@school.ke'],
                                        ['admin_password', 'Temporary password', 'Leave blank to generate'],
                                    ] as const).map(([field, label, placeholder]) => <label key={field} className="text-xs font-medium text-[#68776c]">{label}<input type={field === 'admin_password' ? 'password' : 'text'} value={form.data[field]} onChange={(event) => form.setData(field, event.target.value)} placeholder={placeholder} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]" />{form.errors[field] && <span className="mt-1 block text-xs text-red-700">{form.errors[field]}</span>}</label>)}
                                </div>
                            </fieldset>
                            <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4">
                                <button type="button" onClick={() => setShowCreate(false)} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm font-medium text-[#596b5d] hover:bg-[#f0f3ed]">Cancel</button>
                                <button disabled={form.processing || !curricula.length} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-50"><Check aria-hidden="true" className="size-4" /> Create school</button>
                            </div>
                            {form.errors.admin_email && <p className="text-right text-xs text-red-700">{form.errors.admin_email}</p>}
                        </form>
                    </section>
                </div>
            )}
        </AppLayout>
    );
}