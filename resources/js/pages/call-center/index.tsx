import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Client, type ClientConfig, type Supervision, type SupervisionMode } from '@sautikit/webrtc';
import { ArrowDownLeft, ArrowUpRight, Headphones, Mic, MicOff, PhoneCall, PhoneOff, Settings2, Signal, Speaker, Voicemail } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Call = {
    id: number;
    direction: 'inbound' | 'outbound';
    from: string;
    to: string;
    status: string;
    provider_call_id: string | null;
    session_id: string | null;
    provider_number_id: string | null;
    started_at: string | null;
    created_at: string;
    agent: string | null;
};

type Contact = { id: number; name: string; phone: string; role: string };
type Routing = { number_id: string; agent_id: string } | null;
type SupervisionState = { callId: string; mode: SupervisionMode; client: Client; supervision: Supervision; muted: boolean };
type WebRtcToken = {
    token: string;
    endpoint: string;
    protocol?: string;
    turnServer?: ClientConfig['turnServer'];
};
type AudioOutputElement = HTMLAudioElement & {
    setSinkId?: (sinkId: string) => Promise<void>;
};
type ActiveCall = {
    client: Client;
    to: string;
    sessionId: string | null;
    status: 'calling' | 'ringing' | 'connected';
    muted: boolean;
};

const supervisionModes: { mode: SupervisionMode; label: string }[] = [
    { mode: 'listen', label: 'Listen' },
    { mode: 'whisper', label: 'Whisper' },
    { mode: 'barge', label: 'Talk over' },
];

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Overview', href: '/dashboard' },
    { title: 'Call centre', href: '/call-center' },
];

function formatDate(value: string | null) {
    return value ? new Date(value).toLocaleString() : 'Not available';
}

function statusStyle(status: string) {
    if (status === 'completed' || status === 'answered') return 'bg-[#e7f1e6] text-[#3c7147]';
    if (status === 'failed') return 'bg-[#f7e8e5] text-[#a54e3d]';
    return 'bg-[#fff2dc] text-[#95631f]';
}

function csrfHeaders() {
    const token = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/)?.[1];
    const headers: Record<string, string> = {};
    if (token) headers['X-XSRF-TOKEN'] = decodeURIComponent(token);
    return headers;
}

async function supervisionRequest<T>(url: string, method: 'GET' | 'POST' | 'PATCH' | 'DELETE', body?: unknown): Promise<T> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body ? { 'Content-Type': 'application/json' } : {}),
            ...(method === 'GET' ? {} : csrfHeaders()),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    const result = await response.json().catch(() => null);

    if (!response.ok) {
        const message = result?.errors?.call?.[0] ?? result?.message ?? 'The call supervision request could not be completed.';
        throw new Error(message);
    }

    return result as T;
}

function waitForRegistration(client: Client): Promise<void> {
    return new Promise((resolve, reject) => {
        const cleanup = () => {
            window.clearTimeout(timeout);
            client.off('registered');
            client.off('closed');
            client.off('error');
        };
        const onRegistered = () => {
            cleanup();
            resolve();
        };
        const onClosed = () => {
            cleanup();
            reject(new Error('SautiKit could not establish a WebRTC connection.'));
        };
        const onError = (error: Error & { code?: string }) => {
            cleanup();
            reject(new Error(error.message || 'SautiKit could not register the WebRTC dialer.'));
        };
        const timeout = window.setTimeout(() => {
            cleanup();
            reject(new Error('Timed out while registering the SautiKit dialer. Check the connection and dialer permissions.'));
        }, 90000);

        client.on('registered', onRegistered);
        client.on('closed', onClosed);
        client.on('error', onError);
    });
}

export default function CallCenterIndex({
    school,
    calls,
    contacts,
    direction,
    canManage,
    canConfigureInbound,
    inboundRouting,
    stats,
}: {
    school: { name: string; code: string; county: string | null; logo_path: string | null };
    calls: Call[];
    contacts: { parents: Contact[]; staff: Contact[] };
    direction: 'all' | 'inbound' | 'outbound';
    canManage: boolean;
    canConfigureInbound: boolean;
    inboundRouting: Routing;
    stats: { total: number; inbound: number; outbound: number; active: number };
}) {
    const dialForm = useForm({ to: '' });
    const routingForm = useForm({
        number_id: inboundRouting?.number_id ?? '',
        agent_id: inboundRouting?.agent_id ?? '',
    });
    const [supervision, setSupervision] = useState<SupervisionState | null>(null);
    const [pendingCallId, setPendingCallId] = useState<string | null>(null);
    const [supervisionError, setSupervisionError] = useState<string | null>(null);
    const [changingMode, setChangingMode] = useState(false);
    const [leavingCall, setLeavingCall] = useState(false);
    const supervisionRef = useRef<SupervisionState | null>(null);
    const [activeCall, setActiveCall] = useState<ActiveCall | null>(null);
    const activeCallRef = useRef<ActiveCall | null>(null);
    const [audioOutputs, setAudioOutputs] = useState<MediaDeviceInfo[]>([]);
    const [audioOutputId, setAudioOutputId] = useState('default');
    const [placingCall, setPlacingCall] = useState(false);
    const [callError, setCallError] = useState<string | null>(null);
    const [endingCallId, setEndingCallId] = useState<number | null>(null);
    const remoteAudioRef = useRef<AudioOutputElement | null>(null);
    const recordedSessionIds = useRef(new Set<string>());
    const callStartingRef = useRef(false);

    useEffect(() => {
        const refreshCalls = () => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['calls', 'stats'] });
            }
        };
        const timer = window.setInterval(refreshCalls, 5000);
        document.addEventListener('visibilitychange', refreshCalls);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', refreshCalls);
            const current = supervisionRef.current;
            if (current) {
                supervisionRef.current = null;
                current.client.destroy();
            }
            const call = activeCallRef.current;
            if (call) {
                call.client.hangup();
                call.client.destroy();
                activeCallRef.current = null;
            }
        };
    }, []);

    function setCurrentSupervision(next: SupervisionState | null) {
        supervisionRef.current = next;
        setSupervision(next);
    }

    function setCurrentCall(next: ActiveCall | null) {
        activeCallRef.current = next;
        setActiveCall(next);
    }

    async function refreshAudioOutputs() {
        if (!navigator.mediaDevices?.enumerateDevices) return;
        try {
            const devices = await navigator.mediaDevices.enumerateDevices();
            setAudioOutputs(devices.filter((device) => device.kind === 'audiooutput'));
        } catch (error) {
            setCallError(error instanceof Error ? error.message : 'Unable to list audio output devices.');
        }
    }

    async function chooseAudioOutput(deviceId: string) {
        const audio = remoteAudioRef.current;
        if (!audio?.setSinkId) {
            setCallError('This browser does not support selecting an audio output device.');
            return;
        }
        try {
            await audio.setSinkId(deviceId);
            setAudioOutputId(deviceId);
            setCallError(null);
        } catch (error) {
            setCallError(error instanceof Error ? error.message : 'Unable to change the audio output.');
        }
    }

    async function placeCall(destination: string) {
        const to = destination.trim();
        if (!/^\+[1-9][0-9]{7,14}$/.test(to)) {
            dialForm.setError('to', 'Enter a valid international phone number.');
            return;
        }
        if (callStartingRef.current || activeCallRef.current || supervisionRef.current || stats.active > 0) return;
        if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
            setCallError(
                'Browser calls require microphone access over HTTPS. Open the call centre on a secure connection and allow microphone access.',
            );
            return;
        }

        callStartingRef.current = true;
        setPlacingCall(true);
        setCallError(null);
        setSupervisionError(null);
        dialForm.clearErrors();
        let client: Client | null = null;
        try {
            const token = await supervisionRequest<WebRtcToken>('/call-center/webrtc-token', 'GET');
            const audioElement = remoteAudioRef.current;
            if (!audioElement) throw new Error('Audio output is not ready.');
            client = new Client({
                token: token.token,
                endpoint: token.endpoint,
                protocol: token.protocol,
                turnServer: token.turnServer,
                audioElement,
            });
            client.on('session', ({ sessionId }) => {
                const current = activeCallRef.current;
                if (current?.client !== client) return;
                setCurrentCall({ ...current, sessionId });
                void recordWebRtcCall(to, sessionId);
            });
            client.on('ringing', () => {
                const current = activeCallRef.current;
                if (current?.client === client) setCurrentCall({ ...current, status: 'ringing' });
            });
            client.on('answered', () => {
                const current = activeCallRef.current;
                if (current?.client === client) setCurrentCall({ ...current, status: 'connected' });
            });
            client.on('hangup', ({ reason, code }) => {
                if (activeCallRef.current?.client !== client) return;
                const call = activeCallRef.current;
                client?.destroy();
                setCurrentCall(null);
                callStartingRef.current = false;
                const hangupDetails = reason ?? code?.toString();
                setCallError(
                    call?.status === 'connected'
                        ? hangupDetails
                            ? `The call ended: ${hangupDetails}.`
                            : 'The call ended.'
                        : hangupDetails
                          ? `The call ended before connecting: ${hangupDetails}.`
                          : 'The call ended before connecting. Check the destination and SautiKit account settings, then try again.',
                );
                dialForm.reset();
                router.reload({ only: ['calls', 'stats'] });
            });
            client.on('declined', ({ reason }) => {
                if (activeCallRef.current?.client !== client) return;
                client?.destroy();
                setCurrentCall(null);
                callStartingRef.current = false;
                setCallError(reason ? `The recipient declined the call: ${reason}` : 'The recipient declined the call.');
                router.reload({ only: ['calls', 'stats'] });
            });
            await waitForRegistration(client);
            client.on('error', (error) => setCallError(error.message));
            client.on('closed', ({ reason }) => {
                if (activeCallRef.current?.client !== client) return;
                client?.destroy();
                setCurrentCall(null);
                callStartingRef.current = false;
                setCallError(reason ? `The WebRTC connection closed: ${reason}` : 'The WebRTC connection closed before the call completed.');
                router.reload({ only: ['calls', 'stats'] });
            });
            setCurrentCall({ client, to, sessionId: client.sessionId, status: 'calling', muted: false });
            if (audioElement.setSinkId) {
                try {
                    await audioElement.setSinkId(audioOutputId);
                } catch (error) {
                    setCallError(error instanceof Error ? error.message : 'Unable to select the audio output. Using the default device.');
                }
            }
            await client.call(to);
            void refreshAudioOutputs();
            if (client.sessionId) void recordWebRtcCall(to, client.sessionId);
            dialForm.reset();
        } catch (error) {
            client?.destroy();
            setCurrentCall(null);
            setCallError(error instanceof Error ? error.message : 'Unable to place the call.');
        } finally {
            callStartingRef.current = false;
            setPlacingCall(false);
        }
    }

    function startCall(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        void placeCall(dialForm.data.to);
    }

    function dialContact(contact: Contact) {
        dialForm.setData('to', contact.phone);
        void placeCall(contact.phone);
    }

    function hangUpActiveCall() {
        const current = activeCallRef.current;
        if (!current) return;
        current.client.hangup();
        current.client.destroy();
        setCurrentCall(null);
        callStartingRef.current = false;
        router.reload({ only: ['calls', 'stats'] });
    }

    function toggleMute() {
        const current = activeCallRef.current;
        if (!current) return;
        if (current.muted) current.client.unmute();
        else current.client.mute();
        setCurrentCall({ ...current, muted: !current.muted });
    }

    function toggleSupervisionMute() {
        const current = supervisionRef.current;
        if (!current) return;
        if (current.muted) current.client.unmute();
        else current.client.mute();
        setCurrentSupervision({ ...current, muted: !current.muted });
    }

    async function recordWebRtcCall(to: string, sessionId: string) {
        if (recordedSessionIds.current.has(sessionId)) return;
        recordedSessionIds.current.add(sessionId);
        try {
            await supervisionRequest('/call-center/calls/active', 'POST', { to, session_id: sessionId });
            router.reload({ only: ['calls', 'stats'] });
        } catch (error) {
            setCallError(error instanceof Error ? error.message : 'Call history could not be saved.');
        }
    }

    async function hangUpRecordedCall(call: Call) {
        setEndingCallId(call.id);
        setSupervisionError(null);
        try {
            await supervisionRequest(`/call-center/calls/${call.id}/hangup`, 'POST');
            router.reload({ only: ['calls', 'stats'] });
        } catch (error) {
            setSupervisionError(error instanceof Error ? error.message : 'Unable to end this call.');
        } finally {
            setEndingCallId(null);
        }
    }

    async function joinCall(call: Call) {
        const callId = call.session_id ?? call.provider_call_id;
        if (!callId) return;

        setPendingCallId(callId);
        setSupervisionError(null);
        let client: Client | null = null;

        try {
            const token = await supervisionRequest<WebRtcToken>('/call-center/webrtc-token', 'GET');
            const clientConfig: ClientConfig = {
                token: token.token,
                endpoint: token.endpoint,
                protocol: token.protocol,
                supervision: {
                    start: (id, request) =>
                        supervisionRequest(`/call-center/calls/${encodeURIComponent(id)}/supervision`, 'POST', {
                            mode: request.mode,
                            announce: request.announce,
                            label: request.label,
                            notifyAgent: request.notifyAgent,
                        }),
                    setMode: async (id, mode) => {
                        await supervisionRequest(`/call-center/calls/${encodeURIComponent(id)}/supervision`, 'PATCH', { mode });
                    },
                    stop: async (id) => {
                        await supervisionRequest(`/call-center/calls/${encodeURIComponent(id)}/supervision`, 'DELETE');
                    },
                },
                audioElement: remoteAudioRef.current ?? undefined,
            };
            if (token.turnServer) clientConfig.turnServer = token.turnServer;

            client = new Client(clientConfig);
            client.on('error', (error) => setSupervisionError(error.message));
            await waitForRegistration(client);
            await refreshAudioOutputs();
            const started = await client.supervise(callId, {
                mode: 'listen',
                announce: 'none',
                label: 'School call centre',
                notifyAgent: true,
            });
            const current: SupervisionState = { callId, mode: started.mode, client, supervision: started, muted: false };
            started.on('mode_changed', ({ to }) => {
                const latest = supervisionRef.current;
                if (latest?.supervision === started) setCurrentSupervision({ ...latest, mode: to });
            });
            started.on('ended', () => {
                current.client.destroy();
                if (supervisionRef.current?.supervision === started) setCurrentSupervision(null);
            });
            setCurrentSupervision(current);
        } catch (error) {
            client?.destroy();
            setSupervisionError(error instanceof Error ? error.message : 'Unable to connect to this call.');
        } finally {
            setPendingCallId(null);
        }
    }

    async function changeSupervisionMode(mode: SupervisionMode) {
        const current = supervisionRef.current;
        if (!current) return;

        setSupervisionError(null);
        setChangingMode(true);
        try {
            await current.supervision.setMode(mode);
            setCurrentSupervision({ ...current, mode });
        } catch (error) {
            setSupervisionError(error instanceof Error ? error.message : 'Unable to change the supervision mode.');
        } finally {
            setChangingMode(false);
        }
    }

    async function leaveCall() {
        const current = supervisionRef.current;
        if (!current) return;

        setSupervisionError(null);
        setLeavingCall(true);
        try {
            await current.supervision.stop();
            current.client.destroy();
            setCurrentSupervision(null);
        } catch (error) {
            setSupervisionError(error instanceof Error ? error.message : 'Unable to leave the call.');
        } finally {
            setLeavingCall(false);
        }
    }

    function saveRouting(event: React.FormEvent<HTMLFormElement>) {
        event.preventDefault();
        routingForm.put('/call-center/inbound-routing', { preserveScroll: true });
    }

    function changeDirection(value: 'all' | 'inbound' | 'outbound') {
        router.get('/call-center', { direction: value === 'all' ? undefined : value }, { preserveScroll: true, preserveState: true });
    }

    const statCards = [
        { label: 'All calls', value: stats.total, icon: PhoneCall, tone: 'text-[#426971]' },
        { label: 'Inbound', value: stats.inbound, icon: ArrowDownLeft, tone: 'text-[#397145]' },
        { label: 'Outbound', value: stats.outbound, icon: ArrowUpRight, tone: 'text-[#9c593d]' },
        { label: 'In progress', value: stats.active, icon: Signal, tone: 'text-[#95631f]' },
    ];
    const callBlocked = placingCall || activeCall !== null || supervision !== null || stats.active > 0;
    const directoryContacts = [...contacts.parents, ...contacts.staff].sort((first, second) => first.name.localeCompare(second.name));

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Call centre" />
            <main className="min-h-full bg-[#f5f6f1] px-4 py-6 text-[#25382d] sm:px-6 lg:px-9 lg:py-8">
                <div className="mx-auto max-w-[1440px]">
                    <header className="flex flex-wrap items-center gap-3">
                        <div className="flex size-12 shrink-0 items-center justify-center overflow-hidden rounded-md bg-[#193f30] text-[#f1bf79]">
                            {school.logo_path ? (
                                <img src={`/storage/${school.logo_path}`} alt="" className="size-full object-cover" />
                            ) : (
                                <PhoneCall aria-hidden="true" className="size-6" />
                            )}
                        </div>
                        <div>
                            <p className="text-xs font-semibold tracking-[0.14em] text-[#7d897f] uppercase">
                                {school.county ? `${school.county} County · ` : ''}
                                {school.code}
                            </p>
                            <h1 className="mt-1 font-serif text-3xl text-[#173b2a] sm:text-4xl">Call centre</h1>
                            <p className="mt-1 text-sm text-[#718076]">{school.name} · Inbound and outbound calls</p>
                        </div>
                    </header>

                    <section aria-label="Call centre summary" className="mt-7 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                        {statCards.map((stat) => (
                            <article key={stat.label} className="rounded-lg border border-[#e3e8df] bg-white p-4 sm:p-5">
                                <div className="flex items-center justify-between">
                                    <p className="text-xs font-semibold tracking-[0.08em] text-[#7c887f] uppercase">{stat.label}</p>
                                    <stat.icon aria-hidden="true" className={`size-4 ${stat.tone}`} />
                                </div>
                                <p className="mt-3 text-2xl font-semibold text-[#203a2a]">{stat.value}</p>
                            </article>
                        ))}
                        <button
                            type="button"
                            disabled={changingMode || leavingCall}
                            onClick={toggleSupervisionMute}
                            className="inline-flex items-center gap-1.5 rounded-md border border-[#dfe5dc] bg-white px-3 py-2 text-xs font-semibold text-[#3c6745] disabled:opacity-50"
                        >
                            {supervision?.muted ? (
                                <MicOff aria-hidden="true" className="size-3.5" />
                            ) : (
                                <Mic aria-hidden="true" className="size-3.5" />
                            )}
                            {supervision?.muted ? 'Unmute' : 'Mute'}
                        </button>
                        {audioOutputs.length > 0 && (
                            <label className="flex items-center gap-1.5 text-xs text-[#657469]">
                                <Speaker aria-hidden="true" className="size-3.5" />
                                <select
                                    aria-label="Supervision audio output"
                                    value={audioOutputId}
                                    onChange={(event) => void chooseAudioOutput(event.target.value)}
                                    className="rounded-md border border-[#dfe5dc] bg-white px-2 py-2"
                                >
                                    {audioOutputs.map((device, index) => (
                                        <option key={device.deviceId || index} value={device.deviceId || 'default'}>
                                            {device.label || `Audio output ${index + 1}`}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                    </section>

                    <div className="mt-7 grid gap-5 xl:grid-cols-2">
                        {canManage && (
                            <section className="order-2 rounded-lg border border-[#e3e8df] bg-white p-5 sm:p-6 xl:order-2">
                                <div className="flex items-start gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-[#e5efe3] text-[#397145]">
                                        <PhoneCall aria-hidden="true" className="size-5" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-[#819087] uppercase">Outbound</p>
                                        <h2 className="mt-1 font-serif text-xl text-[#1b3b2b]">Place a call</h2>
                                        <p className="mt-1 text-sm text-[#718076]">Dial an E.164 number using your configured SautiKit caller ID.</p>
                                    </div>
                                </div>
                                <form onSubmit={(event) => void startCall(event)} className="mt-5 flex flex-col gap-3 sm:flex-row sm:items-start">
                                    <label className="min-w-0 flex-1 text-xs font-medium text-[#657469]">
                                        Destination phone number
                                        <input
                                            type="tel"
                                            inputMode="tel"
                                            placeholder="+254712345678"
                                            value={dialForm.data.to}
                                            onChange={(event) => dialForm.setData('to', event.target.value)}
                                            className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]"
                                            aria-describedby={dialForm.errors.to ? 'call-to-error' : undefined}
                                        />
                                        {dialForm.errors.to && (
                                            <span id="call-to-error" className="mt-1 block text-xs text-red-700">
                                                {dialForm.errors.to}
                                            </span>
                                        )}
                                    </label>
                                    <button
                                        type="submit"
                                        disabled={callBlocked}
                                        className="inline-flex shrink-0 items-center justify-center gap-2 rounded-md bg-[#244b35] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#193c2a] disabled:opacity-60 sm:mt-5"
                                    >
                                        <PhoneCall aria-hidden="true" className="size-4" /> {placingCall ? 'Connecting…' : 'Call'}
                                    </button>
                                </form>
                                {stats.active > 0 && !activeCall && !supervision && (
                                    <p role="status" className="mt-3 text-sm text-[#95631f]">
                                        Another call is already in progress. End it before dialing another number.
                                    </p>
                                )}
                                {callError && (
                                    <p role="alert" className="mt-3 text-sm text-red-700">
                                        {callError}
                                    </p>
                                )}
                                {activeCall && (
                                    <div className="mt-4 rounded-md border border-[#dfe5dc] bg-[#f8faf7] p-4">
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-[#244b35]">
                                                    {activeCall.status === 'connected'
                                                        ? 'Live call'
                                                        : activeCall.status === 'ringing'
                                                          ? 'Ringing'
                                                          : 'Connecting'}{' '}
                                                    · {activeCall.to}
                                                </p>
                                                <p className="mt-1 text-xs text-[#718076]">Ending this call disconnects the caller and recipient.</p>
                                            </div>
                                            <div className="flex flex-wrap items-center gap-2">
                                                {audioOutputs.length > 0 && (
                                                    <label className="flex items-center gap-2 text-xs text-[#657469]">
                                                        <Speaker aria-hidden="true" className="size-4" />
                                                        <select
                                                            aria-label="Audio output device"
                                                            value={audioOutputId}
                                                            onChange={(event) => void chooseAudioOutput(event.target.value)}
                                                            className="rounded-md border border-[#dfe5dc] bg-white px-2 py-2"
                                                        >
                                                            {audioOutputs.map((device, index) => (
                                                                <option key={device.deviceId || index} value={device.deviceId || 'default'}>
                                                                    {device.label || `Audio output ${index + 1}`}
                                                                </option>
                                                            ))}
                                                        </select>
                                                    </label>
                                                )}
                                                <button
                                                    type="button"
                                                    onClick={toggleMute}
                                                    className="inline-flex items-center gap-2 rounded-md border border-[#b9cbb9] px-3 py-2 text-xs font-semibold text-[#3c6745]"
                                                >
                                                    {activeCall.muted ? (
                                                        <MicOff aria-hidden="true" className="size-4" />
                                                    ) : (
                                                        <Mic aria-hidden="true" className="size-4" />
                                                    )}
                                                    {activeCall.muted ? 'Unmute' : 'Mute'}
                                                </button>
                                                <button
                                                    type="button"
                                                    onClick={hangUpActiveCall}
                                                    className="inline-flex items-center gap-2 rounded-md bg-[#a94338] px-3 py-2 text-xs font-semibold text-white hover:bg-[#8e352d]"
                                                >
                                                    <PhoneOff aria-hidden="true" className="size-4" /> End call for everyone
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                )}
                            </section>
                        )}

                        {canManage && (
                            <section className="order-1 rounded-lg border border-[#e3e8df] bg-white p-5 sm:p-6 xl:order-1">
                                <div>
                                    <p className="text-xs font-semibold tracking-[0.12em] text-[#819087] uppercase">School directory</p>
                                    <h2 className="mt-1 font-serif text-xl text-[#1b3b2b]">Parents and staff</h2>
                                    <p className="mt-1 text-sm text-[#718076]">
                                        Numbers are shown with a country code. Select the dial icon to place a call.
                                    </p>
                                </div>
                                {directoryContacts.length ? (
                                    <ul className="mt-5 divide-y divide-[#edf0eb] rounded-md border border-[#edf0eb]">
                                        {directoryContacts.map((contact) => (
                                            <li key={`${contact.role}-${contact.id}`} className="flex items-center justify-between gap-3 px-3 py-2.5">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-medium text-[#34483a]">
                                                        {contact.name} <span className="font-normal text-[#718076]">({contact.role})</span>
                                                    </p>
                                                    <p className="mt-0.5 text-xs text-[#718076]">{contact.phone}</p>
                                                </div>
                                                <button
                                                    type="button"
                                                    aria-label={`Call ${contact.name} at ${contact.phone}`}
                                                    title={`Call ${contact.name}`}
                                                    disabled={callBlocked}
                                                    onClick={() => dialContact(contact)}
                                                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-md bg-[#e5efe3] text-[#397145] hover:bg-[#d8e8d4] disabled:cursor-not-allowed disabled:opacity-50"
                                                >
                                                    <PhoneCall aria-hidden="true" className="size-4" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="mt-5 rounded-md bg-[#f8f9f6] px-3 py-4 text-sm text-[#849087]">
                                        No contacts with callable phone numbers.
                                    </p>
                                )}
                            </section>
                        )}

                        {canConfigureInbound && (
                            <section className="rounded-lg border border-[#e3e8df] bg-white p-5 sm:p-6 xl:col-span-2">
                                <div className="flex items-start gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-md bg-[#e6eff5] text-[#38617a]">
                                        <Settings2 aria-hidden="true" className="size-5" />
                                    </span>
                                    <div>
                                        <p className="text-xs font-semibold tracking-[0.12em] text-[#819087] uppercase">Inbound routing</p>
                                        <h2 className="mt-1 font-serif text-xl text-[#1b3b2b]">SautiKit AI agent</h2>
                                        <p className="mt-1 text-sm text-[#718076]">Route calls on a SautiKit number to a published AI agent.</p>
                                    </div>
                                </div>
                                <form onSubmit={saveRouting} className="mt-5 space-y-3">
                                    <label className="block text-xs font-medium text-[#657469]">
                                        SautiKit number ID
                                        <input
                                            value={routingForm.data.number_id}
                                            onChange={(event) => routingForm.setData('number_id', event.target.value)}
                                            className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]"
                                        />
                                        {routingForm.errors.number_id && (
                                            <span className="mt-1 block text-xs text-red-700">{routingForm.errors.number_id}</span>
                                        )}
                                    </label>
                                    <label className="block text-xs font-medium text-[#657469]">
                                        Published AI agent ID
                                        <input
                                            value={routingForm.data.agent_id}
                                            onChange={(event) => routingForm.setData('agent_id', event.target.value)}
                                            className="mt-1.5 w-full rounded-md border border-[#dfe5dc] bg-white px-3 py-2.5 text-sm text-[#304337] outline-none focus:border-[#77947a]"
                                        />
                                        {routingForm.errors.agent_id && (
                                            <span className="mt-1 block text-xs text-red-700">{routingForm.errors.agent_id}</span>
                                        )}
                                    </label>
                                    <p className="flex items-start gap-2 rounded-md bg-[#f4f6f1] p-3 text-xs leading-relaxed text-[#718076]">
                                        <Voicemail aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                        Calls handed off by the AI agent fall back to voicemail. Configure this number and agent in your SautiKit
                                        workspace first.
                                    </p>
                                    <button
                                        type="submit"
                                        disabled={routingForm.processing}
                                        className="rounded-md border border-[#b9cbb9] px-4 py-2.5 text-sm font-semibold text-[#3c6745] hover:bg-[#edf4ec] disabled:opacity-60"
                                    >
                                        {routingForm.processing ? 'Saving…' : inboundRouting ? 'Update inbound routing' : 'Enable inbound routing'}
                                    </button>
                                </form>
                            </section>
                        )}
                    </div>

                    <section className="mt-7 overflow-hidden rounded-lg border border-[#e3e8df] bg-white">
                        <div className="flex flex-wrap items-end justify-between gap-3 border-b border-[#e8ece5] px-4 py-4 sm:px-5">
                            <div>
                                <p className="text-xs font-semibold tracking-[0.12em] text-[#819087] uppercase">Call history</p>
                                <h2 className="mt-1 font-serif text-2xl text-[#1b3b2b]">Recent calls</h2>
                            </div>
                            <label className="text-xs font-medium text-[#657469]">
                                <span className="sr-only">Filter calls by direction</span>
                                <select
                                    value={direction}
                                    onChange={(event) => changeDirection(event.target.value as 'all' | 'inbound' | 'outbound')}
                                    className="rounded-md border border-[#dfe5dc] bg-white px-3 py-2 text-sm text-[#304337]"
                                >
                                    <option value="all">All calls</option>
                                    <option value="inbound">Inbound</option>
                                    <option value="outbound">Outbound</option>
                                </select>
                            </label>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[700px] text-left text-sm">
                                <thead className="bg-[#f8f9f6] text-[10px] tracking-[0.1em] text-[#819087] uppercase">
                                    <tr>
                                        <th className="px-4 py-3 font-semibold sm:px-5">Direction</th>
                                        <th className="px-4 py-3 font-semibold">From / To</th>
                                        <th className="px-4 py-3 font-semibold">Status</th>
                                        <th className="px-4 py-3 font-semibold">Agent</th>
                                        <th className="px-4 py-3 font-semibold">Date</th>
                                        {canManage && <th className="px-4 py-3 font-semibold">Live call</th>}
                                    </tr>
                                </thead>
                                <tbody className="divide-y divide-[#edf0eb]">
                                    {calls.map((call) => (
                                        <tr key={call.id}>
                                            <td className="px-4 py-3 sm:px-5">
                                                <span
                                                    className={`inline-flex items-center gap-1.5 rounded-sm px-2 py-1 text-[10px] font-semibold uppercase ${call.direction === 'inbound' ? 'bg-[#e7f1e6] text-[#3c7147]' : 'bg-[#e6eff5] text-[#38617a]'}`}
                                                >
                                                    {call.direction === 'inbound' ? (
                                                        <ArrowDownLeft aria-hidden="true" className="size-3" />
                                                    ) : (
                                                        <ArrowUpRight aria-hidden="true" className="size-3" />
                                                    )}
                                                    {call.direction}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <p className="font-medium text-[#34483a]">{call.from}</p>
                                                <p className="mt-0.5 text-xs text-[#849087]">to {call.to}</p>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span
                                                    className={`rounded-sm px-2 py-1 text-[10px] font-semibold uppercase ${statusStyle(call.status)}`}
                                                >
                                                    {call.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-[#718076]">{call.agent ?? '—'}</td>
                                            <td className="px-4 py-3 text-xs text-[#718076]">{formatDate(call.started_at ?? call.created_at)}</td>
                                            {canManage && (
                                                <td className="px-4 py-3">
                                                    {(() => {
                                                        const callId = call.session_id ?? call.provider_call_id;
                                                        const aiAgentCall =
                                                            call.direction === 'inbound' && call.provider_number_id === inboundRouting?.number_id;
                                                        const canJoin = call.status === 'answered' && callId && !aiAgentCall;
                                                        const canEnd = call.status !== 'completed' && call.status !== 'failed';
                                                        return (
                                                            <div className="flex flex-wrap items-center gap-2">
                                                                {aiAgentCall && call.status === 'answered' ? (
                                                                    <span
                                                                        className="text-xs text-[#849087]"
                                                                        title="SautiKit does not support supervision of AI-agent calls"
                                                                    >
                                                                        AI-agent call
                                                                    </span>
                                                                ) : supervision?.callId === callId ? (
                                                                    <span className="inline-flex items-center gap-1.5 text-xs font-semibold text-[#397145]">
                                                                        <Headphones aria-hidden="true" className="size-3.5" />
                                                                        Connected
                                                                    </span>
                                                                ) : canJoin ? (
                                                                    <button
                                                                        type="button"
                                                                        disabled={
                                                                            pendingCallId !== null ||
                                                                            supervision !== null ||
                                                                            activeCall !== null ||
                                                                            endingCallId !== null
                                                                        }
                                                                        onClick={() => void joinCall(call)}
                                                                        className="rounded-md border border-[#b9cbb9] px-2.5 py-1.5 text-xs font-semibold text-[#3c6745] hover:bg-[#edf4ec] disabled:cursor-not-allowed disabled:opacity-50"
                                                                    >
                                                                        {pendingCallId === callId ? 'Connecting…' : 'Connect'}
                                                                    </button>
                                                                ) : (
                                                                    <span className="text-xs text-[#a0aaa2]">—</span>
                                                                )}
                                                                {canEnd && (
                                                                    <button
                                                                        type="button"
                                                                        disabled={endingCallId !== null}
                                                                        onClick={() => void hangUpRecordedCall(call)}
                                                                        className="inline-flex items-center gap-1 rounded-md border border-[#e1c7c1] px-2.5 py-1.5 text-xs font-semibold text-[#a54e3d] hover:bg-[#fff2ef] disabled:cursor-not-allowed disabled:opacity-50"
                                                                    >
                                                                        <PhoneOff aria-hidden="true" className="size-3.5" />
                                                                        {endingCallId === call.id ? 'Ending…' : 'End call'}
                                                                    </button>
                                                                )}
                                                            </div>
                                                        );
                                                    })()}
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                    {!calls.length && (
                                        <tr>
                                            <td colSpan={canManage ? 6 : 5} className="px-5 py-12 text-center">
                                                <PhoneCall aria-hidden="true" className="mx-auto size-6 text-[#91a096]" />
                                                <p className="mt-2 text-sm font-medium text-[#394c3f]">No calls yet</p>
                                                <p className="mt-1 text-xs text-[#849087]">
                                                    Inbound and outbound calls will appear here as they are recorded.
                                                </p>
                                            </td>
                                        </tr>
                                    )}
                                </tbody>
                            </table>
                        </div>
                        {supervisionError && (
                            <p role="alert" className="border-t border-[#f0d8d2] bg-[#fff8f6] px-4 py-3 text-sm text-[#a54e3d] sm:px-5">
                                {supervisionError}
                            </p>
                        )}
                        {supervision && (
                            <div className="border-t border-[#e8ece5] bg-[#f8faf7] px-4 py-4 sm:px-5">
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <div>
                                        <p className="flex items-center gap-2 text-sm font-semibold text-[#244b35]">
                                            <Headphones aria-hidden="true" className="size-4" />
                                            Connected to live call
                                        </p>
                                        <p className="mt-1 text-xs text-[#718076]">
                                            Choose listen, coach the agent privately, or talk to both sides. Disconnect ends only your supervisor
                                            audio leg; it does not end the customer call.
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-center gap-2">
                                        {supervisionModes.map(({ mode, label }) => (
                                            <button
                                                key={mode}
                                                type="button"
                                                aria-pressed={supervision.mode === mode}
                                                disabled={changingMode || leavingCall}
                                                onClick={() => void changeSupervisionMode(mode)}
                                                className={`rounded-md px-3 py-2 text-xs font-semibold disabled:cursor-not-allowed disabled:opacity-50 ${supervision.mode === mode ? 'bg-[#244b35] text-white' : 'border border-[#dfe5dc] bg-white text-[#3c6745] hover:bg-[#edf4ec]'}`}
                                            >
                                                {label}
                                            </button>
                                        ))}
                                        <button
                                            type="button"
                                            disabled={changingMode || leavingCall}
                                            onClick={() => void leaveCall()}
                                            className="rounded-md border border-[#e1c7c1] px-3 py-2 text-xs font-semibold text-[#a54e3d] hover:bg-[#fff2ef] disabled:cursor-not-allowed disabled:opacity-50"
                                        >
                                            {leavingCall ? 'Disconnecting…' : 'Disconnect'}
                                        </button>
                                    </div>
                                </div>
                            </div>
                        )}
                        {canManage && (
                            <p className="border-t border-[#e8ece5] px-4 py-3 text-xs text-[#849087] sm:px-5">
                                <audio ref={remoteAudioRef} id="remoteAudio" autoPlay className="hidden" />
                                Live call controls refresh automatically. Outbound calls placed here can be ended for everyone. Supervision
                                disconnects only your staff audio leg. Only answered calls are eligible; SautiKit does not support supervision of
                                calls handled by an AI agent.
                            </p>
                        )}
                        <p className="border-t border-[#e8ece5] px-4 py-3 text-xs text-[#849087] sm:px-5">Showing up to 100 most recent calls.</p>
                    </section>
                </div>
            </main>
        </AppLayout>
    );
}
