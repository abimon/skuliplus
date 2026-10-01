import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { ArrowRight, GraduationCap } from 'lucide-react';
import { useMemo, useState } from 'react';

type Stream = { id: number; name: string; class_teacher_id: number | null; capacity: number };
type SchoolClass = { id: number; name: string; academic_year_id: number; academic_year: { id: number; name: string; starts_on: string }; level: { stage: string; name: string }; streams: Stream[] };
type Enrollment = { id: number; student_id: number; school_class_id: number; stream_id: number | null; student: { id: number; name: string; admission_number: string | null }; school_class: { id: number; name: string; academic_year_id: number }; stream: { id: number; name: string } | null };
type Promotion = { id: number; decision: string; created_at: string; student: { name: string; admission_number: string | null }; from_class: { name: string } | null; to_class: { name: string } | null };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Promotions', href: '/modules/promotions' }];

export default function Promotions({ school, classes, enrollments, promotions }: { school: { name: string; code: string }; classes: SchoolClass[]; enrollments: Enrollment[]; promotions: Promotion[] }) {
    const form = useForm({ from_stream_id: '', to_class_id: '', to_stream_id: '', student_ids: [] as number[], notes: '' });
    const [sourceFilter, setSourceFilter] = useState('');
    const sourceEnrollment = enrollments.find((enrollment) => String(enrollment.stream_id) === form.data.from_stream_id);
    const sourceClassId = sourceEnrollment?.school_class_id;
    const sourceClass = classes.find((schoolClass) => schoolClass.id === sourceClassId);
    const students = useMemo(() => enrollments.filter((enrollment) => String(enrollment.stream_id) === form.data.from_stream_id && `${enrollment.student.name} ${enrollment.student.admission_number ?? ''}`.toLowerCase().includes(sourceFilter.toLowerCase())), [enrollments, form.data.from_stream_id, sourceFilter]);
    const destinations = classes.filter((schoolClass) => schoolClass.id !== sourceClassId && (sourceClass ? schoolClass.academic_year.starts_on > sourceClass.academic_year.starts_on : true));
    const targetClass = classes.find((schoolClass) => String(schoolClass.id) === form.data.to_class_id);
    const targetStreams = targetClass?.streams ?? [];

    function toggleStudent(studentId: number, checked: boolean) {
        form.setData('student_ids', checked ? [...form.data.student_ids, studentId] : form.data.student_ids.filter((id) => id !== studentId));
    }

    function toggleAll(checked: boolean) {
        form.setData('student_ids', checked ? students.map((enrollment) => enrollment.student_id) : []);
    }

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.post('/modules/promotions/process', { preserveScroll: true, onSuccess: () => { form.reset(); setSourceFilter(''); } });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Promotions" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1240px]">
                    <header><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{school.code} · Academic year transition</p><h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Learner promotions</h1><p className="mt-2 text-sm text-[#718076]">Move a selected class or stream cohort into a later-year class. Previous enrollments remain in the learner record.</p></header>

                    <form onSubmit={submit} className="mt-7 space-y-5 rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-6">
                        <div className="grid gap-4 md:grid-cols-3">
                            <label className="text-xs font-medium text-[#657469]">Source stream<select required value={form.data.from_stream_id} onChange={(event) => form.setData((data) => ({ ...data, from_stream_id: event.target.value, student_ids: [], to_class_id: '', to_stream_id: '' }))} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select source stream</option>{classes.flatMap((schoolClass) => schoolClass.streams.map((stream) => <option key={stream.id} value={stream.id}>{schoolClass.name} · {stream.name} · {schoolClass.academic_year.name}</option>))}</select>{form.errors.from_stream_id && <span className="mt-1 block text-xs text-red-700">{form.errors.from_stream_id}</span>}</label>
                            <label className="text-xs font-medium text-[#657469]">Destination class<select required value={form.data.to_class_id} onChange={(event) => form.setData((data) => ({ ...data, to_class_id: event.target.value, to_stream_id: '' }))} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Choose later year class</option>{destinations.map((schoolClass) => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name} · {schoolClass.academic_year.name}</option>)}</select>{form.errors.to_class_id && <span className="mt-1 block text-xs text-red-700">{form.errors.to_class_id}</span>}</label>
                            <label className="text-xs font-medium text-[#657469]">Destination stream<select disabled={!targetStreams.length} value={form.data.to_stream_id} onChange={(event) => form.setData('to_stream_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a] disabled:opacity-50"><option value="">Unassigned</option>{targetStreams.map((stream) => <option key={stream.id} value={stream.id}>{stream.name} · capacity {stream.capacity}</option>)}</select>{form.errors.to_stream_id && <span className="mt-1 block text-xs text-red-700">{form.errors.to_stream_id}</span>}</label>
                        </div>

                        <section className="overflow-hidden rounded-md border border-[#e6ebe4]">
                            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-[#e8ece5] bg-[#f7f9f5] px-4 py-3"><div><h2 className="text-sm font-semibold text-[#34483a]">Learners to promote</h2><p className="mt-0.5 text-xs text-[#839087]">{form.data.student_ids.length} selected</p></div><div className="flex items-center gap-3"><input aria-label="Filter learners" value={sourceFilter} onChange={(event) => setSourceFilter(event.target.value)} placeholder="Filter name / admission no." className="w-48 rounded-md border border-[#dfe5dc] px-3 py-2 text-xs outline-none focus:border-[#77947a]" /><label className="flex items-center gap-2 text-xs text-[#5d6e61]"><input type="checkbox" checked={!!students.length && students.every((enrollment) => form.data.student_ids.includes(enrollment.student_id))} onChange={(event) => toggleAll(event.target.checked)} className="accent-[#356344]" />Select all</label></div></div>
                            <div className="max-h-80 divide-y divide-[#edf0eb] overflow-auto">{students.map((enrollment) => <label key={enrollment.student_id} className="flex cursor-pointer items-center gap-3 px-4 py-3 hover:bg-[#fbfcf9]"><input type="checkbox" checked={form.data.student_ids.includes(enrollment.student_id)} onChange={(event) => toggleStudent(enrollment.student_id, event.target.checked)} className="accent-[#356344]" /><span className="min-w-0 flex-1"><span className="block truncate text-sm font-medium text-[#35483a]">{enrollment.student.name}</span><span className="mt-0.5 block text-xs text-[#829087]">{enrollment.student.admission_number ?? 'No admission number'} · {enrollment.school_class.name} · {enrollment.stream?.name ?? 'No stream'}</span></span></label>)}{!students.length && <p className="p-8 text-center text-sm text-[#829087]">Select a source stream with active learners.</p>}</div>
                        </section>

                        <label className="block text-xs font-medium text-[#657469]">Notes · optional<textarea value={form.data.notes} onChange={(event) => form.setData('notes', event.target.value)} className="mt-1.5 min-h-16 w-full rounded-md border border-[#dfe5dc] px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></label>
                        {form.errors.student_ids && <p role="alert" className="text-xs text-red-700">{form.errors.student_ids}</p>}
                        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-[#e8ece5] pt-4"><p className="text-xs text-[#7c887f]">Promoting creates next-year enrollments; historic records are retained.</p><button disabled={form.processing || !form.data.student_ids.length || !form.data.to_class_id} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><ArrowRight aria-hidden="true" className="size-4" /> Promote selected</button></div>
                    </form>

                    <section className="mt-8"><div className="mb-3 flex items-end justify-between"><div><p className="text-xs font-semibold tracking-[0.14em] text-[#859087] uppercase">Audit trail</p><h2 className="mt-1 font-serif text-2xl text-[#1b3b2b]">Recent decisions</h2></div><span className="text-xs text-[#859087]">{promotions.length} records</span></div><div className="overflow-hidden rounded-lg border border-[#e3e8df] bg-white"><div className="divide-y divide-[#edf0eb]">{promotions.map((promotion) => <article key={promotion.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"><div className="flex min-w-0 items-center gap-3"><span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-[#e7f0e8] text-[#4c7955]"><GraduationCap aria-hidden="true" className="size-4" /></span><div className="min-w-0"><p className="truncate text-sm font-medium text-[#35483a]">{promotion.student.name}</p><p className="mt-0.5 truncate text-xs text-[#849087]">{promotion.from_class?.name ?? 'Previous class'} → {promotion.to_class?.name ?? 'New class'}</p></div></div><span className="text-xs text-[#829087]">{promotion.decision}</span></article>)}{!promotions.length && <p className="p-8 text-center text-sm text-[#829087]">No promotion decisions recorded yet.</p>}</div></div></section>
                </div>
            </main>
        </AppLayout>
    );
}
