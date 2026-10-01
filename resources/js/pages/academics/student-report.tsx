import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowDownToLine, ArrowLeft, Mail, Printer, TrendingUp } from 'lucide-react';

type ReportResult = { id: number; score: string; grade: string | null; remark: string | null; subject: { name: string; code: string }; exam: { name: string; type: string; max_score: number; held_on: string | null; term: { name: string; academicYear: { name: string } } | null } };
type Trend = { label: string; score: number; possible: number; percent: number };

export default function StudentReport({ school, student, enrollment, results, trends, summary, generatedAt, canEmail }: {
    school: { name: string; code: string; county: string | null; logo_path: string | null; primary_color: string; secondary_color: string; motto: string | null; phone: string | null; email: string | null; po_box: string | null };
    student: { id: number; name: string; admission_number: string | null; gender: string | null };
    enrollment: { school_class: { name: string } | null; stream: { name: string } | null; academic_year: { name: string } | null } | null;
    results: ReportResult[];
    trends: Trend[];
    summary: { score: number; possible: number; percent: number | null; assessments: number };
    generatedAt: string;
    canEmail: boolean;
}) {
    const form = useForm({ student_ids: [student.id] });
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Results & trends', href: '/academics/reports' }, { title: student.name, href: `/students/${student.id}/results` }];
    const best = Math.max(1, ...trends.map((trend) => trend.percent));

    function emailReport() {
        form.post('/academics/results/email', { preserveScroll: true });
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${student.name} results`}><style>{`@media print { @page { margin:12mm } .print-hidden{display:none!important} body{background:white!important} .report-sheet{box-shadow:none!important;border-color:#d9e1d8!important} }`}</style></Head>
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1100px]">
                    <header className="print-hidden mb-5 flex flex-wrap items-end justify-between gap-4"><div><Link href="/academics/reports" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#5f7564] hover:text-[#294a34]"><ArrowLeft aria-hidden="true" className="size-4" /> All learner reports</Link><h1 className="mt-3 font-serif text-3xl text-[#193b2a]">Academic results</h1></div><div className="flex gap-2"><a href={`/students/${student.id}/results.pdf`} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659]"><ArrowDownToLine aria-hidden="true" className="size-4" /> Download PDF</a><button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659]"><Printer aria-hidden="true" className="size-4" /> Print</button>{canEmail && <button onClick={emailReport} disabled={form.processing} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-3 py-2.5 text-sm font-semibold text-white disabled:opacity-50"><Mail aria-hidden="true" className="size-4" /> Email guardian</button>}</div></header>

                    <article className="report-sheet overflow-hidden rounded-lg border border-[#dfe5dc] bg-white shadow-[0_14px_40px_rgba(34,61,43,0.08)]">
                        <div className="h-2" style={{ backgroundColor: school.primary_color }} />
                        <div className="p-5 sm:p-8">
                            <header className="flex flex-wrap items-center justify-between gap-4 border-b border-[#e8ece5] pb-5"><div className="flex items-center gap-3"><div className="flex size-12 items-center justify-center overflow-hidden rounded-md bg-[#eef3eb]">{school.logo_path && <img src={`/storage/${school.logo_path}`} alt="" className="size-full object-cover" />}</div><div><h2 className="font-serif text-xl text-[#193b2a]">{school.name}</h2><p className="mt-1 text-xs text-[#7c887f]">{school.county ? `${school.county} County · ` : ''}{school.code}</p><p className="mt-1 text-[10px] text-[#8a958c]">{[school.phone, school.email, school.po_box].filter(Boolean).join(' · ')}</p></div></div><div className="text-right"><p className="text-[10px] font-semibold tracking-[0.14em] text-[#7b887e] uppercase">Learner results report</p><p className="mt-1 text-xs text-[#829087]">Generated {generatedAt}</p></div></header>

                            <section className="mt-5 flex flex-wrap items-end justify-between gap-4"><div><p className="text-[10px] font-semibold tracking-[0.13em] text-[#839087] uppercase">Learner</p><h3 className="mt-1 font-serif text-2xl text-[#203b2b]">{student.name}</h3><p className="mt-1 text-sm text-[#718076]">{student.admission_number ?? 'Admission number not recorded'}{student.gender ? ` · ${student.gender}` : ''}</p></div><div className="text-left sm:text-right"><p className="text-sm font-semibold text-[#405544]">{enrollment?.school_class?.name ?? 'Class not recorded'}{enrollment?.stream ? ` · ${enrollment.stream.name}` : ''}</p><p className="mt-1 text-xs text-[#829087]">Academic year {enrollment?.academic_year?.name ?? '—'}</p></div></section>

                            <section className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-4">{[
                                ['Overall', summary.percent === null ? '—' : `${summary.percent}%`],
                                ['Marks earned', `${summary.score} / ${summary.possible}`],
                                ['Assessments', summary.assessments],
                                ['Result entries', results.length],
                            ].map(([label, value]) => <div key={String(label)} className="rounded-md bg-[#f4f7f1] p-3"><p className="text-[10px] font-semibold tracking-[0.08em] text-[#829087] uppercase">{label}</p><p className="mt-1 text-lg font-semibold text-[#2f4d36]">{value}</p></div>)}</section>

                            <section className="mt-7"><div className="flex items-center gap-2"><TrendingUp aria-hidden="true" className="size-4 text-[#66836b]" /><h3 className="font-serif text-xl text-[#1c3c2c]">Performance trend</h3></div>{trends.length ? <div className="mt-4 space-y-3">{trends.map((trend) => <div key={trend.label} className="grid grid-cols-[minmax(110px,0.9fr)_minmax(100px,2fr)_auto] items-center gap-3"><p className="truncate text-xs text-[#68776c]">{trend.label}</p><div className="h-2 overflow-hidden rounded-full bg-[#edf1eb]"><div className="h-full rounded-full bg-[#54805e]" style={{ width: `${Math.min(100, (trend.percent / best) * 100)}%` }} /></div><p className="text-xs font-semibold text-[#425d48]">{trend.percent}%</p></div>)}</div> : <p className="mt-3 text-sm text-[#829087]">No published assessment history yet.</p>}</section>

                            <section className="mt-7"><h3 className="font-serif text-xl text-[#1c3c2c]">Assessment detail</h3><div className="mt-3 overflow-x-auto rounded-md border border-[#e6ebe4]"><table className="w-full min-w-[650px] border-collapse text-left text-sm"><thead><tr className="bg-[#f5f7f2] text-[10px] font-semibold tracking-[0.08em] text-[#829087] uppercase"><th className="px-3 py-2.5">Year / term</th><th className="px-3 py-2.5">Assessment</th><th className="px-3 py-2.5">Learning area</th><th className="px-3 py-2.5">Score</th><th className="px-3 py-2.5">Band</th><th className="px-3 py-2.5">Remark</th></tr></thead><tbody>{results.map((result) => <tr key={result.id} className="border-t border-[#edf0eb]"><td className="px-3 py-2.5 text-xs text-[#6d7b70]">{result.exam.term?.academicYear?.name} · {result.exam.term?.name}</td><td className="px-3 py-2.5 text-[#405544]">{result.exam.name}</td><td className="px-3 py-2.5 text-[#405544]">{result.subject.name}</td><td className="px-3 py-2.5 font-semibold text-[#354c3a]">{result.score} / {result.exam.max_score}</td><td className="px-3 py-2.5 font-bold text-[#52705a]">{result.grade ?? '—'}</td><td className="px-3 py-2.5 text-xs text-[#718076]">{result.remark ?? '—'}</td></tr>)}</tbody></table>{!results.length && <p className="p-7 text-center text-sm text-[#829087]">No published results available.</p>}</div></section>

                            <footer className="mt-7 flex flex-wrap items-end justify-between gap-4 border-t border-[#e8ece5] pt-4"><p className="text-sm font-medium text-[#4f6954]">{school.motto ?? 'Learn, grow, achieve.'}</p><p className="text-[10px] text-[#929b93]">Published assessments only · {school.code}</p></footer>
                        </div>
                        <div className="h-1" style={{ backgroundColor: school.secondary_color }} />
                    </article>
                </div>
            </main>
        </AppLayout>
    );
}
