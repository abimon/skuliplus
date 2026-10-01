import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDownToLine, ArrowLeft, Mail, Printer } from 'lucide-react';
import { useState } from 'react';

type Student = { id: number; name: string; admission_number: string | null; exam_results_count: number };
const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Academics', href: '/academics' }, { title: 'Results & trends', href: '/academics/reports' }];

export default function ReportsIndex({ school, students, canEmail }: { school: { name: string; code: string; county: string | null }; students: Student[]; canEmail: boolean }) {
    const [filter, setFilter] = useState('');
    const form = useForm({ student_ids: [] as number[] });
    const shown = students.filter((student) => `${student.name} ${student.admission_number ?? ''}`.toLowerCase().includes(filter.toLowerCase()));

    function toggle(studentId: number, selected: boolean) {
        form.setData('student_ids', selected ? [...form.data.student_ids, studentId] : form.data.student_ids.filter((id) => id !== studentId));
    }

    function emailReports() {
        form.post('/academics/results/email', { preserveScroll: true, onSuccess: () => form.setData('student_ids', []) });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Results & trends" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1100px]">
                    <header className="flex flex-wrap items-end justify-between gap-4"><div><Link href="/academics" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#5f7564] hover:text-[#294a34]"><ArrowLeft aria-hidden="true" className="size-4" /> Academics</Link><p className="mt-3 text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{school.county ? `${school.county} County · ` : ''}{school.code}</p><h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Results &amp; trends</h1><p className="mt-2 text-sm text-[#718076]">Published assessments across terms and academic years at {school.name}.</p></div>{canEmail && <button onClick={emailReports} disabled={!form.data.student_ids.length || form.processing} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Mail aria-hidden="true" className="size-4" /> Email selected ({form.data.student_ids.length})</button>}</header>

                    <section className="mt-7 overflow-hidden rounded-lg border border-[#e3e8df] bg-white">
                        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8ece5] bg-[#f8faf6] px-4 py-3"><div><h2 className="text-sm font-semibold text-[#34483a]">Learner reports</h2><p className="mt-0.5 text-xs text-[#829087]">{students.length} learners with published assessments</p></div><input value={filter} onChange={(event) => setFilter(event.target.value)} placeholder="Filter by learner or admission number" className="w-full max-w-xs rounded-md border border-[#dfe5dc] bg-white px-3 py-2 text-sm outline-none focus:border-[#77947a]" /></div>
                        {canEmail && <div className="flex items-center justify-between border-b border-[#edf0eb] px-4 py-2 text-xs text-[#7d887f]"><label className="flex items-center gap-2"><input type="checkbox" checked={!!shown.length && shown.every((student) => form.data.student_ids.includes(student.id))} onChange={(event) => form.setData('student_ids', event.target.checked ? [...new Set([...form.data.student_ids, ...shown.map((student) => student.id)])] : form.data.student_ids.filter((id) => !shown.some((student) => student.id === id)))} className="accent-[#356344]" /> Select filtered learners</label><span>Reports go to each learner’s linked guardian</span></div>}
                        <div className="divide-y divide-[#edf0eb]">{shown.map((student) => <article key={student.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><div className="flex min-w-0 items-center gap-3">{canEmail && <input aria-label={`Select ${student.name}`} type="checkbox" checked={form.data.student_ids.includes(student.id)} onChange={(event) => toggle(student.id, event.target.checked)} className="accent-[#356344]" />}<span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-[#edf2e9] text-xs font-semibold text-[#53715a]">{student.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</span><div className="min-w-0"><p className="truncate text-sm font-semibold text-[#35483a]">{student.name}</p><p className="mt-0.5 text-xs text-[#829087]">{student.admission_number ?? 'No admission number'} · {student.exam_results_count} result entries</p></div></div><div className="flex gap-1"><Link href={`/students/${student.id}/results`} aria-label={`View ${student.name} results`} className="rounded-md p-2 text-[#59735d] hover:bg-[#f0f4ee]" title="View report"><Printer aria-hidden="true" className="size-4" /></Link><a href={`/students/${student.id}/results.pdf`} aria-label={`Download ${student.name} report`} className="rounded-md p-2 text-[#59735d] hover:bg-[#f0f4ee]" title="Download PDF"><ArrowDownToLine aria-hidden="true" className="size-4" /></a></div></article>)}{!shown.length && <p className="p-9 text-center text-sm text-[#829087]">No published learner reports match this filter.</p>}</div>
                    </section>
                </div>
            </main>
        </AppLayout>
    );
}
