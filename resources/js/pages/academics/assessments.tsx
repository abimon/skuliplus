import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowDownToLine, ArrowLeft, BookOpenCheck, FileUp, Plus, Printer, X } from 'lucide-react';
import { useRef, useState } from 'react';

type SchoolClass = {
    id: number;
    name: string;
    curriculum_id: number;
    curriculum_level_id: number;
    curriculum: { id: number; name: string; code: string };
    academic_year: { id: number; name: string };
    enrollments: { id: number; student: { id: number; name: string; admission_number: string | null } }[];
};
type ExamResult = { id: number; student: { id: number; name: string; admission_number: string | null }; subject: { id: number; code: string; name: string }; score: string; grade: string | null; remark: string | null };
type Exam = {
    id: number;
    name: string;
    type: string;
    max_score: number;
    held_on: string | null;
    is_published: boolean;
    results_count: number;
    term: { id: number; name: string; academic_year_id: number };
    school_class: { id: number; name: string; academic_year_id: number; curriculum_id: number; curriculum_level_id: number };
    results: ExamResult[];
};
type Term = { id: number; name: string; academic_year_id: number; academic_year: { id: number; name: string } };
type AssessmentSubject = { id: number; curriculum_id: number; curriculum_level_id: number | null; name: string; code: string };

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Academics', href: '/academics' }, { title: 'Exams & results', href: '/academics/assessments' }];

function Field({ label, error, children }: { label: string; error?: string; children: React.ReactNode }) {
    return <label className="block text-xs font-medium text-[#657469]">{label}{children}{error && <span className="mt-1 block text-xs text-red-700">{error}</span>}</label>;
}

export default function Assessments({ school, exams, terms, classes, subjects, can }: {
    school: { name: string; code: string };
    exams: Exam[];
    terms: Term[];
    classes: SchoolClass[];
    subjects: AssessmentSubject[];
    can: { record: boolean; upload: boolean };
}) {
    const [showExamForm, setShowExamForm] = useState(false);
    const [showResultForm, setShowResultForm] = useState(false);
    const [selectedExamId, setSelectedExamId] = useState<number | null>(exams[0]?.id ?? null);
    const [studentFilter, setStudentFilter] = useState('');
    const uploadRef = useRef<HTMLInputElement>(null);
    const examForm = useForm({ name: '', term_id: '', school_class_id: '', type: 'midterm', max_score: '100', held_on: '' });
    const resultForm = useForm({ student_id: '', subject_id: '', score: '', remark: '' });
    const importForm = useForm<{ file: File | null }>({ file: null });
    const selectedExam = exams.find((exam) => exam.id === selectedExamId) ?? null;
    const selectedClass = selectedExam ? classes.find((schoolClass) => schoolClass.id === selectedExam.school_class.id) : null;
    const enrolledStudents = selectedClass?.enrollments.map((enrollment) => enrollment.student) ?? [];
    const examSubjects = subjects.filter((subject) => subject.curriculum_id === selectedExam?.school_class.curriculum_id && (!subject.curriculum_level_id || subject.curriculum_level_id === selectedExam?.school_class.curriculum_level_id));

    function closeExamForm() {
        setShowExamForm(false);
        examForm.reset();
        examForm.clearErrors();
    }

    function closeResultForm() {
        setShowResultForm(false);
        resultForm.reset();
        resultForm.clearErrors();
    }

    function submitExam(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        examForm.post('/academics/assessments', { preserveScroll: true, onSuccess: closeExamForm });
    }

    function submitResult(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!selectedExam) return;
        resultForm.post(`/academics/assessments/${selectedExam.id}/results`, { preserveScroll: true, onSuccess: closeResultForm });
    }

    function importResults(file: File | null) {
        if (!file || !selectedExam) return;
        importForm.setData('file', file);
        importForm.post(`/academics/assessments/${selectedExam.id}/import`, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => { importForm.reset(); if (uploadRef.current) uploadRef.current.value = ''; },
        });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Exams & results"><style>{`@media print { .print-hidden { display:none !important; } body { background:white !important; } }`}</style></Head>
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1440px]">
                    <header className="print-hidden flex flex-wrap items-end justify-between gap-4">
                        <div><Link href="/academics" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#5f7564] hover:text-[#294a34]"><ArrowLeft aria-hidden="true" className="size-4" /> Academics</Link><p className="mt-3 text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{school.code} · Assessment</p><h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Exams &amp; results</h1><p className="mt-2 text-sm text-[#718076]">Class assessments, learner scores and CBC performance bands.</p></div>
                        <div className="flex flex-wrap gap-2"><Link href="/academics/reports" className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659]">Results &amp; trends</Link><button onClick={() => setShowExamForm(true)} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a]"><Plus aria-hidden="true" className="size-4" /> Create exam</button></div>
                    </header>

                    {importForm.errors.file && <p role="alert" className="print-hidden mt-4 rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-800">{importForm.errors.file}</p>}

                    <div className="mt-7 grid items-start gap-6 xl:grid-cols-[minmax(260px,0.7fr)_minmax(0,1.6fr)]">
                        <section className="print-hidden rounded-lg border border-[#e3e8df] bg-white p-4">
                            <div className="flex items-center justify-between gap-3"><div><p className="text-xs font-semibold tracking-[0.12em] text-[#819087] uppercase">Assessments</p><h2 className="mt-1 font-serif text-xl text-[#1b3b2b]">Exam register</h2></div><span className="rounded-sm bg-[#eff4ec] px-2 py-1 text-xs font-semibold text-[#59715e]">{exams.length}</span></div>
                            <div className="mt-4 space-y-2">
                                {exams.map((exam) => <button key={exam.id} onClick={() => { setSelectedExamId(exam.id); setStudentFilter(''); }} className={`w-full rounded-md border p-3 text-left ${selectedExamId === exam.id ? 'border-[#a8bda8] bg-[#f2f6ef]' : 'border-[#e8ece5] hover:bg-[#fafbf8]'}`}><span className="flex items-start justify-between gap-2"><span className="min-w-0"><span className="block truncate text-sm font-semibold text-[#34483a]">{exam.name}</span><span className="mt-1 block text-xs text-[#7e8a81]">{exam.school_class.name} · {exam.term.name}</span></span><span className="shrink-0 text-[10px] font-semibold text-[#6f866f]">{exam.results_count} scores</span></span></button>)}
                                {!exams.length && <div className="rounded-md border border-dashed border-[#dce3d9] p-5 text-center"><BookOpenCheck aria-hidden="true" className="mx-auto size-6 text-[#729078]" /><p className="mt-2 text-sm font-medium text-[#394c3f]">No exams yet</p><p className="mt-1 text-xs text-[#849087]">Create a class assessment to enter results.</p></div>}
                            </div>
                        </section>

                        <section className="min-w-0 rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-5">
                            {selectedExam ? <>
                                <div className="flex flex-wrap items-start justify-between gap-3 border-b border-[#e8ece5] pb-4"><div><p className="text-xs font-semibold tracking-[0.1em] text-[#819087] uppercase">{selectedExam.type.replaceAll('_', ' ')} · {selectedExam.term.name}</p><h2 className="mt-1 font-serif text-2xl text-[#1b3b2b]">{selectedExam.name}</h2><p className="mt-1 text-sm text-[#7c887f]">{selectedExam.school_class.name} · {selectedExam.school_class.curriculum_id ? classes.find((schoolClass) => schoolClass.id === selectedExam.school_class.id)?.curriculum.name : ''} · Maximum {selectedExam.max_score}</p><span className={`mt-2 inline-flex rounded-sm px-2 py-1 text-[10px] font-semibold ${selectedExam.is_published ? 'bg-[#e7f1e6] text-[#3c7147]' : 'bg-[#f5f0e5] text-[#8a6b31]'}`}>{selectedExam.is_published ? 'Published to families' : 'Draft · not visible to families'}</span></div><div className="print-hidden flex flex-wrap gap-2"><button onClick={() => window.print()} className="inline-flex items-center gap-1.5 rounded-md border border-[#dfe5dc] px-3 py-2 text-xs font-semibold text-[#536659]"><Printer aria-hidden="true" className="size-3.5" /> Print</button><button onClick={() => router.patch(`/academics/assessments/${selectedExam.id}/publish`, { is_published: !selectedExam.is_published }, { preserveScroll: true })} className="rounded-md border border-[#dfe5dc] px-3 py-2 text-xs font-semibold text-[#536659]">{selectedExam.is_published ? 'Unpublish' : 'Publish results'}</button>{can.upload && <><a href={`/academics/assessments/${selectedExam.id}/template`} className="inline-flex items-center gap-1.5 rounded-md border border-[#dfe5dc] px-3 py-2 text-xs font-semibold text-[#536659]"><ArrowDownToLine aria-hidden="true" className="size-3.5" /> CSV template</a><button onClick={() => uploadRef.current?.click()} disabled={importForm.processing} className="inline-flex items-center gap-1.5 rounded-md border border-[#dfe5dc] px-3 py-2 text-xs font-semibold text-[#536659]"><FileUp aria-hidden="true" className="size-3.5" /> Import results</button><input ref={uploadRef} type="file" accept=".csv,text/csv" className="sr-only" onChange={(event) => importResults(event.target.files?.[0] ?? null)} /></>}{can.record && <button onClick={() => setShowResultForm(true)} className="inline-flex items-center gap-1.5 rounded-md bg-[#244b35] px-3 py-2 text-xs font-semibold text-white"><Plus aria-hidden="true" className="size-3.5" /> Record score</button>}</div></div>
                                <div className="print:hidden mt-4 grid gap-2 sm:grid-cols-[minmax(150px,1fr)_220px]"><label className="text-xs font-medium text-[#76847a]">Filter learner<input value={studentFilter} onChange={(event) => setStudentFilter(event.target.value)} placeholder="Name or admission number" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] px-3 py-2 text-sm outline-none focus:border-[#77947a]" /></label><div className="self-end text-right text-xs text-[#829087]">{selectedExam.results.length} result entries</div></div>
                                <div className="mt-4 overflow-x-auto"><table className="w-full min-w-[600px] border-collapse text-left text-sm"><thead><tr className="border-b border-[#e8ece5] text-[10px] font-semibold tracking-[0.08em] text-[#829087] uppercase"><th className="px-3 py-2">Learner</th><th className="px-3 py-2">Learning area</th><th className="px-3 py-2">Score</th><th className="px-3 py-2">Band</th><th className="px-3 py-2">Remark</th></tr></thead><tbody>{selectedExam.results.filter((result) => `${result.student.name} ${result.student.admission_number ?? ''}`.toLowerCase().includes(studentFilter.toLowerCase())).map((result) => <tr key={result.id} className="border-b border-[#edf0eb] last:border-0"><td className="px-3 py-3"><span className="block font-medium text-[#35483a]">{result.student.name}</span><span className="mt-0.5 block text-xs text-[#849087]">{result.student.admission_number}</span></td><td className="px-3 py-3 text-[#55685b]">{result.subject.name}</td><td className="px-3 py-3 font-semibold text-[#344f39]">{result.score} / {selectedExam.max_score}</td><td className="px-3 py-3"><span className="rounded-sm bg-[#edf3eb] px-2 py-1 text-xs font-bold text-[#517057]">{result.grade ?? '—'}</span></td><td className="px-3 py-3 text-xs text-[#718076]">{result.remark ?? '—'}</td></tr>)}</tbody></table>{!selectedExam.results.length && <p className="py-10 text-center text-sm text-[#829087]">No scores recorded. Use manual entry or import a validated CSV.</p>}</div>
                            </> : <div className="p-10 text-center"><BookOpenCheck aria-hidden="true" className="mx-auto size-7 text-[#729078]" /><p className="mt-3 text-sm font-medium text-[#394c3f]">Select or create an exam</p></div>}
                        </section>
                    </div>
                </div>
            </main>

            {showExamForm && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) closeExamForm(); }}><section role="dialog" aria-modal="true" aria-labelledby="exam-form-title" className="my-auto w-full max-w-xl rounded-lg bg-[#fbfcf8] p-5 shadow-2xl sm:p-7"><div className="flex items-start justify-between"><div><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">New assessment</p><h2 id="exam-form-title" className="mt-1 font-serif text-2xl text-[#183b2a]">Create exam</h2></div><button aria-label="Close" onClick={closeExamForm} className="rounded-md p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div><form onSubmit={submitExam} className="mt-6 space-y-4"><Field label="Exam name" error={examForm.errors.name}><input required value={examForm.data.name} onChange={(event) => examForm.setData('name', event.target.value)} placeholder="e.g. Term 1 assessment" className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Term" error={examForm.errors.term_id}><select required value={examForm.data.term_id} onChange={(event) => examForm.setData('term_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select term</option>{terms.map((term) => <option key={term.id} value={term.id}>{term.name} · {term.academic_year.name}</option>)}</select></Field><Field label="Class" error={examForm.errors.school_class_id}><select required value={examForm.data.school_class_id} onChange={(event) => examForm.setData('school_class_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select class</option>{classes.map((schoolClass) => <option key={schoolClass.id} value={schoolClass.id}>{schoolClass.name} · {schoolClass.academic_year.name}</option>)}</select></Field><div className="grid gap-3 sm:grid-cols-2"><Field label="Assessment type" error={examForm.errors.type}><select value={examForm.data.type} onChange={(event) => examForm.setData('type', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="opener">Opener</option><option value="midterm">Mid-term</option><option value="end_term">End of term</option><option value="cat">Continuous assessment</option><option value="mock">Mock</option></select></Field><Field label="Maximum score" error={examForm.errors.max_score}><input type="number" min="1" max="1000" required value={examForm.data.max_score} onChange={(event) => examForm.setData('max_score', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field></div><Field label="Exam date" error={examForm.errors.held_on}><input type="date" value={examForm.data.held_on} onChange={(event) => examForm.setData('held_on', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeExamForm} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={examForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Create exam</button></div></form></section></div>}

            {showResultForm && selectedExam && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) closeResultForm(); }}><section role="dialog" aria-modal="true" aria-labelledby="result-form-title" className="my-auto w-full max-w-xl rounded-lg bg-[#fbfcf8] p-5 shadow-2xl sm:p-7"><div className="flex items-start justify-between"><div><p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">{selectedExam.name} · {selectedExam.school_class.name}</p><h2 id="result-form-title" className="mt-1 font-serif text-2xl text-[#183b2a]">Record learner result</h2></div><button aria-label="Close" onClick={closeResultForm} className="rounded-md p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div><form onSubmit={submitResult} className="mt-6 space-y-4"><Field label="Learner" error={resultForm.errors.student_id}><select required value={resultForm.data.student_id} onChange={(event) => resultForm.setData('student_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select a class learner</option>{enrolledStudents.map((student) => <option key={student.id} value={student.id}>{student.name}{student.admission_number ? ` · ${student.admission_number}` : ''}</option>)}</select></Field><Field label="Learning area" error={resultForm.errors.subject_id}><select required value={resultForm.data.subject_id} onChange={(event) => resultForm.setData('subject_id', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]"><option value="">Select learning area</option>{examSubjects.map((subject) => <option key={subject.id} value={subject.id}>{subject.name} · {subject.code}</option>)}</select></Field><Field label={`Score out of ${selectedExam.max_score}`} error={resultForm.errors.score}><input required type="number" min="0" max={selectedExam.max_score} step="0.01" value={resultForm.data.score} onChange={(event) => resultForm.setData('score', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><Field label="Teacher remark · optional" error={resultForm.errors.remark}><input value={resultForm.data.remark} onChange={(event) => resultForm.setData('remark', event.target.value)} className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" /></Field><p className="text-xs text-[#7d887f]">CBC performance bands are calculated automatically from the score percentage.</p><div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={closeResultForm} className="rounded-md border border-[#dfe5dc] px-4 py-2 text-sm text-[#596b5d]">Cancel</button><button disabled={resultForm.processing} className="rounded-md bg-[#244b35] px-4 py-2 text-sm font-semibold text-white disabled:opacity-50">Save result</button></div></form></section></div>}
        </AppLayout>
    );
}
