import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Bell, BookOpenCheck, Check, CircleDollarSign, Inbox, X } from 'lucide-react';

type Module = { id: number; key: string; name: string; description: string | null; price_kes: string | null; is_active: boolean };
type Curriculum = { id: number; code: string; name: string; is_active: boolean; schools_count: number };
type CentralRequest = {
    id: number;
    type: string;
    subject: string;
    message: string | null;
    payload: Record<string, string> | null;
    status: string;
    created_at: string;
    school: { id: number; name: string; code: string } | null;
    requester: { id: number; name: string; email: string } | null;
};
type Pagination<T> = { data: T[]; current_page: number; last_page: number; total: number; links: { url: string | null; label: string; active: boolean }[] };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Central management', href: '/admin/management' }];
const controlClass = 'h-10 rounded-sm border border-[#dce3da] bg-white px-3 text-sm text-[#34463a] outline-none focus:border-[#77947a]';

function ModulePricingRow({ module }: { module: Module }) {
    const form = useForm({ price_kes: module.key === 'users' ? '' : module.price_kes ?? '', is_active: module.is_active });

    return <form onSubmit={(event) => { event.preventDefault(); form.transform((data) => module.key === 'users' ? { is_active: data.is_active } : data); form.put(`/admin/management/modules/${module.id}`, { preserveScroll: true }); }} className="grid gap-3 border-b border-[#edf0eb] py-4 last:border-0 sm:grid-cols-[minmax(0,1fr)_170px_auto_auto] sm:items-center">
        <div><p className="text-sm font-semibold text-[#33483a]">{module.name}</p><p className="mt-1 text-xs text-[#839087]">{module.description}</p></div>
        {module.key === 'users' ? <span className="text-xs text-[#7c887f]">Core module · no charge</span> : <label className="text-[10px] font-semibold uppercase text-[#819087]">KES / month<input type="number" min="0" step="0.01" value={form.data.price_kes} onChange={(event) => form.setData('price_kes', event.target.value)} className={`${controlClass} mt-1 block w-full`} /></label>}
        <label className="flex items-center gap-2 text-xs text-[#58685c]"><input type="checkbox" checked={form.data.is_active} onChange={(event) => form.setData('is_active', event.target.checked)} className="size-4 accent-[#356344]" />Active</label>
        <button disabled={form.processing} className="inline-flex h-10 items-center justify-center gap-1.5 bg-[#244b35] px-3 text-xs font-semibold text-white disabled:opacity-50"><Check aria-hidden="true" className="size-3.5" />Save</button>
        {form.errors.price_kes && <p className="text-xs text-red-700 sm:col-span-4">{form.errors.price_kes}</p>}
    </form>;
}

function RequestRow({ item }: { item: CentralRequest }) {
    const form = useForm({ decision: 'approve', resolution_note: '' });
    const activation = ['module_activation', 'curriculum_activation'].includes(item.type);
    const sender = item.school ? `${item.school.name} · ${item.school.code}` : item.requester?.name ?? item.payload?.name ?? item.payload?.email ?? 'Public visitor';

    function handle(decision: 'approve' | 'decline' | 'close') {
        form.setData('decision', decision);
        form.patch(`/admin/management/requests/${item.id}`, { preserveScroll: true });
    }

    return <article className="border-b border-[#e8ece5] py-5 last:border-0">
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2"><span className="rounded-sm bg-[#eef3eb] px-2 py-1 text-[10px] font-semibold uppercase text-[#54705a]">{item.type.replaceAll('_', ' ')}</span><span className={`rounded-sm px-2 py-1 text-[10px] font-semibold uppercase ${item.status === 'pending' ? 'bg-[#f5efdf] text-[#8a6c34]' : 'bg-[#e8f1e8] text-[#3c7147]'}`}>{item.status}</span><span className="text-[10px] text-[#89948b]">{new Date(item.created_at).toLocaleString('en-KE')}</span></div>
                <h3 className="mt-2 text-sm font-semibold text-[#2f4435]">{item.subject}</h3>
                <p className="mt-1 text-xs text-[#6f7c72]">From {sender}{item.requester?.email || item.payload?.email ? ` · ${item.requester?.email ?? item.payload?.email}` : ''}</p>
                {item.message && <p className="mt-3 whitespace-pre-wrap text-sm leading-6 text-[#657469]">{item.message}</p>}
                {item.payload?.phone && <p className="mt-2 text-xs text-[#7c887f]">Phone: {item.payload.phone}</p>}
                {item.payload?.school && <p className="mt-1 text-xs text-[#7c887f]">School: {item.payload.school}</p>}
                {item.payload?.role && <p className="mt-1 text-xs text-[#7c887f]">Role: {item.payload.role}</p>}
                {item.status === 'pending' && <textarea aria-label="Resolution note" rows={2} value={form.data.resolution_note} onChange={(event) => form.setData('resolution_note', event.target.value)} placeholder="Internal resolution note (optional)" className="mt-3 w-full resize-y rounded-sm border border-[#dce3da] bg-white px-3 py-2 text-xs outline-none focus:border-[#77947a]" />}
            </div>
            {item.status === 'pending' && <div className="flex shrink-0 flex-wrap gap-2">
                {activation && <button type="button" onClick={() => handle('approve')} disabled={form.processing} className="inline-flex h-9 items-center gap-1.5 bg-[#244b35] px-3 text-xs font-semibold text-white disabled:opacity-50"><Check aria-hidden="true" className="size-3.5" />Approve &amp; activate</button>}
                <button type="button" onClick={() => handle(activation ? 'decline' : 'close')} disabled={form.processing} className="inline-flex h-9 items-center gap-1.5 border border-[#dce3da] px-3 text-xs font-semibold text-[#647469] disabled:opacity-50">{activation ? <X aria-hidden="true" className="size-3.5" /> : <Check aria-hidden="true" className="size-3.5" />}{activation ? 'Decline' : 'Mark handled'}</button>
            </div>}
        </div>
    </article>;
}

export default function CentralManagement({ modules, curricula, requests, requestFilter, stats }: {
    modules: Module[];
    curricula: Curriculum[];
    requests: Pagination<CentralRequest>;
    requestFilter: string;
    stats: { pending: number; pending_modules: number; schools: number; active_schools: number };
}) {
    return <AppLayout breadcrumbs={breadcrumbs}>
        <Head title="Central management" />
        <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
            <div className="mx-auto max-w-[1320px]">
                <header className="flex flex-wrap items-end justify-between gap-4"><div><p className="text-xs font-semibold uppercase text-[#7d897f]">National administration</p><h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Central management</h1><p className="mt-2 text-sm text-[#718076]">Module pricing, curriculum availability, and requests from schools and prospective members.</p></div><span className="inline-flex items-center gap-2 border border-[#e0e5dc] bg-white px-3 py-2 text-xs text-[#657469]"><Bell aria-hidden="true" className="size-4 text-[#a06a33]" />{stats.pending} pending</span></header>

                <section className="mt-7 grid gap-3 sm:grid-cols-3">{[['Pending requests', stats.pending], ['Schools', stats.schools], ['Active schools', stats.active_schools]].map(([label, value]) => <article key={String(label)} className="border border-[#e3e8df] bg-white p-4"><p className="text-xs font-semibold uppercase text-[#7c887f]">{label}</p><p className="mt-2 text-2xl font-semibold text-[#203a2a]">{Number(value).toLocaleString('en-KE')}</p></article>)}</section>

                <section className="mt-7 border border-[#e3e8df] bg-white px-5 sm:px-7">
                    <div className="flex items-center gap-3 border-b border-[#e8ece5] py-5"><CircleDollarSign aria-hidden="true" className="size-5 text-[#a06a33]" /><div><h2 className="font-serif text-xl text-[#1b3b2b]">Module pricing</h2><p className="mt-1 text-xs text-[#829087]">Set monthly KES pricing and central availability. People remains the included core module.</p></div></div>
                    {modules.map((module) => <ModulePricingRow key={module.id} module={module} />)}
                </section>

                <section className="mt-7 border border-[#e3e8df] bg-white px-5 sm:px-7">
                    <div className="flex items-center gap-3 border-b border-[#e8ece5] py-5"><BookOpenCheck aria-hidden="true" className="size-5 text-[#55775d]" /><div><h2 className="font-serif text-xl text-[#1b3b2b]">Curriculum pathways</h2><p className="mt-1 text-xs text-[#829087]">Inactive pathways remain visible to school administrators, who can request access.</p></div></div>
                    {curricula.map((curriculum) => <form key={curriculum.id} onSubmit={(event) => { event.preventDefault(); router.patch(`/admin/management/curricula/${curriculum.id}`, { is_active: !curriculum.is_active }, { preserveScroll: true }); }} className="flex flex-wrap items-center justify-between gap-4 border-b border-[#edf0eb] py-4 last:border-0"><div><p className="text-sm font-semibold text-[#34483a]">{curriculum.name} <span className="ml-1 text-xs font-normal text-[#849087]">{curriculum.code}</span></p><p className="mt-1 text-xs text-[#849087]">Assigned to {curriculum.schools_count} schools</p></div><div className="flex items-center gap-3"><span className={`rounded-sm px-2 py-1 text-[10px] font-semibold uppercase ${curriculum.is_active ? 'bg-[#e8f1e8] text-[#3c7147]' : 'bg-[#f3eee2] text-[#92723f]'}`}>{curriculum.is_active ? 'Active' : 'Inactive'}</span><button className="h-9 border border-[#dce3da] px-3 text-xs font-semibold text-[#4e6754]">{curriculum.is_active ? 'Deactivate' : 'Activate'}</button></div></form>)}
                </section>

                <section className="mt-7 border border-[#e3e8df] bg-white px-5 sm:px-7">
                    <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8ece5] py-5"><div className="flex items-center gap-3"><Inbox aria-hidden="true" className="size-5 text-[#55775d]" /><div><h2 className="font-serif text-xl text-[#1b3b2b]">Central inbox</h2><p className="mt-1 text-xs text-[#829087]">Demo requests, contact messages, school activation requests, feedback and testimonials.</p></div></div><div className="flex items-center gap-3"><span className="rounded-sm bg-[#edf3eb] px-2 py-1 text-[10px] font-semibold text-[#4f7155]">{stats.pending_modules} module activations</span><span className="text-xs text-[#829087]">{requests.total} requests</span></div></div>
                    <div className="flex flex-wrap gap-2 border-b border-[#edf0eb] py-3" aria-label="Filter central requests">{[['all', 'All'], ['module_activation', 'Module activation'], ['curriculum_activation', 'Curriculum activation'], ['demo', 'Demo'], ['contact', 'Contact'], ['feedback', 'Feedback'], ['testimonial', 'Testimonials'], ['inquiry', 'Inquiries']].map(([key, label]) => <button key={key} type="button" onClick={() => router.get('/admin/management', key === 'all' ? {} : { type: key }, { preserveScroll: true, preserveState: true, replace: true })} aria-pressed={requestFilter === key} className={`h-8 border px-2.5 text-[11px] font-semibold ${requestFilter === key ? 'border-[#315c3d] bg-[#eaf1e8] text-[#244c32]' : 'border-[#dfe5dc] text-[#718076] hover:bg-[#f7f8f4]'}`}>{label}{key === 'module_activation' && stats.pending_modules > 0 ? ` · ${stats.pending_modules}` : ''}</button>)}</div>
                    {requests.data.length ? requests.data.map((item) => <RequestRow key={item.id} item={item} />) : <p className="py-10 text-center text-sm text-[#829087]">No requests received yet.</p>}
                    {requests.last_page > 1 && <nav aria-label="Inbox pages" className="flex justify-end gap-1 border-t border-[#edf0eb] py-4">{requests.links.map((link, index) => <button type="button" key={`${index}-${link.label}`} disabled={!link.url} onClick={() => link.url && router.visit(link.url, { preserveScroll: true, preserveState: true })} className={`min-w-9 px-3 py-2 text-xs ${link.active ? 'bg-[#244b35] font-semibold text-white' : 'border border-[#dfe5dc] text-[#647469]'} disabled:opacity-40`}><span dangerouslySetInnerHTML={{ __html: link.label }} /></button>)}</nav>}
                </section>
            </div>
        </main>
    </AppLayout>;
}