import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import ImportCsvModal from './ImportCsvModal';
import { useState, useCallback } from 'react';
import { UserPlus, Upload, Search, Tag, Trash2, Eye, Users, Table2, Download, CheckSquare, Square, X, Layers, Plus, Minus } from 'lucide-react';
import { useTranslation } from 'react-i18next';

function ContactAvatar({ contact, size = 8 }) {
    const { t } = useTranslation();
    const name = `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim();
    const initials = name
        ? name.split(' ').map(p => p[0]).slice(0, 2).join('').toUpperCase()
        : '?';

    if (contact.avatar_url) {
        return (
            <img
                src={contact.avatar_url}
                alt={name || t('contacts_page.contact_alt')}
                className={`h-${size} w-${size} rounded-full object-cover flex-shrink-0`}
                onError={e => { e.target.style.display = 'none'; e.target.nextSibling.style.display = 'flex'; }}
            />
        );
    }
    return (
        <div className={`h-${size} w-${size} rounded-full bg-brand-100 dark:bg-brand-900/40 text-brand-700 dark:text-brand-300 flex items-center justify-center text-xs font-semibold flex-shrink-0`}>
            {initials}
        </div>
    );
}

function ContactRow({ contact, selected, onToggle, onDelete }) {
    const { t } = useTranslation();
    return (
        <tr className={`hover:bg-neutral-50 dark:hover:bg-neutral-800/50 ${selected ? 'bg-brand-50 dark:bg-brand-900/10' : ''}`}>
            <td className="px-4 py-3">
                <button type="button" onClick={() => onToggle(contact.uuid)} className="text-neutral-400 hover:text-brand-600 transition">
                    {selected
                        ? <CheckSquare className="h-4 w-4 text-brand-600" />
                        : <Square className="h-4 w-4" />
                    }
                </button>
            </td>
            <td className="px-4 py-3 text-sm text-neutral-900 dark:text-neutral-100">
                <div className="flex items-center gap-2.5">
                    <ContactAvatar contact={contact} size={8} />
                    <span>
                        {contact.first_name || contact.last_name
                            ? `${contact.first_name ?? ''} ${contact.last_name ?? ''}`.trim()
                            : <span className="text-neutral-400">—</span>
                        }
                    </span>
                </div>
            </td>
            <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">{contact.phone_e164 || '—'}</td>
            <td className="px-4 py-3 text-sm text-neutral-600 dark:text-neutral-400">{contact.email || '—'}</td>
            <td className="px-4 py-3">
                <div className="flex flex-wrap gap-1">
                    {contact.tags?.map(tag => (
                        <span key={tag.id} className="rounded-full px-2 py-0.5 text-xs font-medium" style={{ backgroundColor: tag.color + '33', color: tag.color }}>
                            {tag.name}
                        </span>
                    ))}
                </div>
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center gap-1">
                    {contact.opt_in_whatsapp && <span className="text-xs bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300 rounded px-1">{t('contacts_page.channel_wa')}</span>}
                    {contact.opt_in_sms      && <span className="text-xs bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300 rounded px-1">{t('contacts_page.channel_sms')}</span>}
                    {contact.opt_in_email    && <span className="text-xs bg-purple-100 text-purple-700 dark:bg-purple-900/40 dark:text-purple-300 rounded px-1">{t('contacts_page.channel_email')}</span>}
                </div>
            </td>
            <td className="px-4 py-3">
                <div className="flex items-center gap-2">
                    <Link href={route('client.contacts.show', contact.uuid)} className="text-neutral-400 hover:text-brand-600 transition">
                        <Eye className="h-4 w-4" />
                    </Link>
                    <button type="button" onClick={() => onDelete(contact.uuid)} className="text-neutral-400 hover:text-red-500 transition">
                        <Trash2 className="h-4 w-4" />
                    </button>
                </div>
            </td>
        </tr>
    );
}

function BulkManageModal({ mode, count, tags, segments, processing, onClose, onApply }) {
    const { t } = useTranslation();
    const [action, setAction] = useState('add');
    const [tagNames, setTagNames] = useState(new Set());
    const [segmentIds, setSegmentIds] = useState(new Set());
    const [newTag, setNewTag] = useState('');

    const isTags = mode === 'tags';
    const nothingPicked = isTags ? tagNames.size === 0 : segmentIds.size === 0;

    const toggle = (setter) => (value) => setter(prev => {
        const next = new Set(prev);
        next.has(value) ? next.delete(value) : next.add(value);
        return next;
    });
    const toggleTag = toggle(setTagNames);
    const toggleSegment = toggle(setSegmentIds);

    const addNewTag = () => {
        const name = newTag.trim().slice(0, 64);
        if (!name) return;
        setTagNames(prev => new Set([...prev, name]));
        setNewTag('');
    };

    // Show workspace tags plus any freshly typed names not saved yet.
    const tagOptions = [...new Set([...tags.map(tg => tg.name), ...tagNames])];

    const submit = () => onApply({
        action,
        ...(isTags ? { tag_names: [...tagNames] } : { segment_ids: [...segmentIds] }),
    });

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={e => { if (e.target === e.currentTarget) onClose(); }}>
            <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                <div className="flex items-start justify-between">
                    <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                        {isTags ? <Tag className="h-5 w-5 text-brand-600" /> : <Layers className="h-5 w-5 text-brand-600" />}
                        {isTags ? t('contacts_page.bulk_tags_title', { count }) : t('contacts_page.bulk_segments_title', { count })}
                    </h3>
                    <button type="button" onClick={onClose} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                        <X className="h-5 w-5" />
                    </button>
                </div>

                {/* Add / Remove toggle */}
                <div className="grid grid-cols-2 gap-1 rounded-lg bg-neutral-100 dark:bg-neutral-800 p-1">
                    {[
                        ['add', t('contacts_page.bulk_action_add'), Plus],
                        ['remove', t('contacts_page.bulk_action_remove'), Minus],
                    ].map(([value, label, Icon]) => (
                        <button
                            key={value}
                            type="button"
                            onClick={() => setAction(value)}
                            className={`flex items-center justify-center gap-1.5 rounded-md px-3 py-1.5 text-sm font-medium transition
                                ${action === value
                                    ? 'bg-white dark:bg-neutral-900 text-brand-700 dark:text-brand-300 shadow-sm'
                                    : 'text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200'}`}
                        >
                            <Icon className="h-3.5 w-3.5" /> {label}
                        </button>
                    ))}
                </div>

                {isTags ? (
                    <div className="space-y-3">
                        {action === 'add' && (
                            <div className="flex gap-2">
                                <input
                                    type="text"
                                    value={newTag}
                                    onChange={e => setNewTag(e.target.value)}
                                    onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addNewTag(); } }}
                                    placeholder={t('contacts_page.bulk_new_tag_placeholder')}
                                    className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                />
                                <button type="button" onClick={addNewTag} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('contacts_page.bulk_action_add')}
                                </button>
                            </div>
                        )}
                        <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                            {tagOptions.length === 0 && (
                                <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_page.bulk_no_tags')}</p>
                            )}
                            {tagOptions.map(name => {
                                const checked = tagNames.has(name);
                                return (
                                    <button
                                        key={name}
                                        type="button"
                                        onClick={() => toggleTag(name)}
                                        className={`rounded-full border px-3 py-1 text-xs transition ${checked
                                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                            : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}
                                    >
                                        {name}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-wrap gap-2 max-h-40 overflow-y-auto">
                        {segments.length === 0 && (
                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_page.bulk_no_segments')}</p>
                        )}
                        {segments.map(seg => {
                            const checked = segmentIds.has(seg.id);
                            return (
                                <button
                                    key={seg.id}
                                    type="button"
                                    onClick={() => toggleSegment(seg.id)}
                                    className={`rounded-full border px-3 py-1 text-xs transition ${checked
                                        ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                                        : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}
                                >
                                    {seg.name}
                                </button>
                            );
                        })}
                    </div>
                )}

                <div className="flex gap-2 pt-1">
                    <button
                        type="button"
                        onClick={submit}
                        disabled={nothingPicked || processing}
                        className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                    >
                        {processing ? t('common.saving') : t('contacts_page.bulk_apply', { count })}
                    </button>
                    <button type="button" onClick={onClose} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                        {t('common.cancel')}
                    </button>
                </div>
            </div>
        </div>
    );
}

export default function ContactsIndex({ contacts, filters, tags = [], segments = [] }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [search, setSearch] = useState(filters.search ?? '');
    const [showAddModal, setShowAddModal] = useState(false);
    const [showImportModal, setShowImportModal] = useState(false);
    const [bulkModal, setBulkModal] = useState(null); // null | 'tags' | 'segments'
    const [bulkProcessing, setBulkProcessing] = useState(false);
    const [selected, setSelected] = useState(new Set());

    const { data, setData, post, processing, reset } = useForm({
        first_name: '', last_name: '', phone_e164: '', email: '',
        opt_in_whatsapp: true, opt_in_sms: true, opt_in_email: true,
        segment_ids: [],
    });

    const allUuids = contacts.data.map(c => c.uuid);
    const allSelected = allUuids.length > 0 && allUuids.every(id => selected.has(id));
    const someSelected = selected.size > 0;

    const toggleAll = useCallback(() => {
        if (allSelected) {
            setSelected(prev => { const next = new Set(prev); allUuids.forEach(id => next.delete(id)); return next; });
        } else {
            setSelected(prev => new Set([...prev, ...allUuids]));
        }
    }, [allSelected, allUuids]);

    const toggleOne = useCallback((uuid) => {
        setSelected(prev => { const next = new Set(prev); next.has(uuid) ? next.delete(uuid) : next.add(uuid); return next; });
    }, []);

    const clearSelection = () => setSelected(new Set());

    const handleSearch = (e) => {
        e.preventDefault();
        router.get(route('client.contacts.index'), { search }, { preserveState: true, replace: true });
    };

    const handleDelete = (uuid) => {
        if (confirm(t('contacts_page.confirm_delete_one'))) {
            router.delete(route('client.contacts.destroy', uuid), { preserveScroll: true });
        }
    };

    const handleBulkDelete = () => {
        if (!confirm(t('contacts_page.confirm_delete_selected', { count: selected.size }))) return;
        router.delete(route('client.contacts.bulk-destroy'), {
            data: { uuids: [...selected] },
            preserveScroll: true,
            onSuccess: () => clearSelection(),
        });
    };

    const handleExport = (selectedOnly = false) => {
        const params = new URLSearchParams();
        if (selectedOnly && someSelected) {
            params.set('uuids', [...selected].join(','));
        } else if (filters.search) {
            params.set('search', filters.search);
        }
        window.location.href = route('client.contacts.export') + (params.toString() ? '?' + params.toString() : '');
    };

    const applyBulk = (payload) => {
        const routeName = bulkModal === 'tags' ? 'client.contacts.bulk-tags' : 'client.contacts.bulk-segments';
        setBulkProcessing(true);
        router.post(route(routeName), { uuids: [...selected], ...payload }, {
            preserveScroll: true,
            onSuccess: () => { setBulkModal(null); clearSelection(); },
            onFinish: () => setBulkProcessing(false),
        });
    };

    const handlePhoneChange = (value) => {
        setData(prev => ({
            ...prev,
            phone_e164: value,
            opt_in_whatsapp: value.trim() ? prev.opt_in_whatsapp : false,
            opt_in_sms: value.trim() ? prev.opt_in_sms : false,
        }));
    };

    const handleEmailChange = (value) => {
        setData(prev => ({
            ...prev,
            email: value,
            opt_in_email: value.trim() ? prev.opt_in_email : false,
        }));
    };

    const submitAdd = (e) => {
        e.preventDefault();
        if (!data.phone_e164.trim() && !data.email.trim()) {
            alert(t('contacts_page.alert_phone_or_email'));
            return;
        }
        post(route('client.contacts.store'), { onSuccess: () => { reset(); setShowAddModal(false); } });
    };

    return (
        <ClientLayout title={t('contacts_page.title')}>
            <Head title={t('contacts_page.title')} />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.title')}</h2>
                    <div className="flex gap-2">
                        {(
                            <Link
                                href={route('client.contacts.bulk-import')}
                                className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                            >
                                <Table2 className="h-4 w-4" /> {t('contacts_page.bulk_import')}
                            </Link>
                        )}
                        {(
                            <button type="button" onClick={() => setShowImportModal(true)} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                <Upload className="h-4 w-4" /> {t('contacts_page.import_csv')}
                            </button>
                        )}
                        <button type="button" onClick={() => handleExport(false)} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                            <Download className="h-4 w-4" /> {t('contacts_page.export_csv')}
                        </button>
                        <Link href={route('client.segments.index')} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                            <Tag className="h-4 w-4" /> {t('contacts_page.segments')}
                        </Link>
                        {(
                            <button type="button" onClick={() => setShowAddModal(true)} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                                <UserPlus className="h-4 w-4" /> {t('contacts_page.add_contact')}
                            </button>
                        )}
                    </div>
                </div>

                {flash.success && <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>}

                {/* Search */}
                <form onSubmit={handleSearch} className="flex gap-2">
                    <div className="relative flex-1">
                        <Search className="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" />
                        <input
                            type="text"
                            value={search}
                            onChange={e => setSearch(e.target.value)}
                            placeholder={t('contacts_page.search_placeholder')}
                            className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 pl-9 pr-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                        />
                    </div>
                    <button type="submit" className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">{t('common.search')}</button>
                </form>

                {/* Bulk action bar */}
                {someSelected && (
                    <div className="flex items-center gap-3 rounded-lg bg-brand-50 dark:bg-brand-900/20 border border-brand-200 dark:border-brand-700 px-4 py-2.5">
                        <span className="text-sm font-medium text-brand-700 dark:text-brand-300">{t('contacts_page.n_selected', { count: selected.size })}</span>
                        <div className="flex gap-2 ml-auto">
                            <button type="button" onClick={() => setBulkModal('tags')} className="flex items-center gap-1.5 rounded-lg border border-brand-300 dark:border-brand-600 px-3 py-1.5 text-xs font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-900/40 transition">
                                <Tag className="h-3.5 w-3.5" /> {t('contacts_page.bulk_tags_btn')}
                            </button>
                            <button type="button" onClick={() => setBulkModal('segments')} className="flex items-center gap-1.5 rounded-lg border border-brand-300 dark:border-brand-600 px-3 py-1.5 text-xs font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-900/40 transition">
                                <Layers className="h-3.5 w-3.5" /> {t('contacts_page.bulk_segments_btn')}
                            </button>
                            <button type="button" onClick={() => handleExport(true)} className="flex items-center gap-1.5 rounded-lg border border-brand-300 dark:border-brand-600 px-3 py-1.5 text-xs font-medium text-brand-700 dark:text-brand-300 hover:bg-brand-100 dark:hover:bg-brand-900/40 transition">
                                <Download className="h-3.5 w-3.5" /> {t('contacts_page.export_selected')}
                            </button>
                            <button type="button" onClick={handleBulkDelete} className="flex items-center gap-1.5 rounded-lg border border-red-300 dark:border-red-700 px-3 py-1.5 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                <Trash2 className="h-3.5 w-3.5" /> {t('contacts_page.delete_selected')}
                            </button>
                            <button type="button" onClick={clearSelection} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                <X className="h-4 w-4" />
                            </button>
                        </div>
                    </div>
                )}

                {/* Table */}
                <div className="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900">
                    <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                        <thead className="bg-neutral-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-4 py-3 w-10">
                                    <button type="button" onClick={toggleAll} className="text-neutral-400 hover:text-brand-600 transition">
                                        {allSelected
                                            ? <CheckSquare className="h-4 w-4 text-brand-600" />
                                            : <Square className="h-4 w-4" />
                                        }
                                    </button>
                                </th>
                                {[t('common.name'), t('contacts_page.col_phone'), t('common.email'), t('contacts_page.col_tags'), t('contacts_page.col_optins'), ''].map(h => (
                                    <th key={h} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                            {contacts.data.map(c => (
                                <ContactRow
                                    key={c.id}
                                    contact={c}
                                    selected={selected.has(c.uuid)}
                                    onToggle={toggleOne}
                                    onDelete={handleDelete}
                                />
                            ))}
                            {contacts.data.length === 0 && (
                                <tr>
                                    <td colSpan={7}>
                                        <EmptyState
                                            icon={<Users className="h-8 w-8" />}
                                            title={t('contacts_page.empty_title')}
                                            description={t('contacts_page.empty_description')}
                                            action={{ label: t('contacts_page.add_contact'), onClick: () => setShowAddModal(true) }}
                                            secondaryAction={{ label: t('contacts_page.bulk_import'), href: route('client.contacts.bulk-import') }}
                                        />
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {contacts.last_page > 1 && (
                    <div className="flex gap-1">
                        {contacts.links.map((link, i) => (
                            <a key={i} href={link.url ?? '#'} className={`px-3 py-1.5 rounded text-sm border ${link.active ? 'bg-brand-600 text-white border-brand-600' : 'border-neutral-300 dark:border-neutral-600 text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800'} ${!link.url ? 'opacity-40 pointer-events-none' : ''}`} dangerouslySetInnerHTML={{ __html: link.label }} />
                        ))}
                    </div>
                )}
            </div>

            {/* Import CSV wizard */}
            <ImportCsvModal open={showImportModal} onClose={() => setShowImportModal(false)} tags={tags} segments={segments} />

            {/* Bulk tag/segment modal */}
            {bulkModal && (
                <BulkManageModal
                    key={bulkModal}
                    mode={bulkModal}
                    count={selected.size}
                    tags={tags}
                    segments={segments}
                    processing={bulkProcessing}
                    onClose={() => setBulkModal(null)}
                    onApply={applyBulk}
                />
            )}

            {/* Add Contact Modal */}
            {showAddModal && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="w-full max-w-md rounded-xl bg-white dark:bg-neutral-900 p-6 shadow-xl space-y-4">
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.add_contact')}</h3>
                        <form onSubmit={submitAdd} className="space-y-3">
                            <div className="grid grid-cols-2 gap-3">
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.first_name')}</label>
                                    <input type="text" value={data.first_name} onChange={e => setData('first_name', e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                                </div>
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.last_name')}</label>
                                    <input type="text" value={data.last_name} onChange={e => setData('last_name', e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                                </div>
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.phone_e164')}</label>
                                <input type="text" value={data.phone_e164} onChange={e => handlePhoneChange(e.target.value)} placeholder="+8801XXXXXXXXX" className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div>
                                <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('common.email')}</label>
                                <input type="email" value={data.email} onChange={e => handleEmailChange(e.target.value)} className="mt-1 w-full rounded border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm" />
                            </div>
                            <div className="flex gap-4">
                                {[['opt_in_whatsapp', 'WhatsApp', !data.phone_e164.trim()], ['opt_in_sms', t('contacts_page.channel_sms'), !data.phone_e164.trim()], ['opt_in_email', t('common.email'), !data.email.trim()]].map(([key, label, disabled]) => (
                                    <label key={key} className={`flex items-center gap-1.5 text-sm ${disabled ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer'}`}>
                                        <input type="checkbox" checked={data[key]} onChange={e => setData(key, e.target.checked)} disabled={disabled} className="rounded" />
                                        {label}
                                    </label>
                                ))}
                            </div>
                            {segments.length > 0 && (
                                <div>
                                    <label className="text-xs font-medium text-neutral-500 dark:text-neutral-400">{t('contacts_page.add_to_segments')}</label>
                                    <div className="mt-1.5 flex flex-wrap gap-2">
                                        {segments.map(seg => {
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
                            <div className="flex gap-2 pt-2">
                                <button type="submit" disabled={processing} className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition">
                                    {processing ? t('common.saving') : t('common.save')}
                                </button>
                                <button type="button" onClick={() => setShowAddModal(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    {t('common.cancel')}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            )}
        </ClientLayout>
    );
}
