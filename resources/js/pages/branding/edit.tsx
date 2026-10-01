import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Check, ImagePlus, School } from 'lucide-react';
import { useEffect, useState } from 'react';

type SchoolBrand = {
    id: number;
    name: string;
    code: string;
    logo_path: string | null;
    county: string | null;
    sub_county: string | null;
    ward: string | null;
    address: string | null;
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
};
type BrandingFormData = {
    [key: string]: string | File | null | boolean;
    name: string;
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
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'School setup', href: '/settings/school' },
];
const textInput = 'mt-1.5 h-11 w-full rounded-md border border-[#dfe5dc] bg-white px-3 text-sm text-[#304337] outline-none focus:border-[#77947a] focus:ring-2 focus:ring-[#77947a]/15';

export default function SchoolBrandingEdit({ school }: { school: SchoolBrand }) {
    const form = useForm<BrandingFormData>({
        name: school.name,
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
        logo: null as File | null,
        remove_logo: false,
    });
    const [preview, setPreview] = useState<string | null>(null);

    useEffect(() => {
        if (!form.data.logo) {
            setPreview(null);
            return;
        }
        const url = URL.createObjectURL(form.data.logo);
        setPreview(url);
        return () => URL.revokeObjectURL(url);
    }, [form.data.logo]);

    function submit(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        form.transform((data) => ({ ...data, _method: 'PUT' }));
        form.post('/settings/school', {
            forceFormData: true,
            preserveScroll: true,
        });
    }

    const textFields = [
        ['name', 'Registered school name', 'text'],
        ['county', 'County', 'text'],
        ['sub_county', 'Sub-county', 'text'],
        ['ward', 'Ward', 'text'],
        ['phone', 'School phone', 'tel'],
        ['email', 'School email', 'email'],
        ['website', 'Website', 'url'],
        ['po_box', 'P.O. box', 'text'],
    ] as const;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="School setup" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1080px]">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div className="flex items-center gap-3">
                            <span className="flex size-12 items-center justify-center rounded-md bg-[#e6efe6] text-[#356344]"><School aria-hidden="true" className="size-6" /></span>
                            <div><p className="text-xs font-semibold uppercase text-[#7d897f]">Administration</p><h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">School setup</h1><p className="mt-1 text-sm text-[#718076]">Identity, contact details and the story your community sees.</p></div>
                        </div>
                        <span className="rounded-sm border border-[#dfe5dc] bg-white px-3 py-2 text-xs font-semibold text-[#647469]">{school.code}</span>
                    </header>

                    <form onSubmit={submit} className="mt-7 space-y-5">
                        <section className="border border-[#e3e8df] bg-white p-5 sm:p-7">
                            <div className="border-b border-[#edf0eb] pb-4"><h2 className="font-serif text-xl text-[#1b3b2b]">School identity</h2><p className="mt-1 text-xs text-[#829087]">Displayed on your school workspace and documents.</p></div>
                            <div className="mt-5 grid gap-4 sm:grid-cols-2">
                                {textFields.map(([key, label, type]) => <label key={key} className="block text-xs font-semibold text-[#59695d]">{label}<input type={type} maxLength={key === 'name' ? 255 : 255} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} className={textInput} />{form.errors[key] && <span className="mt-1 block font-normal text-red-700">{form.errors[key]}</span>}</label>)}
                                <label className="block text-xs font-semibold text-[#59695d] sm:col-span-2">Postal / physical address<textarea rows={2} maxLength={255} value={form.data.address} onChange={(event) => form.setData('address', event.target.value)} className="mt-1.5 w-full resize-y rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" />{form.errors.address && <span className="mt-1 block font-normal text-red-700">{form.errors.address}</span>}</label>
                            </div>
                        </section>

                        <section className="border border-[#e3e8df] bg-white p-5 sm:p-7">
                            <div className="border-b border-[#edf0eb] pb-4"><h2 className="font-serif text-xl text-[#1b3b2b]">Logo and colours</h2><p className="mt-1 text-xs text-[#829087]">These colours appear across school-branded reports and identity material.</p></div>
                            <div className="mt-5 grid gap-6 lg:grid-cols-[220px_1fr]">
                                <div>
                                    <div className="flex aspect-square max-w-[200px] items-center justify-center overflow-hidden border border-[#e3e8df] bg-[#f6f8f4]">
                                        {preview ? <img src={preview} alt="Selected school logo preview" className="size-full object-contain" /> : school.logo_path && !form.data.remove_logo ? <img src={`/storage/${school.logo_path}`} alt={`${school.name} logo`} className="size-full object-contain p-4" /> : <ImagePlus aria-hidden="true" className="size-8 text-[#94a397]" />}
                                    </div>
                                    <label className="mt-3 block text-xs font-semibold text-[#59695d]">Upload logo<input type="file" accept="image/*" onChange={(event) => form.setData('logo', event.target.files?.[0] ?? null)} className="mt-1.5 block w-full text-xs text-[#68776c] file:mr-2 file:rounded-sm file:border-0 file:bg-[#eaf1e8] file:px-3 file:py-2 file:text-xs file:font-semibold file:text-[#355e3c]" />{form.errors.logo && <span className="mt-1 block font-normal text-red-700">{form.errors.logo}</span>}</label>
                                    {school.logo_path && <label className="mt-3 flex items-center gap-2 text-xs text-[#6d7c70]"><input type="checkbox" checked={form.data.remove_logo} onChange={(event) => form.setData('remove_logo', event.target.checked)} className="accent-[#356344]" />Remove current logo</label>}
                                </div>
                                <div className="grid content-start gap-3 sm:grid-cols-3">{([['primary_color', 'Primary'], ['secondary_color', 'Secondary'], ['accent_color', 'Accent']] as const).map(([key, label]) => <label key={key} className="flex items-center gap-3 border border-[#e3e8df] p-3 text-xs font-semibold text-[#59695d]"><input type="color" value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} className="size-10 cursor-pointer border-0 bg-transparent p-0" /><span>{label}<span className="mt-1 block font-mono text-[10px] font-normal text-[#829087]">{form.data[key]}</span></span></label>)}</div>
                            </div>
                        </section>

                        <section className="border border-[#e3e8df] bg-white p-5 sm:p-7">
                            <div className="border-b border-[#edf0eb] pb-4"><h2 className="font-serif text-xl text-[#1b3b2b]">Motto and purpose</h2><p className="mt-1 text-xs text-[#829087]">Share the values and direction behind your school.</p></div>
                            <div className="mt-5 grid gap-4">
                                <label className="block text-xs font-semibold text-[#59695d]">Motto<input maxLength={255} value={form.data.motto} onChange={(event) => form.setData('motto', event.target.value)} className={textInput} />{form.errors.motto && <span className="mt-1 block font-normal text-red-700">{form.errors.motto}</span>}</label>
                                {(['mission', 'vision', 'aim'] as const).map((key) => <label key={key} className="block text-xs font-semibold capitalize text-[#59695d]">{key}<textarea rows={3} maxLength={5000} value={form.data[key]} onChange={(event) => form.setData(key, event.target.value)} className="mt-1.5 w-full resize-y rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#77947a]" />{form.errors[key] && <span className="mt-1 block font-normal text-red-700">{form.errors[key]}</span>}</label>)}
                            </div>
                        </section>

                        <div className="sticky bottom-3 flex justify-end border border-[#e3e8df] bg-[#f5f6f1]/95 p-3 backdrop-blur-sm">
                            <button disabled={form.processing} className="inline-flex h-11 items-center gap-2 rounded-md bg-[#244b35] px-5 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-50"><Check aria-hidden="true" className="size-4" />{form.processing ? 'Saving…' : 'Save school setup'}</button>
                        </div>
                    </form>
                </div>
            </main>
        </AppLayout>
    );
}