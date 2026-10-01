import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { BookOpen, GraduationCap, Landmark, Pencil, Plus, X } from 'lucide-react';
import { useState } from 'react';

type Curriculum = {
    id: number;
    code: string;
    name: string;
    authority: string;
    description: string | null;
    is_active: boolean;
    levels: { id: number; code: string; name: string; stage: string; order: number }[];
    subjects: { id: number; code: string; name: string; learning_area: string | null; is_core: boolean }[];
    schools: { id: number; name: string }[];
};
type CurriculumFormData = { code: string; name: string; authority: string; description: string };
type CurriculumLevel = { id: number; code: string; name: string; stage: string; order: number };
type LevelFormData = { code: string; name: string; stage: string; order: string };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'Curricula', href: '/admin/curricula' },
];

export default function CurriculaIndex({ curricula }: { curricula: Curriculum[] }) {
    const [showCreate, setShowCreate] = useState(false);
    const [editing, setEditing] = useState<Curriculum | null>(null);
    const [levelTarget, setLevelTarget] = useState<{ curriculum: Curriculum; level: CurriculumLevel | null } | null>(null);
    const createForm = useForm<CurriculumFormData>({ code: '', name: '', authority: 'KICD', description: '' });
    const editForm = useForm<CurriculumFormData>({ code: '', name: '', authority: 'KICD', description: '' });
    const levelForm = useForm<LevelFormData>({ code: '', name: '', stage: '', order: '1' });

    function createCurriculum(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        createForm.post('/admin/curricula', { preserveScroll: true, onSuccess: () => { createForm.reset(); setShowCreate(false); } });
    }

    function openEdit(curriculum: Curriculum) {
        setEditing(curriculum);
        editForm.setData({ code: curriculum.code, name: curriculum.name, authority: curriculum.authority, description: curriculum.description ?? '' });
        editForm.clearErrors();
    }

    function updateCurriculum(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!editing) return;
        editForm.put(`/admin/curricula/${editing.id}`, { preserveScroll: true, onSuccess: () => setEditing(null) });
    }

    function openCreateLevel(curriculum: Curriculum) {
        setLevelTarget({ curriculum, level: null });
        levelForm.setData({ code: '', name: '', stage: '', order: String(curriculum.levels.length + 1) });
        levelForm.clearErrors();
    }

    function openEditLevel(curriculum: Curriculum, level: CurriculumLevel) {
        setLevelTarget({ curriculum, level });
        levelForm.setData({ code: level.code, name: level.name, stage: level.stage, order: String(level.order) });
        levelForm.clearErrors();
    }

    function saveLevel(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!levelTarget) return;
        const options = { preserveScroll: true, onSuccess: () => setLevelTarget(null) };
        if (levelTarget.level) {
            levelForm.put(`/admin/curricula/${levelTarget.curriculum.id}/levels/${levelTarget.level.id}`, options);
        } else {
            levelForm.post(`/admin/curricula/${levelTarget.curriculum.id}/levels`, options);
        }
    }

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Curriculum catalogue" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8" style={{ fontFamily: 'DM Sans, sans-serif' }}>
                <div className="mx-auto max-w-[1280px]">
                    <header className="flex flex-wrap items-end justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">National administration · KICD</p>
                            <h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Curriculum catalogue</h1>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-[#718076]">National learning pathways and their levels, available for assignment to one or more schools.</p>
                        </div>
                        <button onClick={() => setShowCreate(true)} className="inline-flex h-10 items-center gap-2 bg-[#244b35] px-4 text-sm font-semibold text-white hover:bg-[#193c2a]"><Plus aria-hidden="true" className="size-4" /> Add curriculum</button>
                    </header>

                    <section aria-label="Curricula" className="mt-7 space-y-4">
                        {curricula.map((curriculum, index) => (
                            <article key={curriculum.id} className="overflow-hidden rounded-lg border border-[#e3e8df] bg-white">
                                <div className="flex flex-wrap items-start justify-between gap-5 border-b border-[#edf0eb] p-5 sm:p-6">
                                    <div className="flex min-w-0 items-start gap-4">
                                        <span className={`flex size-12 shrink-0 items-center justify-center rounded-md ${index % 2 ? 'bg-[#fff0dc] text-[#a75a1d]' : 'bg-[#e4f0e9] text-[#236345]'}`}><GraduationCap aria-hidden="true" className="size-6" /></span>
                                        <div className="min-w-0">
                                            <div className="flex flex-wrap items-center gap-2"><h2 className="font-serif text-2xl text-[#1b3b2b]">{curriculum.name}</h2><span className="rounded-sm bg-[#f0f3ed] px-2 py-1 text-xs font-semibold text-[#58705c]">{curriculum.code}</span><span className={`rounded-sm px-2 py-1 text-[10px] font-semibold uppercase ${curriculum.is_active ? 'bg-[#e8f1e8] text-[#3c7147]' : 'bg-[#f3eee2] text-[#92723f]'}`}>{curriculum.is_active ? 'Active' : 'Inactive'}</span></div>
                                            <p className="mt-1 text-xs font-medium text-[#829087]">Authority: {curriculum.authority}</p>
                                            {curriculum.description && <p className="mt-2 max-w-2xl text-sm leading-6 text-[#6f7c72]">{curriculum.description}</p>}
                                        </div>
                                    </div>
                                    <div className="flex items-start gap-4 text-xs text-[#758178]">
                                        <button type="button" onClick={() => openEdit(curriculum)} aria-label={`Edit ${curriculum.name}`} className="inline-flex h-9 items-center gap-1.5 border border-[#dfe5dc] px-3 text-xs font-semibold text-[#536659] hover:bg-[#f7f8f4]"><Pencil aria-hidden="true" className="size-3.5" />Edit details</button>
                                        <span className="flex items-center gap-1.5"><BookOpen aria-hidden="true" className="size-4" /> {curriculum.subjects.length} learning areas</span>
                                        <span className="flex items-center gap-1.5"><Landmark aria-hidden="true" className="size-4" /> {curriculum.schools.length} schools</span>
                                    </div>
                                </div>
                                <div className="grid gap-6 p-5 sm:p-6 lg:grid-cols-[1.15fr_0.85fr]">
                                    <section>
                                        <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="text-xs font-semibold tracking-[0.1em] text-[#77847a] uppercase">Learning stages</h3><button type="button" onClick={() => openCreateLevel(curriculum)} className="inline-flex h-8 items-center gap-1.5 border border-[#dfe5dc] px-2.5 text-xs font-semibold text-[#536659] hover:bg-[#f7f8f4]"><Plus aria-hidden="true" className="size-3.5" />Add level</button></div>
                                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                            {curriculum.levels.map((level) => (
                                                <div key={level.id} className="flex items-center gap-3 rounded-md bg-[#f5f7f2] px-3 py-2.5">
                                                    <span className="flex size-8 shrink-0 items-center justify-center rounded-sm bg-white text-[10px] font-bold text-[#64806a]">{level.code}</span>
                                                    <span className="min-w-0"><span className="block truncate text-sm font-medium text-[#405145]">{level.name}</span><span className="mt-0.5 block text-xs text-[#849087]">{level.stage}</span></span>
                                                    <button type="button" onClick={() => openEditLevel(curriculum, level)} aria-label={`Edit ${level.name}`} className="ml-auto flex size-8 shrink-0 items-center justify-center text-[#718076] hover:bg-white hover:text-[#355e3c]"><Pencil aria-hidden="true" className="size-3.5" /></button>
                                                </div>
                                            ))}
                                        </div>
                                    </section>
                                    <section>
                                        <h3 className="text-xs font-semibold tracking-[0.1em] text-[#77847a] uppercase">Assigned schools</h3>
                                        {curriculum.schools.length ? <ul className="mt-3 space-y-2">{curriculum.schools.map((school) => <li key={school.id} className="flex items-center gap-2 rounded-md border border-[#e9ede6] px-3 py-2.5 text-sm text-[#46594a]"><Landmark aria-hidden="true" className="size-4 text-[#7b927f]" />{school.name}</li>)}</ul> : <p className="mt-3 rounded-md bg-[#f5f7f2] px-3 py-3 text-sm text-[#818c83]">Not assigned to any school</p>}
                                    </section>
                                </div>
                            </article>
                        ))}
                        {!curricula.length && <div className="rounded-lg border border-dashed border-[#dce3d9] bg-white p-9 text-center"><BookOpen aria-hidden="true" className="mx-auto size-7 text-[#729078]" /><p className="mt-3 text-sm font-medium">No curriculum records found</p></div>}
                    </section>
                </div>
            </main>
            {showCreate && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setShowCreate(false); }}><section role="dialog" aria-modal="true" aria-labelledby="create-curriculum-title" className="my-auto w-full max-w-xl bg-[#fbfcf8] p-5 shadow-2xl sm:p-7"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase text-[#7d897f]">National catalogue</p><h2 id="create-curriculum-title" className="mt-1 font-serif text-2xl text-[#183b2a]">Add curriculum</h2></div><button type="button" aria-label="Close" onClick={() => setShowCreate(false)} className="p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div><form onSubmit={createCurriculum} className="mt-6 space-y-4">{([['code', 'Curriculum code'], ['name', 'Curriculum name'], ['authority', 'Authority']] as const).map(([field, label]) => <label key={field} className="block text-xs font-semibold text-[#59695d]">{label}<input required maxLength={field === 'code' ? 40 : 160} value={createForm.data[field]} onChange={(event) => createForm.setData(field, event.target.value)} className="mt-1.5 h-11 w-full rounded-sm border border-[#d9e0d9] bg-white px-3 text-sm outline-none focus:border-[#527e5c]" />{createForm.errors[field] && <span className="mt-1 block font-normal text-red-700">{createForm.errors[field]}</span>}</label>)}<label className="block text-xs font-semibold text-[#59695d]">Description<textarea rows={4} maxLength={5000} value={createForm.data.description} onChange={(event) => createForm.setData('description', event.target.value)} className="mt-1.5 w-full resize-y border border-[#d9e0d9] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#527e5c]" />{createForm.errors.description && <span className="mt-1 block font-normal text-red-700">{createForm.errors.description}</span>}</label><div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={() => setShowCreate(false)} className="h-10 border border-[#dfe5dc] px-4 text-sm text-[#596b5d]">Cancel</button><button disabled={createForm.processing} className="h-10 bg-[#244b35] px-4 text-sm font-semibold text-white disabled:opacity-50">Create pathway</button></div></form></section></div>}
            {editing && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setEditing(null); }}><section role="dialog" aria-modal="true" aria-labelledby="edit-curriculum-title" className="my-auto w-full max-w-xl bg-[#fbfcf8] p-5 shadow-2xl sm:p-7"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase text-[#7d897f]">National catalogue</p><h2 id="edit-curriculum-title" className="mt-1 font-serif text-2xl text-[#183b2a]">Edit curriculum</h2></div><button type="button" aria-label="Close" onClick={() => setEditing(null)} className="p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div><form onSubmit={updateCurriculum} className="mt-6 space-y-4">{([['code', 'Curriculum code'], ['name', 'Curriculum name'], ['authority', 'Authority']] as const).map(([field, label]) => <label key={field} className="block text-xs font-semibold text-[#59695d]">{label}<input required maxLength={field === 'code' ? 40 : 160} value={editForm.data[field]} onChange={(event) => editForm.setData(field, event.target.value)} className="mt-1.5 h-11 w-full rounded-sm border border-[#d9e0d9] bg-white px-3 text-sm outline-none focus:border-[#527e5c]" />{editForm.errors[field] && <span className="mt-1 block font-normal text-red-700">{editForm.errors[field]}</span>}</label>)}<label className="block text-xs font-semibold text-[#59695d]">Description<textarea rows={4} maxLength={5000} value={editForm.data.description} onChange={(event) => editForm.setData('description', event.target.value)} className="mt-1.5 w-full resize-y border border-[#d9e0d9] bg-white px-3 py-2.5 text-sm outline-none focus:border-[#527e5c]" />{editForm.errors.description && <span className="mt-1 block font-normal text-red-700">{editForm.errors.description}</span>}</label><div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={() => setEditing(null)} className="h-10 border border-[#dfe5dc] px-4 text-sm text-[#596b5d]">Cancel</button><button disabled={editForm.processing} className="h-10 bg-[#244b35] px-4 text-sm font-semibold text-white disabled:opacity-50">Save changes</button></div></form></section></div>}
            {levelTarget && <div className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-[#13271d]/55 p-3 backdrop-blur-[2px] sm:p-6" onMouseDown={(event) => { if (event.target === event.currentTarget) setLevelTarget(null); }}><section role="dialog" aria-modal="true" aria-labelledby="curriculum-level-title" className="my-auto w-full max-w-xl bg-[#fbfcf8] p-5 shadow-2xl sm:p-7"><div className="flex items-start justify-between gap-4"><div><p className="text-xs font-semibold uppercase text-[#7d897f]">{levelTarget.curriculum.name}</p><h2 id="curriculum-level-title" className="mt-1 font-serif text-2xl text-[#183b2a]">{levelTarget.level ? 'Edit learning stage' : 'Add learning stage'}</h2></div><button type="button" aria-label="Close" onClick={() => setLevelTarget(null)} className="p-2 text-[#718076] hover:bg-[#edf1e9]"><X aria-hidden="true" className="size-4" /></button></div><form onSubmit={saveLevel} className="mt-6 space-y-4"><div className="grid gap-3 sm:grid-cols-2">{([['code', 'Level code'], ['name', 'Level name'], ['stage', 'Stage / grouping'], ['order', 'Display order']] as const).map(([field, label]) => <label key={field} className="block text-xs font-semibold text-[#59695d]">{label}<input required maxLength={field === 'code' ? 40 : 160} type={field === 'order' ? 'number' : 'text'} min={field === 'order' ? 1 : undefined} max={field === 'order' ? 255 : undefined} value={levelForm.data[field]} onChange={(event) => levelForm.setData(field, event.target.value)} className="mt-1.5 h-11 w-full rounded-sm border border-[#d9e0d9] bg-white px-3 text-sm outline-none focus:border-[#527e5c]" />{levelForm.errors[field] && <span className="mt-1 block font-normal text-red-700">{levelForm.errors[field]}</span>}</label>)}</div><p className="text-xs leading-5 text-[#829087]">Updating a level keeps its linked learning areas attached.</p><div className="flex justify-end gap-2 border-t border-[#e8ece5] pt-4"><button type="button" onClick={() => setLevelTarget(null)} className="h-10 border border-[#dfe5dc] px-4 text-sm text-[#596b5d]">Cancel</button><button disabled={levelForm.processing} className="h-10 bg-[#244b35] px-4 text-sm font-semibold text-white disabled:opacity-50">{levelTarget.level ? 'Save level' : 'Add level'}</button></div></form></section></div>}
        </AppLayout>
    );
}