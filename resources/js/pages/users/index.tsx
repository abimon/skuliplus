import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDownToLine, ArrowLeft, ArrowRight, BookUser, FileUp, IdCard, Pencil, Plus, Search, Users, X } from 'lucide-react';
import { useRef, useState } from 'react';

type Role = 'student' | 'parent' | 'teacher' | 'hod_academics' | 'support_staff' | 'librarian' | 'lab_technician' | 'gatekeeper' | 'finance_officer' | 'stores_officer';
type Profile = { boarding_status?: string; previous_school?: string; rank?: string; tsc_number?: string; qualification?: string; specialization?: string; relationship?: string; occupation?: string; workplace?: string; staff_category?: string; duty_station?: string };
type DirectoryUser = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    gender: string | null;
    admission_number: string | null;
    employee_number: string | null;
    date_of_birth: string | null;
    roles: { name: Role }[];
    guardians: { id: number; name: string }[];
    student_profile?: Profile;
    teacher_profile?: Profile;
    parent_profile?: Profile;
    staff_profile?: Profile;
};
type UserForm = {
    [key: string]: string | number | null;
    first_name: string;
    last_name: string;
    role: Role;
    email: string;
    phone: string;
    gender: string;
    date_of_birth: string;
    admission_number: string;
    employee_number: string;
    guardian_id: number | null;
    relationship: string;
    boarding_status: string;
    previous_school: string;
    rank: string;
    staff_category: string;
    duty_station: string;
    tsc_number: string;
    qualification: string;
    specialization: string;
    occupation: string;
    workplace: string;
    password: string;
};
type PaginationLink = { url: string | null; label: string; active: boolean };
type PageData = { data: DirectoryUser[]; links: PaginationLink[]; current_page: number; last_page: number; total: number };
type GuardianOption = { id: number; name: string; email: string | null };

const roleOptions: { value: Role; label: string }[] = [
    { value: 'student', label: 'Student' }, { value: 'parent', label: 'Parent / guardian' },
    { value: 'teacher', label: 'Teacher' }, { value: 'hod_academics', label: 'HOD academics' },
    { value: 'support_staff', label: 'Support staff' }, { value: 'librarian', label: 'Librarian' },
    { value: 'lab_technician', label: 'Lab technician' }, { value: 'gatekeeper', label: 'Gatekeeper' },
    { value: 'finance_officer', label: 'Finance officer' }, { value: 'stores_officer', label: 'Stores officer' },
];
const staffRoles: Role[] = ['teacher', 'hod_academics', 'support_staff', 'librarian', 'lab_technician', 'gatekeeper', 'finance_officer', 'stores_officer'];
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'People', href: '/users' }];
const emptyForm = (): UserForm => ({
    first_name: '', last_name: '', role: 'student', email: '', phone: '', gender: '', date_of_birth: '',
    admission_number: '', employee_number: '', guardian_id: null, relationship: 'parent', boarding_status: 'day',
    previous_school: '', rank: 'teacher', staff_category: 'support_staff', duty_station: '', tsc_number: '',
    qualification: '', specialization: '', occupation: '', workplace: '', password: '',
});

function humanRole(role?: string) {
    return roleOptions.find((option) => option.value === role)?.label ?? role?.replaceAll('_', ' ') ?? 'User';
}

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <label className="block text-xs font-medium text-[#657469]">{label}{children}{error && <span className="mt-1 block text-xs text-red-700">{error}</span>}</label>;
}

export default function UsersIndex({ school, users, filters, roles, guardians, can }: {
    school: { name: string; code: string; county: string | null };
    users: PageData;
    filters: { search?: string; role?: string };
    roles: Role[];
    guardians: GuardianOption[];
    can: { create: boolean; update: boolean; import: boolean; id_cards: boolean };
}) {
    const [editingUser, setEditingUser] = useState<DirectoryUser | null>(null);
    const [showForm, setShowForm] = useState(false);
    const [search, setSearch] = useState(filters.search ?? '');
    const [selectedRole, setSelectedRole] = useState(filters.role ?? '');
    const uploadRef = useRef<HTMLInputElement>(null);
    const userForm = useForm<UserForm>(emptyForm());
    const importForm = useForm<{ file: File | null }>({ file: null });

    function openCreate() {
        setEditingUser(null);
        userForm.setData(emptyForm());
        userForm.clearErrors();
        setShowForm(true);
    }

    function openEdit(user: DirectoryUser) {
        const role = user.roles[0]?.name ?? 'support_staff';
        const profile = user.student_profile ?? user.teacher_profile ?? user.parent_profile ?? user.staff_profile ?? {};
        const names = user.name.trim().split(/\s+/);
        setEditingUser(user);
        userForm.setData({
            ...emptyForm(),
            first_name: names[0] ?? '',
            last_name: names.slice(1).join(' '),
            role,
            email: user.email ?? '',
            phone: user.phone ?? '',
            gender: user.gender ?? '',
            date_of_birth: user.date_of_birth?.slice(0, 10) ?? '',
            admission_number: user.admission_number ?? '',
            employee_number: user.employee_number ?? '',
            guardian_id: user.guardians[0]?.id ?? null,
            relationship: profile.relationship ?? 'parent',
            boarding_status: profile.boarding_status ?? 'day',
            previous_school: profile.previous_school ?? '',
            rank: profile.rank ?? 'teacher',
            staff_category: profile.staff_category ?? role,
            duty_station: profile.duty_station ?? '',
            tsc_number: profile.tsc_number ?? '',
            qualification: profile.qualification ?? '',
            specialization: profile.specialization ?? '',
            occupation: profile.occupation ?? '',
            workplace: profile.workplace ?? '',
            password: '',
        });
        userForm.clearErrors();
        setShowForm(true);
    }

    function submitUser(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => { setShowForm(false); setEditingUser(null); userForm.reset(); } };
        if (editingUser) userForm.put(`/users/${editingUser.id}`, options);
        else userForm.post('/users', options);
    }

    function runFilter(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        router.get('/users', { search: search || undefined, role: selectedRole || undefined }, { preserveState: true, replace: true });
    }

    function importCsv(file: File | null) {
        if (!file) return;
        importForm.setData('file', file);
        importForm.post('/users/import', { forceFormData: true, preserveScroll: true, onSuccess: () => { importForm.reset(); if (uploadRef.current) uploadRef.current.value = ''; } });
    }

    const role = userForm.data.role;
    const isStudent = role === 'student';
    const isTeacher = role === 'teacher' || role === 'hod_academics';
    const isStaff = staffRoles.includes(role);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="People" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1440px]">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{school.county ? `${school.county} County · ` : ''}{school.code}</p>
                            <h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">People</h1>
                            <p className="mt-2 text-sm text-[#718076]">Learners, families, teachers and school staff at {school.name}.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {can.import && <><a href="/users/import-template" className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]"><ArrowDownToLine aria-hidden="true" className="size-4" /> CSV template</a><button onClick={() => uploadRef.current?.click()} disabled={importForm.processing} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]"><FileUp aria-hidden="true" className="size-4" /> Import CSV</button><input ref={uploadRef} type="file" accept=".csv,text/csv" className="sr-only" onChange={(event) => importCsv(event.target.files?.[0] ?? null)} /></>}
                            {can.create && <button onClick={openCreate} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a]"><Plus aria-hidden="true" className="size-4" /> Add user</button>}
                        </div>
                    </header>

                    {importForm.errors.file && <p role="alert" className="mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{importForm.errors.file}</p>}

                    <section className="mt-7 rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-5">
                        <div className="flex flex-wrap items-center justify-between gap-4">
                            <div className="flex items-center gap-3">
                                <span className="flex size-10 items-center justify-center rounded-md bg-[#e4f0e9] text-[#236345]"><Users aria-hidden="true" className="size-5" /></span>
                                <div><p className="text-xl font-semibold text-[#263c2d]">{users.total.toLocaleString('en-KE')}</p><p className="text-xs text-[#7b877e]">people in this school</p></div>
                            </div>
                            <p className="text-xs text-[#849087]">Page {users.current_page} of {users.last_page || 1}</p>
                        </div>
                        <form onSubmit={runFilter} className="mt-4 grid gap-2 sm:grid-cols-[minmax(200px,1fr)_220px_auto]">
                            <label className="relative"><span className="sr-only">Search users</span><Search aria-hidden="true" className="absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#869188]" /><input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Name, email or admission number" className="w-full rounded-md border border-[#dfe5dc] py-2.5 pr-3 pl-9 text-sm outline-none focus:border-[#77947a]" /></label>
                            <label><span className="sr-only">Filter by role</span><select value={selectedRole} onChange={(event) => setSelectedRole(event.target.value)} className="w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#46594a] outline-none focus:border-[#77947a]"><option value="">All roles</option>{roles.map((item) => <option key={item} value={item}>{humanRole(item)}</option>)}</select></label>
                            <button className="rounded-md border border-[#dfe5dc] px-4 py-2.5 text-sm font-semibold text-[#48624e] hover:bg-[#f5f7f2]">Search</button>
                        </form>
                    </section>

                    <section className="mt-4 overflow-hidden rounded-lg border border-[#e3e8df] bg-white">
                        <div className="hidden grid-cols-[minmax(180px,1.2fr)_minmax(130px,0.8fr)_minmax(160px,1fr)_minmax(140px,0.8fr)_auto] gap-4 border-b border-[#e8ece5] bg-[#f9faf7] px-5 py-3 text-[10px] font-semibold tracking-[0.1em] text-[#869087] uppercase lg:grid">
                            <span>Name</span><span>Role</span><span>School identifier</span><span>Contact</span><span>Actions</span>
                        </div>
                        {users.data.map((user) => {
                            const userRole = user.roles[0]?.name;
                            const identifier = user.admission_number ?? user.employee_number ?? '—';
                            return (
                                <article key={user.id} className="grid gap-3 border-b border-[#edf0eb] px-4 py-4 last:border-b-0 sm:px-5 lg:grid-cols-[minmax(180px,1.2fr)_minmax(130px,0.8fr)_minmax(160px,1fr)_minmax(140px,0.8fr)_auto] lg:items-center lg:gap-4">
                                    <div className="flex min-w-0 items-center gap-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#edf2e9] text-xs font-semibold text-[#53715a]">{user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</span>
                                        <div className="min-w-0"><p className="truncate text-sm font-semibold text-[#33463a]">{user.name}</p><p className="truncate text-xs text-[#829087]">{user.email ?? 'Student account · no login'}</p></div>
                                    </div>
                                    <div><span className="text-[10px] text-[#869087] uppercase lg:hidden">Role · </span><span className="inline-flex rounded-sm bg-[#f0f4ee] px-2 py-1 text-xs font-medium text-[#59715e]">{humanRole(userRole)}</span>{user.teacher_profile?.rank && <p className="mt-1 text-[11px] text-[#849087]">{user.teacher_profile.rank.replaceAll('_', ' ')}</p>}</div>
                                    <div><span className="text-[10px] text-[#869087] uppercase lg:hidden">Identifier · </span><span className="text-sm text-[#536458]">{identifier}</span>{user.guardians[0] && <p className="mt-1 truncate text-[11px] text-[#849087]">Guardian: {user.guardians[0].name}</p>}</div>
                                    <div className="truncate text-sm text-[#68776c]">{user.phone ?? '—'}</div>
                                    <div className="flex items-center gap-1 lg:justify-end">
                                        {can.update && <button onClick={() => openEdit(user)} aria-label={`Edit ${user.name}`} title="Edit user" className="rounded-md p-2 text-[#68796c] hover:bg-[#f0f4ee]"><Pencil aria-hidden="true" className="size-4" /></button>}
                                        {can.id_cards && (userRole === 'student' || staffRoles.includes(userRole as Role)) && <Link href={`/users/${user.id}/id-card`} aria-label={`Print ${user.name} ID card`} title="Print ID card" className="rounded-md p-2 text-[#68796c] hover:bg-[#f0f4ee]"><IdCard aria-hidden="true" className="size-4" /></Link>}
                                    </div>
                                </article>
                            );
                        })}
                        {!users.data.length && <div className="p-10 text-center"><BookUser aria-hidden="true" className="mx-auto size-7 text-[#87a08a]" /><p className="mt-3 text-sm font-medium text-[#394c3f]">No people match this search</p><p className="mt-1 text-xs text-[#849087]">Try a different name or role filter.</p></div>}
                    </section>

                    {users.links.length > 3 && <nav aria-label="Pagination" className="mt-4 flex flex-wrap justify-end gap-1">{users.links.map((link, index) => <button key={`${index}-${link.label}`} disabled={!link.url} onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })} className={`min-w-9 rounded-md px-3 py-2 text-sm ${link.active ? 'bg-[#244b35] font-semibold text-white' : 'border border-[#dfe5dc] bg-white text-[#607064] hover:bg-[#f4f6f1]'} disabled:cursor-not-allowed disabled:opacity-40`}>{index === 0 ? <ArrowLeft aria-hidden="true" className="mx-auto size-4" /> : index === users.links.length - 1 ? <ArrowRight aria-hidden="true" className="mx-auto size-4" /> : <span dangerouslySetInnerHTML={{ __html: link.label }} />}</button>)}</nav>}
                </div>
            </main>

            {showForm && (
                <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowForm(false); }}>
                    <section role="dialog" aria-modal="true" aria-labelledby="user-form-title" className="my-auto w-full max-w-3xl rounded-lg bg-[#fbfcf8] p-5 shadow-2xl sm:p-7">
                        <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{editingUser ? 'Update school record' : 'New school record'}</p><h2 id="user-form-title" className="mt-1 font-serif text-2xl text-[#183b2a]">{editingUser ? 'Edit person' : 'Register a person'}</h2></div><button aria-label="Close" onClick={() => setShowForm(false)} className="rounded-md p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div>
                        <form onSubmit={submitUser} className="mt-6 space-y-5">
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field label="First name" error={userForm.errors.first_name}><input required value={userForm.data.first_name} onChange={(event) => userForm.setData('first_name', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                                <Field label="Last name" error={userForm.errors.last_name}><input required value={userForm.data.last_name} onChange={(event) => userForm.setData('last_name', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                                <Field label="School role" error={userForm.errors.role}><select value={role} onChange={(event) => { const nextRole = event.target.value as Role; userForm.setData((data) => ({ ...data, role: nextRole, staff_category: staffRoles.includes(nextRole) && !['teacher', 'hod_academics'].includes(nextRole) ? nextRole : data.staff_category })); }} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]">{roleOptions.filter((option) => roles.includes(option.value)).map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}</select></Field>
                                <Field label="Phone" error={userForm.errors.phone}><input value={userForm.data.phone} onChange={(event) => userForm.setData('phone', event.target.value)} placeholder="07xx xxx xxx" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                                <Field label="Gender" error={userForm.errors.gender}><select value={userForm.data.gender} onChange={(event) => userForm.setData('gender', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Not specified</option><option value="female">Female</option><option value="male">Male</option><option value="other">Other</option></select></Field>
                                <Field label="Date of birth" error={userForm.errors.date_of_birth}><input type="date" value={userForm.data.date_of_birth} onChange={(event) => userForm.setData('date_of_birth', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                            </div>

                            {!isStudent && <div className="grid gap-3 border-t border-[#e8ece5] pt-4 sm:grid-cols-2"><Field label="Email address · login required" error={userForm.errors.email}><input type="email" required value={userForm.data.email} onChange={(event) => userForm.setData('email', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label={editingUser && !editingUser.roles.some((item) => item.name === 'student') ? 'New password · optional' : 'Temporary password · 10+ characters'} error={userForm.errors.password}><input type="password" required={!editingUser || editingUser.roles.some((item) => item.name === 'student')} minLength={10} value={userForm.data.password} onChange={(event) => userForm.setData('password', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></div>}

                            {isStudent && <fieldset className="space-y-3 border-t border-[#e8ece5] pt-4"><legend className="text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Learner details</legend><div className="grid gap-3 sm:grid-cols-2"><Field label="Admission number" error={userForm.errors.admission_number}><input required value={userForm.data.admission_number} onChange={(event) => userForm.setData('admission_number', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Parent / guardian" error={userForm.errors.guardian_id}><select required value={userForm.data.guardian_id ?? ''} onChange={(event) => userForm.setData('guardian_id', event.target.value ? Number(event.target.value) : null)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select a school guardian</option>{guardians.map((guardian) => <option key={guardian.id} value={guardian.id}>{guardian.name}{guardian.email ? ` · ${guardian.email}` : ''}</option>)}</select></Field><Field label="Boarding status" error={userForm.errors.boarding_status}><select value={userForm.data.boarding_status} onChange={(event) => userForm.setData('boarding_status', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="day">Day learner</option><option value="boarding">Boarding</option></select></Field><Field label="Previous school · transfer record" error={userForm.errors.previous_school}><input value={userForm.data.previous_school} onChange={(event) => userForm.setData('previous_school', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></div><p className="text-xs text-[#79867d]">Learners do not sign in; they are linked to a parent or guardian account.</p></fieldset>}

                            {role === 'parent' && <fieldset className="grid gap-3 border-t border-[#e8ece5] pt-4 sm:grid-cols-2"><legend className="text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Family profile</legend><Field label="Relationship" error={userForm.errors.relationship}><input value={userForm.data.relationship} onChange={(event) => userForm.setData('relationship', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Occupation" error={userForm.errors.occupation}><input value={userForm.data.occupation} onChange={(event) => userForm.setData('occupation', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Workplace" error={userForm.errors.workplace}><input value={userForm.data.workplace} onChange={(event) => userForm.setData('workplace', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></fieldset>}

                            {isStaff && <fieldset className="grid gap-3 border-t border-[#e8ece5] pt-4 sm:grid-cols-2"><legend className="text-xs font-semibold tracking-[0.08em] text-[#56675a] uppercase">Staff profile</legend><Field label="Employee number" error={userForm.errors.employee_number}><input value={userForm.data.employee_number} onChange={(event) => userForm.setData('employee_number', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>{isTeacher ? <><Field label="Teacher rank" error={userForm.errors.rank}><select value={userForm.data.rank} onChange={(event) => userForm.setData('rank', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="teacher">Teacher</option><option value="senior_teacher">Senior teacher</option><option value="senior_master">Senior master / mistress</option><option value="deputy_head">Deputy head</option><option value="hod">Head of department</option><option value="headteacher">Headteacher</option></select></Field><Field label="TSC number" error={userForm.errors.tsc_number}><input value={userForm.data.tsc_number} onChange={(event) => userForm.setData('tsc_number', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Qualification" error={userForm.errors.qualification}><input value={userForm.data.qualification} onChange={(event) => userForm.setData('qualification', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Specialization" error={userForm.errors.specialization}><input value={userForm.data.specialization} onChange={(event) => userForm.setData('specialization', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></> : <><Field label="Staff category" error={userForm.errors.staff_category}><select value={userForm.data.staff_category} onChange={(event) => userForm.setData('staff_category', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="support_staff">Support staff</option><option value="librarian">Librarian</option><option value="lab_technician">Lab technician</option><option value="gatekeeper">Gatekeeper</option><option value="finance_officer">Finance officer</option><option value="stores_officer">Stores officer</option></select></Field><Field label="Duty station" error={userForm.errors.duty_station}><input value={userForm.data.duty_station} onChange={(event) => userForm.setData('duty_station', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></>}</fieldset>}

                            <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={() => setShowForm(false)} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm font-medium text-[#596b5d] hover:bg-[#f0f3ed]">Cancel</button><button disabled={userForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-50">{editingUser ? 'Save profile' : 'Register user'}</button></div>
                            {userForm.hasErrors && <p role="alert" className="text-right text-xs text-red-700">Review the highlighted fields.</p>}
                        </form>
                    </section>
                </div>
            )}
        </AppLayout>
    );
}
