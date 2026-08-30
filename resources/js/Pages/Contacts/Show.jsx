import { Head, useForm, router } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { ArrowLeft, MessageSquare, Phone, Mail, Globe, Camera, Trash2, Upload } from 'lucide-react';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

function OptInBadge({ label, active }) {
    return (
        <span className={`rounded-full px-2 py-0.5 text-xs font-medium ${active ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-800 dark:text-neutral-400'}`}>
            {active ? '✓' : '✗'} {label}
        </span>
    );
}

function AvatarUploader({ contact }) {
    const { t } = useTranslation();
    const fileInput = useRef();
    const [preview, setPreview] = useState(contact.avatar_url ?? null);
    const [uploading, setUploading] = useState(false);
    const [dragOver, setDragOver] = useState(false);

    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();
    const initials = name
        ? name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase()
        : '?';

    const uploadFile = (file) => {
        if (!file || !file.type.startsWith('image/')) return;
        setPreview(URL.createObjectURL(file));
        setUploading(true);

        const formData = new FormData();
        formData.append('avatar', file);
        router.post(route('client.contacts.avatar.upload', contact.uuid), formData, {
            forceFormData: true,
            preserveScroll: true,
            onFinish: () => setUploading(false),
        });
    };

    const handleFileChange = (e) => {
        const file = e.target.files[0];
        if (file) uploadFile(file);
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files[0];
        if (file) uploadFile(file);
    };

    const handleDelete = () => {
        if (!confirm(t('contacts_page.avatar_confirm_remove'))) return;
        setPreview(null);
        router.delete(route('client.contacts.avatar.delete', contact.uuid), { preserveScroll: true });
    };

    return (
        <div className="flex flex-col items-center gap-3">
            {/* Avatar circle */}
            <div
                className={`relative group cursor-pointer rounded-full transition ${dragOver ? 'ring-4 ring-brand-400' : ''}`}
                onClick={() => fileInput.current?.click()}
                onDragOver={(e) => { e.preventDefault(); setDragOver(true); }}
                onDragLeave={() => setDragOver(false)}
                onDrop={handleDrop}
            >
                {preview ? (
                    <img
                        src={preview}
                        alt={name || t('contacts_page.contact_alt')}
                        className="h-24 w-24 rounded-full object-cover border-2 border-neutral-200 dark:border-neutral-700"
                    />
                ) : (
                    <div className="h-24 w-24 rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 flex items-center justify-center text-2xl font-bold border-2 border-neutral-200 dark:border-neutral-700">
                        {initials}
                    </div>
                )}
                {/* Overlay on hover */}
                <div className="absolute inset-0 rounded-full bg-black/40 opacity-0 group-hover:opacity-100 flex items-center justify-center transition">
                    <Camera className="h-6 w-6 text-white" />
                </div>
                {uploading && (
                    <div className="absolute inset-0 rounded-full bg-black/50 flex items-center justify-center">
                        <div className="h-5 w-5 border-2 border-white border-t-transparent rounded-full animate-spin" />
                    </div>
                )}
            </div>

            <input
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/webp,image/gif"
                className="hidden"
                onChange={handleFileChange}
            />

            {/* Action buttons */}
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={() => fileInput.current?.click()}
                    className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-xs text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                >
                    <Upload className="h-3.5 w-3.5" /> {t('contacts_page.avatar_upload')}
                </button>
                {preview && (
                    <button
                        type="button"
                        onClick={handleDelete}
                        className="flex items-center gap-1.5 rounded-lg border border-red-200 dark:border-red-800 px-3 py-1.5 text-xs text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                    >
                        <Trash2 className="h-3.5 w-3.5" /> {t('common.remove')}
                    </button>
                )}
            </div>
            <p className="text-xs text-neutral-400">{t('contacts_page.avatar_hint')}</p>
            {contact.avatar_url && contact.avatar_url.startsWith('http') && !contact.avatar_url.includes('/storage/') && (
                <p className="text-xs text-blue-500 dark:text-blue-400">{t('contacts_page.avatar_synced')}</p>
            )}
        </div>
    );
}

export default function ContactShow({ contact, staticSegments = [] }) {
    const { t } = useTranslation();
    const { data, setData, put, processing } = useForm({
        first_name: contact.first_name ?? '',
        last_name: contact.last_name ?? '',
        email: contact.email ?? '',
        country: contact.country ?? '',
        language: contact.language ?? '',
        opt_in_whatsapp: contact.opt_in_whatsapp,
        opt_in_sms: contact.opt_in_sms,
        opt_in_email: contact.opt_in_email,
        segment_ids: (contact.segments ?? []).filter(s => s.type === 'static').map(s => s.id),
    });

    const handleSave = (e) => {
        e.preventDefault();
        put(route('client.contacts.update', contact.uuid), { preserveScroll: true });
    };

    return (
        <ClientLayout title={t('contacts_page.contact_alt')}>
            <Head title={`${contact.first_name ?? ''} ${contact.last_name ?? ''} · ${t('contacts_page.contact_alt')}`} />
            <div className="max-w-3xl space-y-6">
                <div className="flex items-center gap-3">
                    <a href={route('client.contacts.index')} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <ArrowLeft className="h-5 w-5" />
                    </a>
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                        {contact.first_name || contact.last_name ? `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim() : t('contacts_page.unknown_contact')}
                    </h2>
                </div>

                {/* Avatar + Contact info */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5">
                    <div className="flex flex-col sm:flex-row gap-6 items-start">
                        {/* Avatar uploader */}
                        <AvatarUploader contact={contact} />

                        {/* Contact details */}
                        <div className="flex-1 space-y-3">
                            <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('contacts_page.contact_details')}</h3>
                            {contact.phone_e164 && <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400"><Phone className="h-4 w-4" />{contact.phone_e164}</div>}
                            {contact.email      && <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400"><Mail className="h-4 w-4" />{contact.email}</div>}
                            {contact.country    && <div className="flex items-center gap-2 text-sm text-neutral-600 dark:text-neutral-400"><Globe className="h-4 w-4" />{contact.country}</div>}
                            <div className="flex flex-wrap gap-2 pt-2">
                                <OptInBadge label="WhatsApp" active={contact.opt_in_whatsapp} />
                                <OptInBadge label={t('contacts_page.channel_sms')} active={contact.opt_in_sms} />
                                <OptInBadge label={t('common.email')}    active={contact.opt_in_email} />
                            </div>
                        </div>
                    </div>
                </div>

                {/* Edit form */}
                <form onSubmit={handleSave} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-3">
                    <h3 className="font-medium text-neutral-800 dark:text-neutral-200">{t('common.edit')}</h3>
                    {[['first_name', t('contacts_page.first_name')], ['last_name', t('contacts_page.last_name')], ['email', t('common.email')], ['country', t('contacts_page.country_label')], ['language', t('contacts_page.language_label')]].map(([k, l]) => (
                        <div key={k}>
                            <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{l}</label>
                            <input type="text" value={data[k]} onChange={e => setData(k, e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                        </div>
                    ))}
                    <div className="flex gap-3 flex-wrap">
                        {[['opt_in_whatsapp', t('contacts_page.channel_wa')], ['opt_in_sms', t('contacts_page.channel_sms')], ['opt_in_email', t('common.email')]].map(([key, label]) => (
                            <label key={key} className="flex items-center gap-1.5 text-sm cursor-pointer">
                                <input type="checkbox" checked={data[key]} onChange={e => setData(key, e.target.checked)} className="rounded" />
                                {label}
                            </label>
                        ))}
                    </div>
                    {staticSegments.length > 0 && (
                        <div>
                            <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.segments')}</label>
                            <div className="mt-1.5 flex flex-wrap gap-2">
                                {staticSegments.map(seg => {
                                    const checked = data.segment_ids.includes(seg.id);
                                    return (
                                        <label key={seg.id} className={`flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs cursor-pointer transition ${checked ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300' : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}>
                                            <input type="checkbox" className="sr-only" checked={checked} onChange={() => {
                                                const ids = checked ? data.segment_ids.filter(id => id !== seg.id) : [...data.segment_ids, seg.id];
                                                setData('segment_ids', ids);
                                            }} />
                                            {seg.name}
                                        </label>
                                    );
                                })}
                            </div>
                        </div>
                    )}
                    <button type="submit" disabled={processing} className="w-full rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                        {processing ? t('common.saving') : t('contacts_page.save_changes')}
                    </button>
                </form>

                {/* Conversation timeline */}
                {contact.conversations?.length > 0 && (
                    <div>
                        <h3 className="mb-3 font-medium text-neutral-800 dark:text-neutral-200 flex items-center gap-2">
                            <MessageSquare className="h-4 w-4" /> {t('contacts_page.recent_conversations')}
                        </h3>
                        <div className="space-y-3">
                            {contact.conversations.map(conv => (
                                <div key={conv.id} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-4">
                                    <div className="flex items-center justify-between text-xs text-neutral-400 mb-2">
                                        <span className="capitalize font-medium text-neutral-600 dark:text-neutral-300">{conv.channel_account?.channel ?? t('contacts_page.unknown_channel')}</span>
                                        <span className={`rounded-full px-2 py-0.5 font-medium ${conv.status === 'open' ? 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300' : 'bg-neutral-100 text-neutral-500 dark:bg-neutral-700 dark:text-neutral-400'}`}>{conv.status}</span>
                                    </div>
                                    {conv.messages?.map(msg => (
                                        <div key={msg.id} className={`text-sm p-2 rounded-lg mb-1 max-w-xs ${msg.direction === 'in' ? 'bg-neutral-100 dark:bg-neutral-800 text-neutral-800 dark:text-neutral-200' : 'ml-auto bg-brand-600 text-white'}`}>
                                            {msg.body || t('contacts_page.media_placeholder')}
                                        </div>
                                    ))}
                                </div>
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </ClientLayout>
    );
}
