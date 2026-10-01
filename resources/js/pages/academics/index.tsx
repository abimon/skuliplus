import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { CalendarDays, ChevronDown, GraduationCap, Plus, School, Users, X, type LucideIcon } from 'lucide-react';
import { useState } from 'react';

type Curriculum = { id: number; code: string; name: string; levels: Level[]; subjects: Subject[] };
type Level = { id: number; code: string; name: string; stage: string; order: number };
type Subject = { id: number; code: string; name: string; weekly_lessons: number };
type Teacher = { id: number; name: string };
type TimetableLesson = { id: number; term_id: number; subject: Subject };
type TimetableSlot = { id: number; day_of_week: number; period: number; starts_at: string | null; ends_at: string | null; lesson: TimetableLesson | null };
type StreamData = { id: number; name: string; capacity: number; active_learners_count: number; class_teacher: Teacher | null; lessons: TimetableLesson[]; timetable_slots: TimetableSlot[] };
type SchoolClassData = {
    id: number;
    name: string;
    curriculum: { id: number; name: string; code: string };
    level: Level;
    academic_year: { id: number; name: string };
    active_learners_count: number;
    streams: StreamData[];
};
type AcademicYear = { id: number; name: string; is_current: boolean };
type Term = { id: number; name: string; academic_year_id: number; is_current: boolean; academic_year: AcademicYear };
type Student = { id: number; name: string; admission_number: string | null };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Academics', href: '/academics' }];
const dayLabels: Record<number, string> = { 1: 'Monday', 2: 'Tuesday', 3: 'Wednesday', 4: 'Thursday', 5: 'Friday' };

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <label className="block text-xs font-medium text-[#657469]">{label}{children}{error && <span className="mt-1 block text-xs text-red-700">{error}</span>}</label>;
}

function Timetable({ stream, termId, canEdit }: { stream: StreamData; termId: number | null; canEdit: boolean }) {
    if (!termId) return <p className="mt-3 text-xs text-[#829087]">Set an active term to create a timetable.</p>;
    if (!stream.timetable_slots.length) return <p className="mt-3 text-xs text-[#829087]">No timetable for the selected term yet.</p>;

    return (
        <div className="mt-4 space-y-3">
            {[1, 2, 3, 4, 5].map((day) => {
                const slots = stream.timetable_slots.filter((slot) => slot.day_of_week === day);
                if (!slots.length) return null;

                return (
                    <div key={day} className="grid grid-cols-[68px_repeat(4,minmax(0,1fr))] gap-1.5 sm:grid-cols-[86px_repeat(8,minmax(0,1fr))]">
                        <div className="flex items-center text-[10px] font-semibold text-[#6c7c70]">{dayLabels[day]}</div>
                        {slots.map((slot) => (
                            <div key={slot.id} className="min-w-0 rounded-sm bg-[#f1f5ee] p-1.5 sm:p-2">
                                <p className="text-[9px] font-semibold text-[#78907a]">P{slot.period}</p>
                                {canEdit ? (
                                    <select aria-label={`${dayLabels[day]} period ${slot.period}`} value={slot.lesson?.id ?? ''} onChange={(event) => router.put(`/academics/timetable-slots/${slot.id}`, { lesson_id: Number(event.target.value) }, { preserveScroll: true })} className="mt-1 w-full truncate bg-transparent text-[9px] text-[#36533c] outline-none sm:text-[10px]">
                                        {stream.lessons.map((lesson) => <option key={lesson.id} value={lesson.id}>{lesson.subject.name}</option>)}
                                    </select>
                                ) : <p className="mt-1 truncate text-[9px] text-[#36533c] sm:text-[10px]">{slot.lesson?.subject.name ?? 'Study'}</p>}
                            </div>
                        ))}
                    </div>
                );
            })}
        </div>
    );
}

export default function AcademicsIndex({ school, classes, academicYears, currentTerm, terms, curricula, students, teachers, can }: {
    school: { name: string; code: string; county: string | null };
    classes: SchoolClassData[];
    academicYears: AcademicYear[];
    currentTerm: { id: number; name: string; academic_year_id: number } | null;
    terms: Term[];
    curricula: Curriculum[];
    students: Student[];
    teachers: Teacher[];
    can: { generate_timetable: boolean; edit_timetable: boolean };
}) {
    const [dialog, setDialog] = useState<'class' | 'stream' | 'enrollment' | 'year' | 'term' | null>(null);
    const [streamClassId, setStreamClassId] = useState<number | null>(null);
    const classForm = useForm({ name: '', curriculum_id: '', curriculum_level_id: '', academic_year_id: String(academicYears.find((year) => year.is_current)?.id ?? '') });
    const streamForm = useForm({ school_class_id: '', name: '', class_teacher_id: '', capacity: '45' });
    const enrollmentForm = useForm({ student_id: '', school_class_id: '', stream_id: '' });
    const yearForm = useForm<{ name: string; starts_on: string; ends_on: string; is_current: boolean }>({ name: String(new Date().getFullYear()), starts_on: '', ends_on: '', is_current: true });
    const termForm = useForm({ academic_year_id: String(academicYears.find((year) => year.is_current)?.id ?? ''), name: 'Term 1', number: '1', starts_on: '', ends_on: '', is_current: !currentTerm });
    const selectedCurriculum = curricula.find((curriculum) => String(curriculum.id) === classForm.data.curriculum_id);
    const selectedClass = classes.find((item) => String(item.id) === enrollmentForm.data.school_class_id);
    const selectedStreamOptions = selectedClass?.streams ?? [];
    const selectedTermId = currentTerm?.id ?? null;
    const currentTermId = selectedTermId;
    const totalStreams = classes.reduce((total, item) => total + item.streams.length, 0);
    const totalLearners = classes.reduce((total, item) => total + item.active_learners_count, 0);
    const summary: { label: string; value: number; icon: LucideIcon }[] = [
        { label: 'Classes', value: classes.length, icon: GraduationCap },
        { label: 'Streams', value: totalStreams, icon: School },
        { label: 'Learner placements', value: totalLearners, icon: Users },
    ];

    function closeDialog() {
        setDialog(null);
        classForm.clearErrors();
        streamForm.clearErrors();
        enrollmentForm.clearErrors();
        yearForm.clearErrors();
        termForm.clearErrors();
    }

    function openStream(classId: number) {
        setStreamClassId(classId);
        streamForm.setData({ school_class_id: String(classId), name: '', class_teacher_id: '', capacity: '45' });
        streamForm.clearErrors();
        setDialog('stream');
    }

    function submitClass(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        classForm.post('/academics/classes', { preserveScroll: true, onSuccess: closeDialog });
    }

    function submitStream(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        streamForm.post('/academics/streams', { preserveScroll: true, onSuccess: closeDialog });
    }

    function submitEnrollment(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        enrollmentForm.post('/academics/enrollments', { preserveScroll: true, onSuccess: closeDialog });
    }

    function submitAcademicYear(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        yearForm.post('/academics/years', { preserveScroll: true, onSuccess: closeDialog });
    }

    function submitTerm(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        termForm.post('/academics/terms', { preserveScroll: true, onSuccess: closeDialog });
    }

    function generateTimetable(stream: StreamData) {
        if (!selectedTermId) return;
        const replace = stream.timetable_slots.length > 0;
        if (replace && !window.confirm(`Replace the existing ${stream.name} timetable?`)) return;
        router.post(`/academics/streams/${stream.id}/timetable`, { term_id: selectedTermId, replace }, { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Academics" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1440px]">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{school.county ? `${school.county} County · ` : ''}{school.code}</p>
                            <h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Academics</h1>
                            <p className="mt-2 text-sm text-[#718076]">Classes, streams and learner placement at {school.name}.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Link href="/modules/promotions" className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]">Promotions</Link>
                            <Link href="/academics/reports" className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]">Results &amp; trends</Link>
                            <Link href="/academics/assessments" className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]">Exams &amp; results</Link>
                            <button onClick={() => setDialog('year')} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4]"><CalendarDays aria-hidden="true" className="size-4" /> Academic year</button>
                            <button onClick={() => { termForm.setData((data) => ({ ...data, academic_year_id: String(academicYears.find((year) => year.is_current)?.id ?? academicYears[0]?.id ?? '') })); setDialog('term'); }} disabled={!academicYears.length} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4] disabled:opacity-50"><Plus aria-hidden="true" className="size-4" /> Add term</button>
                            <button onClick={() => setDialog('enrollment')} disabled={!students.length || !classes.length} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659] hover:bg-[#f7f8f4] disabled:opacity-50"><Users aria-hidden="true" className="size-4" /> Enroll learner</button>
                            <button onClick={() => setDialog('class')} disabled={!curricula.length || !academicYears.length} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-50"><Plus aria-hidden="true" className="size-4" /> Add class</button>
                        </div>
                    </header>

                    <section aria-label="Academic summary" className="mt-7 grid grid-cols-3 gap-3">
                        {summary.map(({ label, value, icon: Icon }) => <article key={label} className="rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-5"><div className="flex items-center justify-between gap-2"><p className="text-[10px] font-semibold tracking-[0.08em] text-[#7c887f] uppercase sm:text-xs">{label}</p><Icon aria-hidden="true" className="size-4 text-[#5b8063]" /></div><p className="mt-3 text-2xl font-semibold text-[#203a2a]">{value}</p></article>)}
                    </section>

                    <div className="mt-7 flex flex-wrap items-end justify-between gap-4">
                        <div><p className="text-xs font-semibold tracking-[0.14em] text-[#859087] uppercase">School structure</p><h2 className="mt-1 font-serif text-2xl text-[#1b3b2b]">Classes &amp; streams</h2></div>
                        {terms.length > 0 && <label className="text-xs font-medium text-[#6d7b70]">Timetable term<select value={selectedTermId ?? ''} onChange={(event) => router.get('/academics', { term_id: event.target.value || undefined }, { preserveState: true, preserveScroll: true, replace: true })} className="ml-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2 text-sm text-[#46594a] outline-none focus:border-[#77947a]"><option value="">Current term</option>{terms.map((term) => <option key={term.id} value={term.id}>{term.name} · {term.academic_year.name}</option>)}</select></label>}
                    </div>

                    <section className="mt-4 space-y-3">
                        {classes.map((schoolClass) => <article key={schoolClass.id} className="rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-5">
                            <div className="flex flex-wrap items-start justify-between gap-3">
                                <div className="min-w-0"><div className="flex flex-wrap items-center gap-2"><h3 className="font-serif text-xl text-[#203b2b]">{schoolClass.name}</h3><span className="rounded-sm bg-[#edf3eb] px-2 py-1 text-[10px] font-semibold text-[#52705a]">{schoolClass.curriculum.code}</span></div><p className="mt-1 text-xs text-[#7d887f]">{schoolClass.level.stage} · {schoolClass.academic_year.name} · {schoolClass.level.name}</p></div>
                                <div className="flex items-center gap-3 text-xs text-[#748178]"><span>{schoolClass.active_learners_count} learners</span><button onClick={() => openStream(schoolClass.id)} className="inline-flex items-center gap-1 rounded-md border border-[#dfe5dc] px-2.5 py-1.5 font-medium text-[#53705a] hover:bg-[#f4f7f1]"><Plus aria-hidden="true" className="size-3.5" /> Stream</button></div>
                            </div>
                            <div className="mt-4 space-y-3">
                                {schoolClass.streams.map((stream) => <section key={stream.id} className="rounded-md border border-[#e9ede6] bg-[#fcfdfa] p-3 sm:p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div className="min-w-0"><p className="font-semibold text-[#35483a]">{stream.name}</p><p className="mt-1 text-xs text-[#849087]">{stream.active_learners_count} / {stream.capacity} learners · {stream.class_teacher?.name ?? 'No class teacher'}</p></div>
                                        {can.generate_timetable && selectedTermId && <button onClick={() => generateTimetable(stream)} className="rounded-md border border-[#dfe5dc] px-2.5 py-1.5 text-xs font-semibold text-[#52705a] hover:bg-[#f1f5ee]">{stream.timetable_slots.length ? 'Regenerate timetable' : 'Generate timetable'}</button>}
                                    </div>
                                    <details className="mt-3 border-t border-[#edf0eb] pt-3">
                                        <summary className="flex cursor-pointer list-none items-center justify-between text-xs font-semibold text-[#66786a]">{selectedTermId ? 'Weekly timetable' : 'Timetable'}<ChevronDown aria-hidden="true" className="size-4" /></summary>
                                        <Timetable stream={stream} termId={currentTermId} canEdit={can.edit_timetable} />
                                    </details>
                                </section>)}
                                {!schoolClass.streams.length && <p className="rounded-md border border-dashed border-[#e0e6dd] px-3 py-4 text-center text-xs text-[#829087]">No streams yet. Add a stream to place learners and assign a class teacher.</p>}
                            </div>
                        </article>)}
                        {!classes.length && <div className="rounded-lg border border-dashed border-[#dce3d9] bg-white p-10 text-center"><GraduationCap aria-hidden="true" className="mx-auto size-7 text-[#729078]" /><p className="mt-3 text-sm font-medium text-[#394c3f]">No classes for this school yet</p><p className="mt-1 text-xs text-[#849087]">Add a class using one of the school’s assigned curriculum pathways.</p></div>}
                    </section>
                </div>
            </main>

            {dialog && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) closeDialog(); }}>
                <section role="dialog" aria-modal="true" aria-labelledby="academic-dialog-title" className="my-auto w-full max-w-xl rounded-lg bg-[#fbfcf8] p-5 shadow-2xl sm:p-7">
                    <div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">Academic setup</p><h2 id="academic-dialog-title" className="mt-1 font-serif text-2xl text-[#183b2a]">{dialog === 'class' ? 'Add class' : dialog === 'stream' ? `Add stream${streamClassId ? ` · ${classes.find((item) => item.id === streamClassId)?.name ?? ''}` : ''}` : dialog === 'year' ? 'Add academic year' : dialog === 'term' ? 'Add term' : 'Enroll learner'}</h2></div><button aria-label="Close" onClick={closeDialog} className="rounded-md p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div>

                    {dialog === 'year' && <form onSubmit={submitAcademicYear} className="mt-6 space-y-4">
                        <Field label="Academic year label" error={yearForm.errors.name}><input required maxLength={20} value={yearForm.data.name} onChange={(event) => yearForm.setData('name', event.target.value)} placeholder="2026" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Starts on" error={yearForm.errors.starts_on}><input required type="date" value={yearForm.data.starts_on} onChange={(event) => yearForm.setData('starts_on', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Ends on" error={yearForm.errors.ends_on}><input required type="date" value={yearForm.data.ends_on} onChange={(event) => yearForm.setData('ends_on', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></div>
                        <label className="flex items-center gap-2 text-sm text-[#596b5d]"><input type="checkbox" checked={yearForm.data.is_current} onChange={(event) => yearForm.setData('is_current', event.target.checked)} className="accent-[#356344]" /> Set as current academic year</label>
                        <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeDialog} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={yearForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save academic year</button></div>
                    </form>}

                    {dialog === 'term' && <form onSubmit={submitTerm} className="mt-6 space-y-4">
                        <Field label="Academic year" error={termForm.errors.academic_year_id}><select required value={termForm.data.academic_year_id} onChange={(event) => termForm.setData('academic_year_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select academic year</option>{academicYears.map((year) => <option key={year.id} value={year.id}>{year.name}{year.is_current ? ' · current' : ''}</option>)}</select></Field>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Term name" error={termForm.errors.name}><input required value={termForm.data.name} onChange={(event) => termForm.setData('name', event.target.value)} placeholder="Term 1" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Term number" error={termForm.errors.number}><select value={termForm.data.number} onChange={(event) => termForm.setData('number', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="1">Term 1</option><option value="2">Term 2</option><option value="3">Term 3</option></select></Field></div>
                        <div className="grid gap-3 sm:grid-cols-2"><Field label="Starts on" error={termForm.errors.starts_on}><input required type="date" value={termForm.data.starts_on} onChange={(event) => termForm.setData('starts_on', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Ends on" error={termForm.errors.ends_on}><input required type="date" value={termForm.data.ends_on} onChange={(event) => termForm.setData('ends_on', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></div>
                        <label className="flex items-center gap-2 text-sm text-[#596b5d]"><input type="checkbox" checked={termForm.data.is_current} onChange={(event) => termForm.setData('is_current', event.target.checked)} className="accent-[#356344]" /> Set as current term</label>
                        <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeDialog} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={termForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save term</button></div>
                    </form>}

                    {dialog === 'class' && <form onSubmit={submitClass} className="mt-6 space-y-4">
                        <Field label="Class name" error={classForm.errors.name}><input required value={classForm.data.name} onChange={(event) => classForm.setData('name', event.target.value)} placeholder="e.g. Grade 7" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                        <Field label="Curriculum" error={classForm.errors.curriculum_id}><select required value={classForm.data.curriculum_id} onChange={(event) => classForm.setData((data) => ({ ...data, curriculum_id: event.target.value, curriculum_level_id: '' }))} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select a school-assigned pathway</option>{curricula.map((curriculum) => <option key={curriculum.id} value={curriculum.id}>{curriculum.name} · {curriculum.code}</option>)}</select></Field>
                        <Field label="Grade / level" error={classForm.errors.curriculum_level_id}><select required disabled={!selectedCurriculum} value={classForm.data.curriculum_level_id} onChange={(event) => classForm.setData('curriculum_level_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a] disabled:opacity-50"><option value="">Select a grade or level</option>{selectedCurriculum?.levels.map((level) => <option key={level.id} value={level.id}>{level.name} · {level.stage}</option>)}</select></Field>
                        <Field label="Academic year" error={classForm.errors.academic_year_id}><select required value={classForm.data.academic_year_id} onChange={(event) => classForm.setData('academic_year_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select academic year</option>{academicYears.map((year) => <option key={year.id} value={year.id}>{year.name}{year.is_current ? ' · current' : ''}</option>)}</select></Field>
                        <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeDialog} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={classForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create class</button></div>
                    </form>}

                    {dialog === 'stream' && <form onSubmit={submitStream} className="mt-6 space-y-4">
                        <Field label="Stream name" error={streamForm.errors.name}><input required value={streamForm.data.name} onChange={(event) => streamForm.setData('name', event.target.value)} placeholder="e.g. East" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                        <Field label="Class teacher" error={streamForm.errors.class_teacher_id}><select value={streamForm.data.class_teacher_id} onChange={(event) => streamForm.setData('class_teacher_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Unassigned</option>{teachers.map((teacher) => <option key={teacher.id} value={teacher.id}>{teacher.name}</option>)}</select></Field>
                        <Field label="Learner capacity" error={streamForm.errors.capacity}><input type="number" min="1" max="200" required value={streamForm.data.capacity} onChange={(event) => streamForm.setData('capacity', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field>
                        <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeDialog} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={streamForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create stream</button></div>
                    </form>}

                    {dialog === 'enrollment' && <form onSubmit={submitEnrollment} className="mt-6 space-y-4">
                        <Field label="Learner" error={enrollmentForm.errors.student_id}><select required value={enrollmentForm.data.student_id} onChange={(event) => enrollmentForm.setData('student_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select a learner</option>{students.map((student) => <option key={student.id} value={student.id}>{student.name}{student.admission_number ? ` · ${student.admission_number}` : ''}</option>)}</select></Field>
                        <Field label="Class" error={enrollmentForm.errors.school_class_id}><select required value={enrollmentForm.data.school_class_id} onChange={(event) => enrollmentForm.setData((data) => ({ ...data, school_class_id: event.target.value, stream_id: '' }))} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select a class</option>{classes.map((item) => <option key={item.id} value={item.id}>{item.name} · {item.academic_year.name}</option>)}</select></Field>
                        <Field label="Stream" error={enrollmentForm.errors.stream_id}><select disabled={!selectedClass?.streams.length} value={enrollmentForm.data.stream_id} onChange={(event) => enrollmentForm.setData('stream_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a] disabled:opacity-50"><option value="">No stream selected</option>{selectedStreamOptions.map((stream) => <option key={stream.id} value={stream.id}>{stream.name} · {stream.active_learners_count}/{stream.capacity}</option>)}</select></Field>
                        <p className="text-xs text-[#7d887f]">A learner can have one active class placement per academic year.</p>
                        <div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeDialog} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={enrollmentForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save enrollment</button></div>
                    </form>}
                </section>
            </div>}
        </AppLayout>
    );
}
