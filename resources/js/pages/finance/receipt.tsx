import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Download, Printer, ReceiptText } from 'lucide-react';

function money(value: number | string) {
    return new Intl.NumberFormat('en-KE', { style: 'currency', currency: 'KES', minimumFractionDigits: 2 }).format(Number(value));
}

export default function FinanceReceipt({ school, payment, student, account, invoice }: {
    school: { name: string; code: string; logo_path: string | null; primary_color: string; secondary_color: string; motto: string | null; county: string | null; phone: string | null; email: string | null; po_box: string | null };
    payment: { id: number; receipt_number: string; amount: string; method: string; reference: string | null; payer_name: string | null; paid_at: string | null };
    student: { id: number; name: string; admission_number: string | null } | null;
    account: { name: string; code: string };
    invoice: { amount: string; balance: string; status: string } | null;
}) {
    const breadcrumbs: BreadcrumbItem[] = [{ title: 'Overview', href: '/dashboard' }, { title: 'Finance', href: '/finance' }, { title: 'Receipt', href: `/finance/payments/${payment.id}/receipt` }];
    const paidAt = payment.paid_at ? new Intl.DateTimeFormat('en-KE', { dateStyle: 'long', timeStyle: 'short' }).format(new Date(payment.paid_at)) : 'Not recorded';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Receipt ${payment.receipt_number}`}><style>{`@media print { @page { size:A4; margin:14mm } .print-hidden {display:none!important} body {background:white!important} .receipt-paper {box-shadow:none!important;border-color:#d8ded7!important} }`}</style></Head>
            <main className="min-h-full bg-[#f5f6f1] px-4 py-7 text-[#25382d] sm:px-6 lg:px-9 lg:py-10" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-3xl">
                    <div className="print-hidden mb-6 flex flex-wrap items-end justify-between gap-4"><div><Link href="/finance" className="inline-flex items-center gap-1.5 text-sm font-medium text-[#5f7564] hover:text-[#294a34]"><ArrowLeft aria-hidden="true" className="size-4" /> Back to finance</Link><h1 className="mt-4 font-serif text-3xl text-[#193b2a]">Payment receipt</h1></div><div className="flex gap-2"><a href={`/finance/payments/${payment.id}/receipt.pdf`} className="inline-flex items-center gap-2 rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm font-medium text-[#536659]"><Download aria-hidden="true" className="size-4" /> Download PDF</a><button onClick={() => window.print()} className="inline-flex items-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white"><Printer aria-hidden="true" className="size-4" /> Print</button></div></div>

                    <article className="receipt-paper overflow-hidden rounded-lg border border-[#dfe5dc] bg-white shadow-[0_18px_55px_rgba(34,61,43,0.1)]">
                        <div className="h-2" style={{ backgroundColor: school.primary_color }} />
                        <div className="p-6 sm:p-9">
                            <header className="flex flex-wrap items-start justify-between gap-5 border-b border-[#e8ece5] pb-6">
                                <div className="flex min-w-0 items-center gap-4"><div className="flex size-14 shrink-0 items-center justify-center overflow-hidden rounded-md bg-[#eef3eb] text-[#476b4f]">{school.logo_path ? <img src={`/storage/${school.logo_path}`} alt="" className="size-full object-cover" /> : <ReceiptText aria-hidden="true" className="size-7" />}</div><div className="min-w-0"><h2 className="font-serif text-xl text-[#193b2a]">{school.name}</h2><p className="mt-1 text-xs text-[#7c887f]">{school.county ? `${school.county} County · ` : ''}{school.code}</p><p className="mt-1 text-xs text-[#7c887f]">{[school.phone, school.email, school.po_box].filter(Boolean).join(' · ')}</p></div></div>
                                <div className="text-left sm:text-right"><p className="text-[10px] font-semibold tracking-[0.14em] text-[#7b887e] uppercase">Official receipt</p><p className="mt-1 font-mono text-base font-semibold text-[#344d39]">{payment.receipt_number}</p><p className="mt-1 text-xs text-[#7f8a82]">{paidAt}</p></div>
                            </header>

                            <section className="mt-7 grid gap-5 sm:grid-cols-2"><div><p className="text-[10px] font-semibold tracking-[0.12em] text-[#849087] uppercase">Received from</p><p className="mt-2 text-sm font-semibold text-[#35483a]">{student?.name ?? payment.payer_name ?? 'Walk-in payer'}</p>{student?.admission_number && <p className="mt-1 text-xs text-[#829087]">Admission No. {student.admission_number}</p>}</div><div><p className="text-[10px] font-semibold tracking-[0.12em] text-[#849087] uppercase">Paid into</p><p className="mt-2 text-sm font-semibold text-[#35483a]">{account.name}</p><p className="mt-1 text-xs text-[#829087]">Account {account.code}</p></div></section>

                            <section className="mt-7 overflow-hidden rounded-md border border-[#e6ebe4]"><div className="grid grid-cols-[1fr_auto] gap-4 bg-[#f5f7f2] px-4 py-3 text-[10px] font-semibold tracking-[0.1em] text-[#7c887f] uppercase"><span>Description</span><span>Amount</span></div><div className="grid grid-cols-[1fr_auto] gap-4 px-4 py-4 text-sm"><div><p className="font-medium text-[#3b4e40]">{invoice ? `Fee payment · ${invoice.status === 'paid' ? 'settled' : 'part payment'}` : 'School payment'}</p><p className="mt-1 text-xs text-[#829087]">{payment.method.toUpperCase()}{payment.reference ? ` · Reference ${payment.reference}` : ''}</p></div><p className="font-semibold text-[#294a34]">{money(payment.amount)}</p></div><div className="flex justify-end border-t border-[#edf0eb] px-4 py-3"><p className="text-sm"><span className="mr-5 text-[#77847a]">Total paid</span><strong className="text-[#294a34]">{money(payment.amount)}</strong></p></div></section>

                            {invoice && <div className="mt-5 flex justify-end"><p className="text-right text-xs text-[#77847a]">Invoice amount {money(invoice.amount)}<br /><span className="mt-1 inline-block font-semibold text-[#405544]">Balance remaining {money(invoice.balance)}</span></p></div>}
                            <footer className="mt-9 flex flex-wrap items-end justify-between gap-4 border-t border-[#e8ece5] pt-5"><div><p className="text-sm font-medium text-[#4f6954]">{school.motto ?? 'Thank you for your payment.'}</p><p className="mt-1 text-[10px] text-[#929b93]">Receipt reference: {payment.receipt_number}</p></div><div className="w-40 border-t border-[#aab6aa] pt-2 text-center text-[10px] text-[#849087]">Authorized by</div></footer>
                        </div>
                        <div className="h-1" style={{ backgroundColor: school.secondary_color }} />
                    </article>
                </div>
            </main>
        </AppLayout>
    );
}
