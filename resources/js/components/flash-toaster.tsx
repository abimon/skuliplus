import { usePage } from '@inertiajs/react';
import { Check, X } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type ToastMessage = { id: number; kind: 'success' | 'error'; message: string };
type FlashProps = {
    flash?: { success?: string; error?: string };
    errors?: Record<string, string | string[]>;
};

export function FlashToaster() {
    const { flash, errors = {} } = usePage<FlashProps>().props;
    const [toast, setToast] = useState<ToastMessage | null>(null);
    const seen = useRef('');
    const nextId = useRef(0);
    const errorMessage = Object.values(errors).flat()[0];
    const message = flash?.error ?? errorMessage ?? flash?.success;
    const kind = flash?.error || errorMessage ? 'error' : 'success';

    useEffect(() => {
        if (!message) {
            seen.current = '';
            return;
        }
        const signature = `${kind}:${message}`;
        if (seen.current === signature) return;
        seen.current = signature;
        setToast({ id: ++nextId.current, kind, message });
    }, [kind, message]);

    useEffect(() => {
        if (!toast) return;
        const timeout = window.setTimeout(() => setToast(null), 6000);
        return () => window.clearTimeout(timeout);
    }, [toast]);

    if (!toast) return null;

    return <div className="pointer-events-none fixed inset-x-4 top-4 z-[100] flex justify-center sm:inset-x-auto sm:right-6 sm:justify-end" aria-live={toast.kind === 'error' ? 'assertive' : 'polite'}>
        <div role={toast.kind === 'error' ? 'alert' : 'status'} className={`pointer-events-auto flex w-full max-w-md items-start gap-3 border bg-white px-4 py-3 shadow-[0_12px_36px_rgba(25,47,32,0.18)] ${toast.kind === 'success' ? 'border-[#cbdccb]' : 'border-[#e5c9c1]'}`}>
            <span className={`mt-0.5 flex size-6 shrink-0 items-center justify-center ${toast.kind === 'success' ? 'bg-[#e7f1e6] text-[#3c7147]' : 'bg-[#f8ebe7] text-[#a34d3c]'}`}>
                {toast.kind === 'success' ? <Check aria-hidden="true" className="size-4" /> : <X aria-hidden="true" className="size-4" />}
            </span>
            <p className={`flex-1 text-sm leading-5 ${toast.kind === 'success' ? 'text-[#345b3b]' : 'text-[#8f4034]'}`}>{toast.message}</p>
            <button type="button" aria-label="Dismiss notification" onClick={() => setToast(null)} className="-mr-1 -mt-1 flex size-7 shrink-0 items-center justify-center text-[#829087] hover:bg-[#f2f4ef]"><X aria-hidden="true" className="size-3.5" /></button>
        </div>
    </div>;
}