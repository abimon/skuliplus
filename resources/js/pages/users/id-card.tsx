import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Printer, ShieldCheck } from 'lucide-react';

type IdentityCardProps = {
    user: { id: number; name: string; photo_path: string | null; admission_number: string | null; employee_number: string | null; phone: string | null };
    role: string;
    school: { name: string; code: string; logo_path: string | null; primary_color: string; secondary_color: string; motto: string | null; county: string | null };
    qrCode: string;
    identifier: string;
};

export default function IdentityCard({ user, role, school, qrCode, identifier }: IdentityCardProps) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'People', href: '/users' }, { title: 'ID card', href: `/users/${user.id}/id-card` }];
    const photoUrl = user.photo_path ? `/storage/${user.photo_path}` : null;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`${user.name} · ID card`}>
                <style>{`@page { size: 90mm 60mm; margin: 0; } @media print { body * { visibility: hidden !important; } .identity-card, .identity-card * { visibility: visible !important; } .identity-card { position: fixed !important; left: 2mm !important; top: 2mm !important; margin: 0 !important; box-shadow: none !important; } .print-hidden { display: none !important; } }`}</style>
            </Head>
            <main className="min-h-full bg-[#f5f6f1] px-4 py-7 text-[#25382d] sm:px-6 lg:px-9 lg:py-10" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-3xl">
                    <div className="print-hidden mb-8 flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <Link href="/users" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#5f7564] hover:text-[#294a34]"><ArrowLeft aria-hidden="true" className="size-4" /> Back to people</Link>
                            <h1 className="mt-4 font-serif text-3xl text-[#193b2a]">Identity card</h1>
                            <p className="mt-1 text-sm text-[#77847a]">{school.name} · {school.code}</p>
                        </div>
                        <button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a]"><Printer aria-hidden="true" className="size-4" /> Print / save as PDF</button>
                    </div>

                    <article className="identity-card relative mx-auto aspect-[1.5/1] w-full max-w-[540px] overflow-hidden rounded-xl border border-[#d9e1d8] bg-white shadow-[0_18px_55px_rgba(34,61,43,0.14)] print:aspect-auto print:h-[56mm] print:w-[86mm] print:rounded-[3mm]">
                        <div className="absolute inset-x-0 top-0 h-[15mm]" style={{ backgroundColor: school.primary_color }} />
                        <div className="absolute inset-x-0 top-[15mm] h-[1.4mm]" style={{ backgroundColor: school.secondary_color }} />
                        <div className="absolute -top-8 -right-10 size-48 rounded-full border-[25px] border-white/10 print:hidden" />
                        <div className="absolute -top-4 -right-2 size-32 rounded-full border-[17px] border-white/[0.07] print:hidden" />

                        <div className="relative flex h-[15mm] items-center gap-2.5 px-4 text-white print:px-[4mm]">
                            <div className="flex size-9 shrink-0 items-center justify-center overflow-hidden rounded-sm bg-white/15 print:size-[8mm]">
                                {school.logo_path ? <img src={`/storage/${school.logo_path}`} alt="" className="size-full object-cover" /> : <ShieldCheck aria-hidden="true" className="size-5 print:size-[5mm]" />}
                            </div>
                            <div className="min-w-0">
                                <p className="truncate text-[10px] font-semibold tracking-[0.08em] uppercase print:text-[7pt]">{school.name}</p>
                                <p className="mt-0.5 truncate text-[8px] text-white/75 print:text-[5pt]">{school.county ? `${school.county} County · ` : ''}{school.code}</p>
                            </div>
                        </div>

                        <div className="flex h-[calc(100%-15mm)] items-center gap-3 px-4 pt-2 print:gap-[3mm] print:px-[4mm] print:pt-[2mm]">
                            <div className="flex size-[25%] max-h-28 max-w-28 shrink-0 items-center justify-center overflow-hidden rounded-md border border-[#e2e9e0] bg-[#f1f4ee] print:size-[19mm]">
                                {photoUrl ? <img src={photoUrl} alt={`${user.name} profile`} className="size-full object-cover" /> : <span className="font-serif text-2xl text-[#6d8a72] print:text-[16pt]">{user.name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase()}</span>}
                            </div>
                            <div className="min-w-0 flex-1">
                                <p className="text-[8px] font-semibold tracking-[0.12em] text-[#7c8b7e] uppercase print:text-[5pt]">{role.replaceAll('_', ' ')}</p>
                                <h2 className="mt-1 line-clamp-2 font-serif text-xl leading-tight text-[#193b2a] sm:text-2xl print:text-[12pt]">{user.name}</h2>
                                <p className="mt-2 text-[9px] font-semibold tracking-[0.04em] text-[#526b57] print:mt-[1mm] print:text-[6pt]">{user.admission_number ? 'Admission No.' : 'Staff No.'}: {identifier}</p>
                                {user.phone && <p className="mt-1 truncate text-[9px] text-[#78857b] print:text-[5pt]">{user.phone}</p>}
                            </div>
                            <div className="flex w-[25%] max-w-[100px] shrink-0 flex-col items-center print:w-[19mm]">
                                <img src={qrCode} alt={`Scannable identity code for ${user.name}`} className="aspect-square w-full bg-white p-1 print:p-[1mm]" />
                                <span className="mt-1 text-center text-[7px] font-medium tracking-[0.08em] text-[#76847a] uppercase print:mt-[0.5mm] print:text-[4pt]">Scan to identify</span>
                            </div>
                        </div>
                        {school.motto && <p className="absolute right-4 bottom-2 left-4 truncate text-[8px] italic text-[#88948a] print:right-[4mm] print:bottom-[1.5mm] print:left-[4mm] print:text-[5pt]">{school.motto}</p>}
                    </article>
                    <p className="print-hidden mt-5 text-center text-xs text-[#849087]">Use the browser print dialog to print the card or save it as a PDF.</p>
                </div>
            </main>
        </AppLayout>
    );
}
