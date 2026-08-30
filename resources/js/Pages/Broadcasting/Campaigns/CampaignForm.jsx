import { useEffect, useMemo, useRef, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Trans, useTranslation } from 'react-i18next';
import axios from 'axios';
import {
    ArrowLeft,
    ArrowRight,
    Send,
    Users,
    Search,
    Variable,
    Eye,
    AlertCircle,
    CheckCircle2,
    Loader2,
    Save,
    Upload,
    Link as LinkIcon,
} from 'lucide-react';
import { browserTz, formatInTz, tzLocalToUtcIso, utcToTzLocal } from '@/Utils/datetime';
import { ChannelBrandIcon } from '@/Components/BrandIcons';
import EmailEditor from '@/Components/EmailEditor';
import TimezonePicker from '@/Components/TimezonePicker';
import { DatePicker } from '@/Components/ui';

const STEPS = [
    { key: 'channel', labelKey: 'campaign.step_channel' },
    { key: 'audience', labelKey: 'campaign.step_audience' },
    { key: 'content', labelKey: 'campaign.step_content' },
    { key: 'schedule', labelKey: 'campaign.step_schedule' },
    { key: 'review', labelKey: 'campaign.step_review' },
];

const CHANNEL_META = {
    whatsapp: { label: 'WhatsApp', Icon: (p) => <ChannelBrandIcon channel="whatsapp" {...p} /> },
    sms: { label: 'SMS', Icon: (p) => <ChannelBrandIcon channel="sms" {...p} /> },
    email: { label: 'Email', Icon: (p) => <ChannelBrandIcon channel="email" {...p} /> },
};

const inputClass =
    'mt-1 w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:border-brand-500 focus:ring-1 focus:ring-brand-500';

function FieldError({ message }) {
    if (!message) return null;
    return (
        <p className="mt-1 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
            <AlertCircle className="h-3.5 w-3.5" />
            {message}
        </p>
    );
}

function defaultInitialData(campaign, userTz) {
    const fallbackTz = userTz || browserTz() || 'Asia/Dhaka';
    if (campaign) {
        const tz = campaign.timezone || fallbackTz;
        return {
            name: campaign.name ?? '',
            channel: campaign.channel ?? 'whatsapp',
            whatsapp_phone_number_id: campaign.whatsapp_phone_number_id ?? '',
            audience_type: campaign.audience_type ?? 'segment',
            audience_ref: campaign.audience_ref ?? '',
            template_ref: {
                name: campaign.template_ref?.name ?? '',
                language: campaign.template_ref?.language ?? 'en',
                components: campaign.template_ref?.components ?? [],
            },
            payload_json: {
                subject: campaign.payload_json?.subject ?? '',
                body: campaign.payload_json?.body ?? '',
                from_email: campaign.payload_json?.from_email ?? '',
                from_name: campaign.payload_json?.from_name ?? '',
                reply_to: campaign.payload_json?.reply_to ?? '',
                track_opens: campaign.payload_json?.track_opens ?? true,
                track_clicks: campaign.payload_json?.track_clicks ?? false,
            },
            schedule_at: campaign.schedule_at ? utcToTzLocal(campaign.schedule_at, tz) : '',
            timezone: tz,
        };
    }

    return {
        name: '',
        channel: 'whatsapp',
        whatsapp_phone_number_id: '',
        audience_type: 'segment',
        audience_ref: '',
        template_ref: { name: '', language: 'en', components: [] },
        payload_json: { subject: '', body: '', from_email: '', from_name: '', reply_to: '', track_opens: true, track_clicks: false },
        schedule_at: '',
        timezone: fallbackTz,
    };
}

/**
 * Convert a Meta WhatsApp template's `components` (the canonical sample
 * synced from the Meta Graph API) into our editable per-parameter shape.
 *
 * Returns: [{ section: 'header'|'body'|'button', sub_type, button_index, slots: [{ kind, value, label }] }]
 *
 * For each `{{N}}` placeholder we infer in the template's `text`, we create
 * a "slot" the user can fill in. Header media is detected via `format`.
 */
function deriveSlotsFromTemplate(components = []) {
    const out = [];

    components.forEach((c) => {
        if (!c || typeof c !== 'object') return;
        const type = (c.type || '').toLowerCase();

        if (type === 'header') {
            const format = (c.format || '').toUpperCase();
            if (format === 'TEXT') {
                const slots = extractTextSlots(c.text || '');
                if (slots.length) {
                    out.push({ section: 'header', sub_type: 'text', slots });
                }
            } else if (['IMAGE', 'VIDEO', 'DOCUMENT'].includes(format)) {
                out.push({
                    section: 'header',
                    sub_type: format.toLowerCase(),
                    slots: [{ kind: 'static', value: '', label: `${format.toLowerCase()} URL`, mediaKind: format.toLowerCase() }],
                });
            }
        } else if (type === 'body') {
            const slots = extractTextSlots(c.text || '');
            if (slots.length) out.push({ section: 'body', sub_type: 'text', slots });
        } else if (type === 'buttons' && Array.isArray(c.buttons)) {
            c.buttons.forEach((btn, idx) => {
                if (!btn) return;
                const btnType = (btn.type || '').toLowerCase();
                if (btnType === 'url' && typeof btn.url === 'string' && btn.url.includes('{{')) {
                    out.push({
                        section: 'button',
                        sub_type: 'url',
                        button_index: idx,
                        slots: [{ kind: 'static', value: '', label: `Button ${idx + 1} URL parameter` }],
                    });
                }
                if (btnType === 'copy_code') {
                    out.push({
                        section: 'button',
                        sub_type: 'copy_code',
                        button_index: idx,
                        slots: [{ kind: 'static', value: '', label: `Button ${idx + 1} copy code` }],
                    });
                }
            });
        }
    });

    return out;
}

function extractTextSlots(text) {
    const matches = (text || '').match(/\{\{\s*\d+\s*\}\}/g) || [];
    return matches.map((m, i) => ({
        kind: 'static',
        value: '',
        label: `Variable ${m.replace(/\s+/g, '')} (slot ${i + 1})`,
    }));
}

/**
 * Convert our editable slots back into the Meta Cloud API `components` payload.
 */
function slotsToMetaComponents(slots) {
    return slots
        .filter((s) => s.slots && s.slots.length)
        .map((section) => {
            if (section.section === 'header' && section.sub_type === 'text') {
                return {
                    type: 'header',
                    parameters: section.slots.map((slot) => ({
                        type: 'text',
                        text: slot.kind === 'variable' ? slot.value : slot.value,
                    })),
                };
            }

            if (section.section === 'header' && ['image', 'video', 'document'].includes(section.sub_type)) {
                const slot = section.slots[0];
                const mediaKey = section.sub_type;
                const param = { type: mediaKey, [mediaKey]: { link: slot.value } };
                if (mediaKey === 'document' && slot.filename) {
                    param.document.filename = slot.filename;
                }
                return { type: 'header', parameters: [param] };
            }

            if (section.section === 'body') {
                return {
                    type: 'body',
                    parameters: section.slots.map((slot) => ({
                        type: 'text',
                        text: slot.value,
                    })),
                };
            }

            if (section.section === 'button') {
                return {
                    type: 'button',
                    sub_type: section.sub_type,
                    index: String(section.button_index),
                    parameters: section.slots.map((slot) => ({
                        type: section.sub_type === 'copy_code' ? 'coupon_code' : 'text',
                        text: slot.value,
                    })),
                };
            }

            return null;
        })
        .filter(Boolean);
}

function pickPreviewText(components) {
    if (!Array.isArray(components)) return '';
    const body = components.find((c) => c && (c.type || '').toLowerCase() === 'body');
    return body?.text || '';
}

function renderPreview(text, slots, contactTokens) {
    if (!text) return '';
    let i = 0;
    return text.replace(/\{\{\s*(\d+)\s*\}\}/g, () => {
        const flatSlots = slots.flatMap((s) => (s.section === 'body' ? s.slots : []));
        const slot = flatSlots[i++];
        if (!slot) return '___';
        if (!slot.value) return '___';
        // If user picked a token like {{contact.first_name}}, show the friendly label
        if (slot.value.startsWith('{{')) {
            const tok = contactTokens.find((t) => t.key === slot.value);
            return tok ? `[${tok.label}]` : slot.value;
        }
        return slot.value;
    });
}

export default function CampaignForm({
    campaign = null,
    mode = 'create',
    whatsappTemplates = [],
    whatsappPhoneNumbers = [],
    segments = [],
    tags = [],
    contactTokens = [],
}) {
    const { t } = useTranslation();
    const [step, setStep] = useState(0);
    const [draftUuid, setDraftUuid] = useState(campaign?.uuid ?? null);
    const [draftStatus, setDraftStatus] = useState(null); // null | 'saving' | 'saved' | 'error'
    const [audiencePreview, setAudiencePreview] = useState({
        loading: false,
        matched: 0,
        deliverable: 0,
        sample: [],
        error: null,
    });
    const [testTo, setTestTo] = useState({ phone_e164: '', email: '', sending: false, result: null });

    const userTz = usePage().props.timezone || browserTz() || 'Asia/Dhaka';
    const initialData = useMemo(() => defaultInitialData(campaign, userTz), [campaign?.id]);
    const { data, setData, post, patch, processing, errors, transform } = useForm(initialData);

    // Templates filtered to the selected phone number's WABA.
    const filteredTemplates = useMemo(() => {
        if (data.channel !== 'whatsapp' || !data.whatsapp_phone_number_id) return whatsappTemplates;
        const phone = whatsappPhoneNumbers.find((p) => p.phone_number_id === data.whatsapp_phone_number_id);
        if (!phone?.waba_id) return whatsappTemplates;
        return whatsappTemplates.filter((t) => t.waba_id === phone.waba_id);
    }, [whatsappTemplates, whatsappPhoneNumbers, data.channel, data.whatsapp_phone_number_id]);

    // The selected WhatsApp template (from the workspace) — used to derive parameter slots.
    const selectedTemplate = useMemo(() => {
        if (data.channel !== 'whatsapp' || !data.template_ref?.name) return null;
        return (
            filteredTemplates.find(
                (t) =>
                    t.name === data.template_ref.name &&
                    t.language === data.template_ref.language,
            ) ?? null
        );
    }, [filteredTemplates, data.channel, data.template_ref.name, data.template_ref.language]);

    // Derive parameter slots from the template's canonical components.
    // We keep the slot user-input separately and only marshal back into Meta shape on submit.
    const [slots, setSlots] = useState(() => deriveSlotsFromTemplate(selectedTemplate?.components ?? []));

    // Auto-select the only phone number when switching to WhatsApp with a single number.
    useEffect(() => {
        if (data.channel === 'whatsapp' && whatsappPhoneNumbers.length === 1 && !data.whatsapp_phone_number_id) {
            setData('whatsapp_phone_number_id', whatsappPhoneNumbers[0].phone_number_id);
        }
        if (data.channel !== 'whatsapp' && data.whatsapp_phone_number_id) {
            setData('whatsapp_phone_number_id', '');
        }
    }, [data.channel]); // eslint-disable-line react-hooks/exhaustive-deps

    // Reset template when the phone number changes (templates are WABA-scoped).
    const prevPhoneRef = useRef(data.whatsapp_phone_number_id);
    useEffect(() => {
        if (prevPhoneRef.current !== data.whatsapp_phone_number_id) {
            prevPhoneRef.current = data.whatsapp_phone_number_id;
            setData('template_ref', { name: '', language: 'en', components: [] });
        }
    }, [data.whatsapp_phone_number_id]); // eslint-disable-line react-hooks/exhaustive-deps

    // Whenever the user picks a different template, reset slots from its canonical components.
    useEffect(() => {
        if (data.channel === 'whatsapp' && selectedTemplate) {
            const next = deriveSlotsFromTemplate(selectedTemplate.components ?? []);
            setSlots((prev) => {
                // If the user has already filled values for the same template, preserve them.
                if (
                    prev.length === next.length &&
                    prev.every(
                        (p, i) =>
                            p.section === next[i].section &&
                            p.sub_type === next[i].sub_type &&
                            p.slots.length === next[i].slots.length,
                    )
                ) {
                    return prev;
                }
                return next;
            });
        } else if (data.channel !== 'whatsapp') {
            setSlots([]);
        }
    }, [selectedTemplate, data.channel]);

    // Persist slots into form data as Meta-shape components on every change.
    useEffect(() => {
        if (data.channel !== 'whatsapp') return;
        const components = slotsToMetaComponents(slots);
        // Avoid noisy re-renders if components haven't actually changed.
        const sameJson = JSON.stringify(data.template_ref.components) === JSON.stringify(components);
        if (!sameJson) {
            setData('template_ref', { ...data.template_ref, components });
        }
    }, [slots, data.channel]); // eslint-disable-line react-hooks/exhaustive-deps

    // ── Audience preview (debounced) ──────────────────────────────────────────
    const debounceRef = useRef(null);
    useEffect(() => {
        if (debounceRef.current) clearTimeout(debounceRef.current);
        if (data.audience_type === 'csv') {
            setAudiencePreview({ loading: false, matched: 0, deliverable: 0, sample: [], error: null });
            return;
        }
        debounceRef.current = setTimeout(() => {
            setAudiencePreview((p) => ({ ...p, loading: true, error: null }));
            axios
                .post(route('client.campaigns.audience-preview'), {
                    audience_type: data.audience_type,
                    audience_ref: data.audience_ref,
                    channel: data.channel,
                })
                .then((r) =>
                    setAudiencePreview({
                        loading: false,
                        matched: r.data.matched ?? 0,
                        deliverable: r.data.deliverable ?? 0,
                        sample: r.data.sample ?? [],
                        error: null,
                    }),
                )
                .catch((e) =>
                    setAudiencePreview({
                        loading: false,
                        matched: 0,
                        deliverable: 0,
                        sample: [],
                        error: e?.response?.data?.message ?? t('campaign.preview_error'),
                    }),
                );
        }, 350);
        return () => debounceRef.current && clearTimeout(debounceRef.current);
    }, [data.audience_type, data.audience_ref, data.channel]);

    // ── Step navigation ───────────────────────────────────────────────────────
    // Returns true on success, false on failure.
    const saveDraft = async () => {
        setDraftStatus('saving');
        try {
            const payload = {
                uuid: draftUuid,
                name: data.name,
                channel: data.channel,
                whatsapp_phone_number_id: data.whatsapp_phone_number_id || null,
                audience_type: data.audience_type,
                audience_ref: data.audience_ref || null,
                template_ref: data.template_ref,
                payload_json: data.payload_json,
                timezone: data.timezone,
                schedule_at: data.schedule_at
                    ? tzLocalToUtcIso(data.schedule_at, data.timezone || 'UTC')
                    : null,
            };
            const res = await axios.post(route('client.campaigns.store-draft'), payload);
            setDraftUuid(res.data.uuid);
            setDraftStatus('saved');
            setTimeout(() => setDraftStatus(null), 3000);
            return true;
        } catch {
            setDraftStatus('error');
            setTimeout(() => setDraftStatus(null), 4000);
            return false;
        }
    };

    const next = async () => {
        const saved = await saveDraft();
        if (saved) {
            setStep((s) => Math.min(s + 1, STEPS.length - 1));
        }
    };
    const prev = () => setStep((s) => Math.max(s - 1, 0));

    const isStepValid = useMemo(() => {
        if (step === 0) {
            if (!data.name.trim() || !data.channel) return false;
            // When WhatsApp is selected and there are multiple numbers, one must be chosen.
            if (data.channel === 'whatsapp' && whatsappPhoneNumbers.length > 1 && !data.whatsapp_phone_number_id) {
                return false;
            }
            return true;
        }
        if (step === 1) {
            if (data.audience_type === 'segment' || data.audience_type === 'tag') {
                return !!data.audience_ref;
            }
            return true;
        }
        if (step === 2) {
            if (data.channel === 'whatsapp') {
                return !!data.template_ref.name;
            }
            if (data.channel === 'sms') return (data.payload_json.body || '').trim().length > 0;
            if (data.channel === 'email') {
                return (
                    (data.payload_json.subject || '').trim().length > 0 &&
                    (data.payload_json.body || '').trim().length > 0
                );
            }
        }
        return true;
    }, [step, data]);

    const handleSubmit = (e) => {
        e.preventDefault();
        // The `<input type="datetime-local">` writes a wall-clock string that
        // has no timezone info. Convert it to a UTC ISO 8601 string in the
        // campaign's chosen timezone before sending — otherwise Laravel will
        // parse it as UTC and the campaign will fire at the wrong moment.
        transform((d) => ({
            ...d,
            schedule_at: d.schedule_at ? tzLocalToUtcIso(d.schedule_at, d.timezone || 'UTC') : null,
            audience_ref: d.audience_ref ? String(d.audience_ref) : null,
        }));

        if (draftUuid) {
            patch(route('client.campaigns.update', draftUuid));
        } else {
            post(route('client.campaigns.store'));
        }
    };

    // ── Test send ────────────────────────────────────────────────────────────
    const sendTest = () => {
        if (!campaign?.uuid) return;
        setTestTo((s) => ({ ...s, sending: true, result: null }));
        axios
            .post(route('client.campaigns.test-send', campaign.uuid), {
                phone_e164: testTo.phone_e164 || null,
                email: testTo.email || null,
            })
            .then((r) =>
                setTestTo((s) => ({
                    ...s,
                    sending: false,
                    result: { ok: true, message: t('campaign.test_sent', { id: r.data.message_id || 'OK' }) },
                })),
            )
            .catch((e) =>
                setTestTo((s) => ({
                    ...s,
                    sending: false,
                    result: { ok: false, message: e?.response?.data?.error ?? t('campaign.test_failed') },
                })),
            );
    };

    // ── Slot helpers ──────────────────────────────────────────────────────────
    const updateSlot = (sectionIdx, slotIdx, patchObj) => {
        setSlots((prev) =>
            prev.map((s, i) =>
                i === sectionIdx
                    ? {
                          ...s,
                          slots: s.slots.map((sl, j) => (j === slotIdx ? { ...sl, ...patchObj } : sl)),
                      }
                    : s,
            ),
        );
    };

    const insertTokenIntoTextarea = (field, token) => {
        const current = data.payload_json[field] || '';
        setData('payload_json', { ...data.payload_json, [field]: current + token });
    };

    return (
        <form onSubmit={handleSubmit}>
            <div className="grid grid-cols-1 gap-6 lg:grid-cols-[1fr_22rem]">
                {/* ── Main pane ───────────────────────────────────────────── */}
                <div className="space-y-4">
                    {/* Step indicator */}
                    <div className="flex flex-wrap items-center gap-2">
                        {STEPS.map((stepDef, i) => (
                            <div key={stepDef.key} className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={async () => { await saveDraft(); setStep(i); }}
                                    className={`h-7 w-7 rounded-full flex items-center justify-center text-xs font-semibold transition cursor-pointer hover:ring-2 hover:ring-offset-1 hover:ring-brand-400 ${
                                        i === step
                                            ? 'bg-brand-600 text-white ring-2 ring-brand-400 ring-offset-1'
                                            : i < step
                                              ? 'bg-brand-500 text-white'
                                              : 'bg-neutral-200 dark:bg-neutral-700 text-neutral-500 hover:bg-neutral-300 dark:hover:bg-neutral-600'
                                    }`}
                                >
                                    {i < step ? <CheckCircle2 className="h-4 w-4" /> : i + 1}
                                </button>
                                <button
                                    type="button"
                                    onClick={async () => { await saveDraft(); setStep(i); }}
                                    className={`text-xs transition cursor-pointer ${
                                        i === step
                                            ? 'text-neutral-900 dark:text-neutral-100 font-medium'
                                            : i < step
                                              ? 'text-brand-600 dark:text-brand-400 hover:underline'
                                              : 'text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-300'
                                    }`}
                                >
                                    {t(stepDef.labelKey)}
                                </button>
                                {i < STEPS.length - 1 && (
                                    <div className={`w-6 h-px ${i < step ? 'bg-brand-400' : 'bg-neutral-300 dark:bg-neutral-600'}`} />
                                )}
                            </div>
                        ))}
                    </div>

                    <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-6 space-y-4">
                        {step === 0 && (
                            <ChannelStep
                                data={data}
                                setData={setData}
                                errors={errors}
                                whatsappPhoneNumbers={whatsappPhoneNumbers}
                            />
                        )}

                        {step === 1 && (
                            <AudienceStep
                                data={data}
                                setData={setData}
                                segments={segments}
                                tags={tags}
                                preview={audiencePreview}
                                errors={errors}
                            />
                        )}

                        {step === 2 && (
                            <ContentStep
                                data={data}
                                setData={setData}
                                whatsappTemplates={filteredTemplates}
                                selectedTemplate={selectedTemplate}
                                slots={slots}
                                updateSlot={updateSlot}
                                contactTokens={contactTokens}
                                insertTokenIntoTextarea={insertTokenIntoTextarea}
                                errors={errors}
                                campaignName={data.name}
                            />
                        )}

                        {step === 3 && (
                            <ScheduleStep data={data} setData={setData} errors={errors} />
                        )}

                        {step === 4 && (
                            <ReviewStep
                                data={data}
                                preview={audiencePreview}
                                selectedTemplate={selectedTemplate}
                                slots={slots}
                                contactTokens={contactTokens}
                                campaign={campaign}
                                testTo={testTo}
                                setTestTo={setTestTo}
                                sendTest={sendTest}
                            />
                        )}
                    </div>

                    {/* Step nav */}
                    <div className="flex flex-wrap items-center gap-3">
                        {step > 0 && (
                            <button
                                type="button"
                                onClick={prev}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            >
                                <ArrowLeft className="h-4 w-4" /> {t('common.back')}
                            </button>
                        )}

                        {/* Draft save indicator */}
                        {draftStatus === 'saving' && (
                            <span className="flex items-center gap-1.5 text-xs text-neutral-400 dark:text-neutral-500">
                                <Loader2 className="h-3.5 w-3.5 animate-spin" /> {t('campaign.saving_draft')}
                            </span>
                        )}
                        {draftStatus === 'saved' && (
                            <span className="flex items-center gap-1.5 text-xs text-emerald-600 dark:text-emerald-400">
                                <Save className="h-3.5 w-3.5" /> {t('campaign.draft_saved')}
                            </span>
                        )}
                        {draftStatus === 'error' && (
                            <span className="flex items-center gap-1.5 text-xs text-red-500 dark:text-red-400">
                                <AlertCircle className="h-3.5 w-3.5" /> {t('campaign.draft_save_failed')}
                            </span>
                        )}

                        {step < STEPS.length - 1 ? (
                            <button
                                type="button"
                                onClick={next}
                                disabled={!isStepValid || draftStatus === 'saving'}
                                className="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50 transition"
                            >
                                {draftStatus === 'saving' ? (
                                    <><Loader2 className="h-4 w-4 animate-spin" /> {t('campaign.saving')}</>
                                ) : (
                                    <>{t('common.next')} <ArrowRight className="h-4 w-4" /></>
                                )}
                            </button>
                        ) : (
                            <button
                                type="submit"
                                disabled={processing}
                                className="ml-auto flex items-center gap-1.5 rounded-lg bg-brand-600 px-5 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                            >
                                <Send className="h-4 w-4" />
                                {processing
                                    ? t('campaign.saving')
                                    : mode === 'edit'
                                      ? t('campaign.save_changes')
                                      : t('campaign.create_campaign')}
                            </button>
                        )}
                    </div>
                </div>

                {/* ── Live preview pane ──────────────────────────────────── */}
                <PreviewPane
                    data={data}
                    selectedTemplate={selectedTemplate}
                    slots={slots}
                    contactTokens={contactTokens}
                    audiencePreview={audiencePreview}
                    whatsappPhoneNumbers={whatsappPhoneNumbers}
                />
            </div>
        </form>
    );
}

// ─── Step components ──────────────────────────────────────────────────────────

function ChannelStep({ data, setData, errors, whatsappPhoneNumbers = [] }) {
    const { t } = useTranslation();
    return (
        <>
            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('campaign.name_and_channel')}</h3>
            <div>
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('common.name')}</label>
                <input
                    type="text"
                    value={data.name}
                    onChange={(e) => setData('name', e.target.value)}
                    placeholder={t('campaign.name_placeholder')}
                    required
                    className={inputClass}
                />
                <FieldError message={errors.name} />
            </div>
            <div>
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300 block mb-2">
                    {t('campaign.channel')}
                </label>
                <div className="grid grid-cols-3 gap-3">
                    {Object.entries(CHANNEL_META).map(([val, meta]) => {
                        const Brand = meta.Icon;
                        const active = data.channel === val;
                        return (
                            <button
                                key={val}
                                type="button"
                                onClick={() => setData('channel', val)}
                                className={`rounded-xl border p-4 text-sm font-medium transition flex flex-col items-center gap-2 ${
                                    active
                                        ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/20 text-brand-700 dark:text-brand-300'
                                        : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-300'
                                }`}
                            >
                                <Brand className="h-6 w-6" />
                                {meta.label}
                            </button>
                        );
                    })}
                </div>
                <FieldError message={errors.channel} />
            </div>

            {data.channel === 'whatsapp' && whatsappPhoneNumbers.length > 0 && (
                <div>
                    <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300 block mb-2">
                        {t('campaign.send_from')}
                    </label>
                    {whatsappPhoneNumbers.length === 1 ? (
                        <div className="rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300">
                            {whatsappPhoneNumbers[0].display_phone}
                            {whatsappPhoneNumbers[0].verified_name && (
                                <span className="ml-2 text-neutral-500 dark:text-neutral-400">
                                    — {whatsappPhoneNumbers[0].verified_name}
                                </span>
                            )}
                        </div>
                    ) : (
                        <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            {whatsappPhoneNumbers.map((p) => {
                                const active = data.whatsapp_phone_number_id === p.phone_number_id;
                                return (
                                    <button
                                        key={p.phone_number_id}
                                        type="button"
                                        onClick={() => setData('whatsapp_phone_number_id', p.phone_number_id)}
                                        className={`rounded-xl border p-3 text-sm font-medium transition flex flex-col items-start gap-0.5 text-left ${
                                            active
                                                ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/20 text-brand-700 dark:text-brand-300'
                                                : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-300'
                                        }`}
                                    >
                                        <span>{p.display_phone}</span>
                                        {p.verified_name && (
                                            <span className="text-xs font-normal text-neutral-500 dark:text-neutral-400">
                                                {p.verified_name}
                                            </span>
                                        )}
                                    </button>
                                );
                            })}
                        </div>
                    )}
                    <FieldError message={errors.whatsapp_phone_number_id} />
                </div>
            )}

            {data.channel === 'email' && (
                <div className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 space-y-4">
                    <div className="text-sm font-semibold text-neutral-700 dark:text-neutral-200">{t('campaign.sender')}</div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <label className="text-xs font-medium text-neutral-600 dark:text-neutral-400">
                                {t('campaign.from_name')}
                            </label>
                            <input
                                type="text"
                                value={data.payload_json.from_name}
                                onChange={(e) => setData('payload_json', { ...data.payload_json, from_name: e.target.value })}
                                placeholder={t('campaign.from_name_placeholder')}
                                className={inputClass}
                            />
                        </div>
                        <div>
                            <label className="text-xs font-medium text-neutral-600 dark:text-neutral-400">
                                {t('campaign.from_email')}
                            </label>
                            <input
                                type="email"
                                value={data.payload_json.from_email}
                                onChange={(e) => setData('payload_json', { ...data.payload_json, from_email: e.target.value })}
                                placeholder={t('campaign.from_email_placeholder')}
                                className={inputClass}
                            />
                            <FieldError message={errors['payload_json.from_email']} />
                        </div>
                    </div>
                    <div>
                        <label className="text-xs font-medium text-neutral-600 dark:text-neutral-400">
                            {t('campaign.reply_to')} <span className="font-normal text-neutral-400">({t('common.optional')})</span>
                        </label>
                        <input
                            type="email"
                            value={data.payload_json.reply_to}
                            onChange={(e) => setData('payload_json', { ...data.payload_json, reply_to: e.target.value })}
                            placeholder="support@acme.com"
                            className={inputClass}
                        />
                        <FieldError message={errors['payload_json.reply_to']} />
                    </div>
                </div>
            )}

            {data.channel === 'email' && (
                <div className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 space-y-4">
                    <div className="text-sm font-semibold text-neutral-700 dark:text-neutral-200">{t('campaign.tracking')}</div>
                    <ToggleSwitch
                        checked={!!data.payload_json.track_opens}
                        onChange={(v) => setData('payload_json', { ...data.payload_json, track_opens: v })}
                        label={t('campaign.open_tracking')}
                        description={t('campaign.open_tracking_desc')}
                    />
                    <ToggleSwitch
                        checked={!!data.payload_json.track_clicks}
                        onChange={(v) => setData('payload_json', { ...data.payload_json, track_clicks: v })}
                        label={t('campaign.click_tracking')}
                        description={t('campaign.click_tracking_desc')}
                    />
                </div>
            )}
        </>
    );
}

function AudienceStep({ data, setData, segments, tags, preview, errors }) {
    const { t } = useTranslation();
    const channelLabel = CHANNEL_META[data.channel]?.label ?? data.channel;

    return (
        <>
            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('campaign.select_audience')}</h3>

            <div>
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300 block mb-2">
                    {t('campaign.audience_type')}
                </label>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    {[
                        ['segment', t('campaign.audience_segment')],
                        ['tag', t('campaign.audience_tag')],
                        ['contact_list', t('campaign.audience_all_contacts')],
                        ['csv', t('campaign.audience_csv_upload')],
                    ].map(([val, label]) => (
                        <button
                            key={val}
                            type="button"
                            onClick={() => {
                                setData('audience_type', val);
                                if (val === 'contact_list') setData('audience_ref', '');
                                if (val !== data.audience_type) setData('audience_ref', '');
                            }}
                            className={`rounded-lg border p-3 text-sm font-medium transition ${
                                data.audience_type === val
                                    ? 'border-brand-600 bg-brand-50 dark:bg-brand-900/20 text-brand-700 dark:text-brand-300'
                                    : 'border-neutral-200 dark:border-neutral-700 text-neutral-700 dark:text-neutral-300 hover:border-brand-300'
                            }`}
                        >
                            {label}
                        </button>
                    ))}
                </div>
                <FieldError message={errors.audience_type} />
            </div>

            {data.audience_type === 'segment' && (
                <SearchableSelect
                    label={t('campaign.audience_segment')}
                    items={segments.map((s) => ({
                        id: s.id,
                        label: s.name,
                        meta: `${s.type ?? ''}${s.contact_count ? ` · ${t('campaign.contact_count', { count: s.contact_count })}` : ''}`,
                    }))}
                    value={data.audience_ref}
                    onChange={(v) => setData('audience_ref', v)}
                    placeholder={t('campaign.pick_segment')}
                    emptyHint={t('campaign.no_segments_hint')}
                />
            )}

            {data.audience_type === 'tag' && (
                <SearchableSelect
                    label={t('campaign.audience_tag')}
                    items={tags.map((tag) => ({
                        id: tag.id,
                        label: tag.name,
                        meta: tag.color ?? '',
                    }))}
                    value={data.audience_ref}
                    onChange={(v) => setData('audience_ref', v)}
                    placeholder={t('campaign.pick_tag')}
                    emptyHint={t('campaign.no_tags_hint')}
                />
            )}

            {data.audience_type === 'csv' && (
                <div>
                    <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">
                        {t('campaign.csv_path')}
                    </label>
                    <input
                        type="text"
                        value={data.audience_ref}
                        onChange={(e) => setData('audience_ref', e.target.value)}
                        placeholder="campaigns/imports/abc.csv"
                        className={inputClass}
                    />
                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                        {t('campaign.csv_hint')}
                        <span className="font-mono"> phone_e164</span> {t('campaign.or')}
                        <span className="font-mono"> email</span>.
                    </p>
                </div>
            )}

            {/* Audience preview */}
            <div className="mt-4 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/40 p-3">
                <div className="flex items-center gap-2 text-sm font-medium text-neutral-700 dark:text-neutral-200">
                    <Users className="h-4 w-4" /> {t('campaign.audience_preview')}
                    {preview.loading && <Loader2 className="h-3.5 w-3.5 animate-spin text-neutral-400" />}
                </div>
                {preview.error ? (
                    <p className="mt-2 text-xs text-red-600 dark:text-red-400">{preview.error}</p>
                ) : data.audience_type === 'csv' ? (
                    <p className="mt-2 text-xs text-neutral-500">
                        {t('campaign.csv_no_preview')}
                    </p>
                ) : (
                    <>
                        <p className="mt-2 text-sm text-neutral-700 dark:text-neutral-200">
                            <span className="font-semibold">{preview.matched.toLocaleString()}</span> {t('campaign.contacts_match')}{' '}
                            <span className="text-neutral-500 dark:text-neutral-400">
                                <span className="font-semibold text-emerald-600 dark:text-emerald-400">
                                    {preview.deliverable.toLocaleString()}
                                </span>{' '}
                                {t('campaign.opted_in_for', { channel: channelLabel })}
                            </span>
                        </p>
                        {preview.sample.length > 0 && (
                            <div className="mt-2 text-xs text-neutral-500">
                                {t('campaign.sample')}: {preview.sample
                                    .map((c) => `${c.first_name ?? ''} ${c.last_name ?? ''}`.trim() || c.phone_e164 || c.email)
                                    .join(', ')}
                            </div>
                        )}
                    </>
                )}
            </div>
        </>
    );
}

function ContentStep({
    data,
    setData,
    whatsappTemplates,
    selectedTemplate,
    slots,
    updateSlot,
    contactTokens,
    insertTokenIntoTextarea,
    errors,
    campaignName,
}) {
    const { t } = useTranslation();
    return (
        <>
            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('campaign.message_content')}</h3>

            {data.channel === 'whatsapp' && (
                <>
                    <div>
                        <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">
                            {t('campaign.whatsapp_template')}
                        </label>
                        <select
                            value={
                                whatsappTemplates.find(
                                    (tpl) =>
                                        tpl.name === data.template_ref.name &&
                                        tpl.language === data.template_ref.language,
                                )?.id ?? ''
                            }
                            onChange={(e) => {
                                const v = e.target.value;
                                if (!v) {
                                    setData('template_ref', { name: '', language: 'en', components: [] });
                                    return;
                                }
                                const tpl = whatsappTemplates.find((x) => String(x.id) === v);
                                if (tpl) {
                                    setData('template_ref', {
                                        ...data.template_ref,
                                        name: tpl.name,
                                        language: tpl.language,
                                    });
                                }
                            }}
                            className={inputClass}
                        >
                            <option value="">{t('campaign.select_template')}</option>
                            {whatsappTemplates.map((tpl) => (
                                <option key={tpl.id} value={tpl.id}>
                                    {tpl.name} ({tpl.language}) — {tpl.status}
                                </option>
                            ))}
                        </select>
                        {whatsappTemplates.length === 0 && (
                            <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">
                                {t('campaign.no_templates_synced')}
                            </p>
                        )}
                        <FieldError message={errors['template_ref.name']} />
                    </div>

                    {selectedTemplate && slots.length > 0 && (
                        <div className="space-y-3">
                            <div className="flex items-center gap-2 text-sm font-medium text-neutral-800 dark:text-neutral-200">
                                <Variable className="h-4 w-4" /> {t('campaign.template_variables')}
                            </div>
                            {slots.map((section, sIdx) => (
                                <div
                                    key={`${section.section}-${section.sub_type}-${section.button_index ?? 'x'}`}
                                    className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-3 space-y-3"
                                >
                                    <div className="text-xs uppercase tracking-wider font-semibold text-neutral-500">
                                        {t(`campaign.section_${section.section}`, section.section)}
                                        {section.sub_type ? ` · ${section.sub_type}` : ''}
                                        {section.button_index != null ? ` · ${t('campaign.button_n', { n: section.button_index + 1 })}` : ''}
                                    </div>
                                    {section.slots.map((slot, slotIdx) => (
                                        <SlotInput
                                            key={slotIdx}
                                            slot={slot}
                                            label={slot.label}
                                            mediaKind={slot.mediaKind}
                                            contactTokens={contactTokens}
                                            onChange={(patch) => updateSlot(sIdx, slotIdx, patch)}
                                        />
                                    ))}
                                </div>
                            ))}
                        </div>
                    )}

                    {selectedTemplate && slots.length === 0 && (
                        <p className="text-sm text-neutral-500 dark:text-neutral-400">
                            {t('campaign.no_variables')}
                        </p>
                    )}
                </>
            )}

            {data.channel === 'sms' && (
                <BodyTextarea
                    label={t('campaign.sms_body')}
                    field="body"
                    value={data.payload_json.body}
                    onChange={(v) => setData('payload_json', { ...data.payload_json, body: v })}
                    placeholder={t('campaign.sms_body_placeholder')}
                    rows={4}
                    contactTokens={contactTokens}
                    onInsertToken={(token) => insertTokenIntoTextarea('body', token)}
                    error={errors['payload_json.body']}
                />
            )}

            {data.channel === 'email' && (
                <>
                    <EmailEditor
                        subject={data.payload_json.subject}
                        body={data.payload_json.body}
                        onSubjectChange={(v) => setData('payload_json', { ...data.payload_json, subject: v })}
                        onBodyChange={(v) => setData('payload_json', { ...data.payload_json, body: v })}
                        contactTokens={contactTokens}
                        campaignName={campaignName}
                    />
                    {errors['payload_json.subject'] && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                            <AlertCircle className="h-3.5 w-3.5" />
                            {errors['payload_json.subject']}
                        </p>
                    )}
                    {errors['payload_json.body'] && (
                        <p className="mt-1 flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                            <AlertCircle className="h-3.5 w-3.5" />
                            {errors['payload_json.body']}
                        </p>
                    )}

                </>
            )}
        </>
    );
}

function ScheduleStep({ data, setData, errors }) {
    const { t } = useTranslation();
    // Build a friendly preview that proves what UTC instant we'll persist.
    const tz = data.timezone || browserTz();
    const utcIso = data.schedule_at ? tzLocalToUtcIso(data.schedule_at, tz) : null;
    const localPreview = utcIso ? formatInTz(utcIso, tz) : null;
    const browserPreview =
        utcIso && tz !== browserTz() ? formatInTz(utcIso, browserTz()) : null;

    return (
        <>
            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('campaign.step_schedule')}</h3>
            <p className="text-sm text-neutral-500 dark:text-neutral-400">
                {t('campaign.schedule_help')}
            </p>

            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('campaign.send_at')}</label>
                    <DatePicker
                        mode="datetime"
                        value={data.schedule_at}
                        onChange={(v) => setData('schedule_at', v)}
                        className="mt-1"
                        error={!!errors.schedule_at}
                    />
                    <FieldError message={errors.schedule_at} />
                </div>
                <div>
                    <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('campaign.timezone')}</label>
                    <TimezonePicker
                        value={data.timezone}
                        onChange={tz => setData('timezone', tz)}
                        className="mt-1"
                    />
                    <FieldError message={errors.timezone} />
                </div>
            </div>

            {localPreview && (
                <div className="rounded-md border border-blue-200 bg-blue-50 p-3 text-xs text-blue-900 dark:border-blue-900 dark:bg-blue-950/40 dark:text-blue-200">
                    <div className="flex items-center gap-1.5 font-medium">
                        <CheckCircle2 className="h-3.5 w-3.5" />
                        {t('campaign.will_be_sent_at')}
                    </div>
                    <div className="mt-1">
                        <span className="font-mono">{localPreview}</span>
                    </div>
                    {browserPreview && (
                        <div className="mt-0.5 text-[11px] text-blue-700 dark:text-blue-300">
                            <Trans
                                i18nKey="campaign.which_is_browser"
                                values={{ time: browserPreview }}
                                components={{ time: <span className="font-mono" /> }}
                            />
                        </div>
                    )}
                </div>
            )}
        </>
    );
}

function ReviewStep({
    data,
    preview,
    selectedTemplate,
    slots,
    contactTokens,
    campaign,
    testTo,
    setTestTo,
    sendTest,
}) {
    const { t } = useTranslation();
    const channelLabel = CHANNEL_META[data.channel]?.label ?? data.channel;

    return (
        <>
            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('campaign.review_confirm')}</h3>
            <dl className="grid grid-cols-1 gap-2 text-sm sm:grid-cols-2">
                {[
                    [t('common.name'), data.name || '—'],
                    [t('campaign.col_channel'), channelLabel],
                    [t('campaign.audience'), `${data.audience_type}${data.audience_ref ? ` · ${data.audience_ref}` : ''}`],
                    [t('campaign.reachable_contacts'), preview.deliverable.toLocaleString()],
                    [
                        t('campaign.step_schedule'),
                        data.schedule_at
                            ? `${formatInTz(
                                  tzLocalToUtcIso(data.schedule_at, data.timezone),
                                  data.timezone,
                              )} (${data.timezone})`
                            : t('campaign.on_demand'),
                    ],
                ].map(([k, v]) => (
                    <div key={k} className="flex gap-3">
                        <dt className="w-32 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">{k}</dt>
                        <dd className="text-neutral-900 dark:text-neutral-100">{v}</dd>
                    </div>
                ))}
                {data.channel === 'whatsapp' && data.template_ref?.name && (
                    <div className="flex gap-3 sm:col-span-2">
                        <dt className="w-32 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">{t('campaign.template')}</dt>
                        <dd className="text-neutral-900 dark:text-neutral-100 font-mono">
                            {data.template_ref.name} ({data.template_ref.language})
                        </dd>
                    </div>
                )}
                {data.channel === 'email' && (data.payload_json.from_email || data.payload_json.from_name) && (
                    <div className="flex gap-3 sm:col-span-2">
                        <dt className="w-32 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">{t('campaign.from')}</dt>
                        <dd className="text-neutral-900 dark:text-neutral-100">
                            {data.payload_json.from_name && <span>{data.payload_json.from_name} </span>}
                            {data.payload_json.from_email && (
                                <span className="text-neutral-500 dark:text-neutral-400">&lt;{data.payload_json.from_email}&gt;</span>
                            )}
                        </dd>
                    </div>
                )}
                {data.channel === 'email' && data.payload_json.reply_to && (
                    <div className="flex gap-3 sm:col-span-2">
                        <dt className="w-32 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">{t('campaign.reply_to_short')}</dt>
                        <dd className="text-neutral-900 dark:text-neutral-100">{data.payload_json.reply_to}</dd>
                    </div>
                )}
                {data.channel === 'email' && (
                    <div className="flex gap-3 sm:col-span-2">
                        <dt className="w-32 shrink-0 font-medium text-neutral-500 dark:text-neutral-400">{t('campaign.tracking')}</dt>
                        <dd className="text-neutral-900 dark:text-neutral-100 space-x-2">
                            {data.payload_json.track_opens && (
                                <span className="inline-flex items-center rounded-full bg-emerald-100 dark:bg-emerald-900/30 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:text-emerald-300">
                                    {t('campaign.opens')}
                                </span>
                            )}
                            {data.payload_json.track_clicks && (
                                <span className="inline-flex items-center rounded-full bg-blue-100 dark:bg-blue-900/30 px-2 py-0.5 text-xs font-medium text-blue-700 dark:text-blue-300">
                                    {t('campaign.clicks')}
                                </span>
                            )}
                            {!data.payload_json.track_opens && !data.payload_json.track_clicks && (
                                <span className="text-neutral-400">{t('campaign.disabled')}</span>
                            )}
                        </dd>
                    </div>
                )}
            </dl>

            {/* Test send (edit-mode only — needs a saved campaign id) */}
            {campaign?.id && (
                <div className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 mt-4 space-y-3">
                    <div className="flex items-center gap-2 text-sm font-medium text-neutral-800 dark:text-neutral-200">
                        <Eye className="h-4 w-4" /> {t('campaign.send_a_test')}
                    </div>
                    <p className="text-xs text-neutral-500 dark:text-neutral-400">
                        {t('campaign.send_test_desc')}
                    </p>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
                        <div>
                            <input
                                type="text"
                                placeholder={t('campaign.phone_placeholder')}
                                value={testTo.phone_e164}
                                onChange={(e) => setTestTo((s) => ({ ...s, phone_e164: e.target.value }))}
                                className={inputClass}
                            />
                            {testTo.phone_e164 && !testTo.phone_e164.startsWith('+') && !/^01[3-9]\d{8}$/.test(testTo.phone_e164) && (
                                <p className="mt-1 text-xs text-amber-600 dark:text-amber-400">
                                    {t('campaign.intl_number_hint')}
                                </p>
                            )}
                        </div>
                        <input
                            type="email"
                            placeholder={t('common.email')}
                            value={testTo.email}
                            onChange={(e) => setTestTo((s) => ({ ...s, email: e.target.value }))}
                            className={inputClass}
                        />
                    </div>
                    <button
                        type="button"
                        disabled={testTo.sending || (!testTo.phone_e164 && !testTo.email)}
                        onClick={sendTest}
                        className="inline-flex items-center gap-2 rounded-lg bg-neutral-900 dark:bg-neutral-700 px-3 py-2 text-sm font-medium text-white hover:opacity-90 disabled:opacity-50"
                    >
                        {testTo.sending ? <Loader2 className="h-4 w-4 animate-spin" /> : <Send className="h-4 w-4" />}
                        {t('campaign.send_test')}
                    </button>
                    {testTo.result && (
                        <p
                            className={`text-xs ${
                                testTo.result.ok
                                    ? 'text-emerald-600 dark:text-emerald-400'
                                    : 'text-red-600 dark:text-red-400'
                            } flex items-center gap-1`}
                        >
                            {testTo.result.ok ? <CheckCircle2 className="h-3.5 w-3.5" /> : <AlertCircle className="h-3.5 w-3.5" />}
                            {testTo.result.message}
                        </p>
                    )}
                </div>
            )}
        </>
    );
}

// ─── Reusable bits ───────────────────────────────────────────────────────────

function SearchableSelect({ label, items, value, onChange, placeholder, emptyHint }) {
    const [q, setQ] = useState('');
    const [open, setOpen] = useState(false);

    const filtered = useMemo(
        () => items.filter((i) => i.label.toLowerCase().includes(q.toLowerCase())),
        [items, q],
    );

    const selected = items.find((i) => String(i.id) === String(value));

    return (
        <div className="relative">
            <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
            <div className="mt-1 relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                <input
                    type="text"
                    value={open ? q : selected?.label ?? ''}
                    onChange={(e) => {
                        setQ(e.target.value);
                        setOpen(true);
                    }}
                    onFocus={() => {
                        setOpen(true);
                        setQ('');
                    }}
                    onBlur={() => setTimeout(() => setOpen(false), 150)}
                    placeholder={placeholder}
                    className={`${inputClass} pl-9`}
                />
            </div>
            {open && (
                <div className="absolute z-20 mt-1 max-h-56 w-full overflow-auto rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-lg">
                    {filtered.length === 0 ? (
                        <p className="px-3 py-2 text-xs text-neutral-500">{emptyHint}</p>
                    ) : (
                        filtered.map((i) => (
                            <button
                                key={i.id}
                                type="button"
                                onMouseDown={(e) => {
                                    e.preventDefault();
                                    onChange(String(i.id));
                                    setOpen(false);
                                }}
                                className={`flex w-full items-center justify-between px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-700 ${
                                    String(i.id) === String(value)
                                        ? 'bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                        : ''
                                }`}
                            >
                                <span>{i.label}</span>
                                {i.meta && <span className="text-xs text-neutral-400">{i.meta}</span>}
                            </button>
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

function BodyTextarea({
    label,
    field,
    value,
    onChange,
    placeholder,
    rows = 4,
    mono = false,
    contactTokens,
    onInsertToken,
    error,
}) {
    return (
        <div>
            <div className="flex items-center justify-between gap-3">
                <label className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{label}</label>
                <TokenPicker tokens={contactTokens} onPick={onInsertToken} />
            </div>
            <textarea
                rows={rows}
                value={value}
                onChange={(e) => onChange(e.target.value)}
                placeholder={placeholder}
                className={`${inputClass} resize-none ${mono ? 'font-mono' : ''}`}
            />
            <FieldError message={error} />
        </div>
    );
}

function TokenPicker({ tokens, onPick }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    return (
        <div className="relative">
            <button
                type="button"
                onClick={() => setOpen((o) => !o)}
                className="inline-flex items-center gap-1 rounded-md border border-neutral-300 dark:border-neutral-600 px-2 py-1 text-xs font-medium text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800"
            >
                <Variable className="h-3 w-3" /> {t('campaign.insert_variable')}
            </button>
            {open && (
                <div
                    className="absolute right-0 z-20 mt-1 w-56 rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 shadow-lg"
                    onMouseLeave={() => setOpen(false)}
                >
                    {tokens.map((token) => (
                        <button
                            key={token.key}
                            type="button"
                            onClick={() => {
                                onPick(token.key);
                                setOpen(false);
                            }}
                            className="flex w-full items-center justify-between px-3 py-1.5 text-left text-xs hover:bg-neutral-50 dark:hover:bg-neutral-700"
                        >
                            <span>{token.label}</span>
                            <span className="font-mono text-neutral-400">{token.key}</span>
                        </button>
                    ))}
                </div>
            )}
        </div>
    );
}

function ToggleSwitch({ checked, onChange, label, description }) {
    return (
        <div className="flex items-start gap-3">
            <button
                type="button"
                role="switch"
                aria-checked={checked}
                onClick={() => onChange(!checked)}
                className={`relative mt-0.5 inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-brand-500 focus:ring-offset-2 ${
                    checked ? 'bg-brand-600' : 'bg-neutral-300 dark:bg-neutral-600'
                }`}
            >
                <span
                    className={`pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ${
                        checked ? 'translate-x-4' : 'translate-x-0'
                    }`}
                />
            </button>
            <div>
                <div className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{label}</div>
                {description && (
                    <div className="text-xs text-neutral-500 dark:text-neutral-400">{description}</div>
                )}
            </div>
        </div>
    );
}

function SlotInput({ slot, label, mediaKind, contactTokens, onChange }) {
    const { t } = useTranslation();
    // Header media (image / video / document) gets an Upload-or-Link picker.
    if (mediaKind) {
        return <MediaSlotInput slot={slot} label={label} mediaKind={mediaKind} onChange={onChange} />;
    }
    return (
        <div className="space-y-1.5">
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs text-neutral-600 dark:text-neutral-300">{label}</span>
                {!mediaKind && (
                    <div className="flex gap-1">
                        <button
                            type="button"
                            onClick={() => onChange({ kind: 'static' })}
                            className={`rounded-md px-2 py-0.5 text-xs ${
                                slot.kind === 'static'
                                    ? 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300'
                                    : 'text-neutral-500'
                            }`}
                        >
                            {t('campaign.slot_static')}
                        </button>
                        <button
                            type="button"
                            onClick={() => onChange({ kind: 'variable' })}
                            className={`rounded-md px-2 py-0.5 text-xs ${
                                slot.kind === 'variable'
                                    ? 'bg-brand-100 text-brand-700 dark:bg-brand-900/40 dark:text-brand-300'
                                    : 'text-neutral-500'
                            }`}
                        >
                            {t('campaign.slot_variable')}
                        </button>
                    </div>
                )}
            </div>
            {slot.kind === 'variable' ? (
                <select
                    value={slot.value}
                    onChange={(e) => onChange({ value: e.target.value })}
                    className={inputClass}
                >
                    <option value="">{t('campaign.select_contact_field')}</option>
                    {contactTokens.map((token) => (
                        <option key={token.key} value={token.key}>
                            {token.label}
                        </option>
                    ))}
                </select>
            ) : (
                <input
                    type="text"
                    value={slot.value}
                    onChange={(e) => onChange({ value: e.target.value })}
                    placeholder={mediaKind ? `https://example.com/file.${mediaKind === 'image' ? 'jpg' : mediaKind === 'video' ? 'mp4' : 'pdf'}` : t('campaign.enter_value')}
                    className={inputClass}
                />
            )}
        </div>
    );
}

/**
 * Header media slot with two tabs: Upload (stores the file and uses its public
 * URL) or Link (paste a URL). Either way the slot value ends up as a public URL
 * that Meta fetches when sending the template header.
 */
function MediaSlotInput({ slot, label, mediaKind, onChange }) {
    const { t } = useTranslation();
    const [tab, setTab] = useState('upload');
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState('');
    const fileRef = useRef(null);

    const accept = mediaKind === 'image' ? 'image/*' : mediaKind === 'video' ? 'video/*' : 'application/pdf';
    const ext = mediaKind === 'image' ? 'jpg' : mediaKind === 'video' ? 'mp4' : 'pdf';

    const handleFile = async (e) => {
        const file = e.target.files?.[0];
        if (!file) return;
        setUploading(true);
        setError('');
        try {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('collection', 'campaign-media');
            const res = await axios.post(route('client.media.store'), fd, {
                headers: { 'Content-Type': 'multipart/form-data' },
            });
            onChange({ value: res.data.url });
        } catch (err) {
            setError(err?.response?.data?.error ?? t('campaign.upload_failed'));
        } finally {
            setUploading(false);
            if (fileRef.current) fileRef.current.value = '';
        }
    };

    return (
        <div className="space-y-2">
            <span className="text-xs text-neutral-600 dark:text-neutral-300">{label}</span>

            <div className="flex w-fit gap-0.5 rounded-lg bg-neutral-100 dark:bg-neutral-800 p-0.5">
                {[
                    ['upload', t('campaign.tab_upload'), Upload],
                    ['link', t('campaign.tab_link'), LinkIcon],
                ].map(([key, text, Icon]) => (
                    <button
                        key={key}
                        type="button"
                        onClick={() => setTab(key)}
                        className={`flex items-center gap-1.5 rounded-md px-3 py-1 text-xs font-medium transition ${
                            tab === key
                                ? 'bg-white dark:bg-neutral-700 text-neutral-900 dark:text-neutral-100 shadow-sm'
                                : 'text-neutral-500 hover:text-neutral-700 dark:hover:text-neutral-300'
                        }`}
                    >
                        <Icon className="h-3.5 w-3.5" /> {text}
                    </button>
                ))}
            </div>

            {tab === 'upload' ? (
                <div className="space-y-1.5">
                    <input ref={fileRef} type="file" accept={accept} onChange={handleFile} className="hidden" />
                    <button
                        type="button"
                        onClick={() => fileRef.current?.click()}
                        disabled={uploading}
                        className="flex w-full items-center justify-center gap-2 rounded-lg border border-dashed border-neutral-300 dark:border-neutral-600 px-3 py-2.5 text-sm text-neutral-600 dark:text-neutral-300 hover:border-brand-400 disabled:opacity-50"
                    >
                        {uploading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Upload className="h-4 w-4" />}
                        {uploading ? t('campaign.uploading') : t('campaign.upload_media', { media: t(`campaign.media_${mediaKind}`, mediaKind) })}
                    </button>
                    {error && (
                        <p className="flex items-center gap-1 text-xs text-red-600 dark:text-red-400">
                            <AlertCircle className="h-3.5 w-3.5" /> {error}
                        </p>
                    )}
                </div>
            ) : (
                <input
                    type="text"
                    value={slot.value}
                    onChange={(e) => onChange({ value: e.target.value })}
                    placeholder={`https://example.com/file.${ext}`}
                    className={inputClass}
                />
            )}

            {slot.value && !uploading && (
                <div className="flex items-center gap-2 text-xs text-emerald-600 dark:text-emerald-400">
                    <CheckCircle2 className="h-3.5 w-3.5 shrink-0" />
                    <a href={slot.value} target="_blank" rel="noreferrer" className="truncate underline">
                        {mediaKind === 'image' ? t('campaign.image_set') : mediaKind === 'video' ? t('campaign.video_set') : t('campaign.file_set')}
                    </a>
                </div>
            )}

            {mediaKind === 'image' && slot.value && (
                <img
                    src={slot.value}
                    alt=""
                    className="mt-1 max-h-28 rounded-lg border border-neutral-200 dark:border-neutral-700 object-contain"
                    onError={(e) => { e.currentTarget.style.display = 'none'; }}
                />
            )}
        </div>
    );
}

function PreviewPane({ data, selectedTemplate, slots, contactTokens, audiencePreview, whatsappPhoneNumbers = [] }) {
    const { t } = useTranslation();
    let content = null;

    if (data.channel === 'whatsapp') {
        const templateBody = pickPreviewText(selectedTemplate?.components ?? []);
        const rendered = renderPreview(templateBody, slots, contactTokens);
        content = (
            <div className="rounded-2xl rounded-tl-none bg-emerald-50 dark:bg-emerald-900/20 px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100 shadow-sm whitespace-pre-line">
                {rendered || '—'}
            </div>
        );
    } else if (data.channel === 'sms') {
        content = (
            <div className="rounded-2xl rounded-tl-none bg-blue-50 dark:bg-blue-900/20 px-3 py-2 text-sm text-neutral-800 dark:text-neutral-100 whitespace-pre-line">
                {data.payload_json.body || '—'}
            </div>
        );
    } else if (data.channel === 'email') {
        content = (
            <div className="space-y-2">
                <div className="text-xs uppercase tracking-wide text-neutral-500">{t('campaign.subject')}</div>
                <div className="text-sm font-medium text-neutral-900 dark:text-neutral-100">
                    {data.payload_json.subject || '—'}
                </div>
                <div
                    className="prose prose-sm max-w-none dark:prose-invert text-sm text-neutral-800 dark:text-neutral-200"
                    dangerouslySetInnerHTML={{ __html: data.payload_json.body || '—' }}
                />
            </div>
        );
    }

    return (
        <aside className="space-y-4">
            <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
                <div className="flex items-center gap-2 text-sm font-medium text-neutral-800 dark:text-neutral-200">
                    <Eye className="h-4 w-4" /> {t('campaign.live_preview')}
                </div>
                <div className="mt-3">{content}</div>
                <p className="mt-3 text-xs text-neutral-500">
                    <Trans
                        i18nKey="campaign.variables_hint"
                        components={{ field: <span className="font-mono" /> }}
                    />
                </p>
            </div>

            <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4 space-y-1 text-xs">
                <div className="font-medium text-neutral-700 dark:text-neutral-200">{t('campaign.at_a_glance')}</div>
                <div className="flex justify-between">
                    <span className="text-neutral-500">{t('campaign.col_channel')}</span>
                    <span className="font-medium text-neutral-800 dark:text-neutral-100">
                        {CHANNEL_META[data.channel]?.label ?? data.channel}
                    </span>
                </div>
                {data.channel === 'whatsapp' && data.whatsapp_phone_number_id && (() => {
                    const p = whatsappPhoneNumbers.find((n) => n.phone_number_id === data.whatsapp_phone_number_id);
                    return p ? (
                        <div className="flex justify-between gap-3">
                            <span className="text-neutral-500">{t('campaign.from')}</span>
                            <span className="text-right font-medium text-neutral-800 dark:text-neutral-100">
                                {p.display_phone}
                            </span>
                        </div>
                    ) : null;
                })()}
                {data.channel === 'email' && data.payload_json?.from_email && (
                    <div className="flex justify-between gap-3">
                        <span className="text-neutral-500">{t('campaign.from')}</span>
                        <span className="text-right font-medium text-neutral-800 dark:text-neutral-100 truncate max-w-[10rem]">
                            {data.payload_json.from_name
                                ? `${data.payload_json.from_name} <${data.payload_json.from_email}>`
                                : data.payload_json.from_email}
                        </span>
                    </div>
                )}
                <div className="flex justify-between">
                    <span className="text-neutral-500">{t('campaign.audience')}</span>
                    <span className="font-medium text-neutral-800 dark:text-neutral-100">
                        {data.audience_type}
                    </span>
                </div>
                <div className="flex justify-between">
                    <span className="text-neutral-500">{t('campaign.reachable')}</span>
                    <span className="font-medium text-emerald-600 dark:text-emerald-400">
                        {audiencePreview.deliverable.toLocaleString()}
                    </span>
                </div>
                {data.schedule_at && (
                    <div className="flex justify-between gap-3">
                        <span className="text-neutral-500">{t('campaign.step_schedule')}</span>
                        <span className="text-right font-medium text-neutral-800 dark:text-neutral-100">
                            {formatInTz(
                                tzLocalToUtcIso(data.schedule_at, data.timezone),
                                data.timezone,
                            )}
                        </span>
                    </div>
                )}
            </div>
        </aside>
    );
}
