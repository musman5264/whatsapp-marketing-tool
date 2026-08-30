import { Head, Link, useForm, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import EmptyState from '@/Components/EmptyState';
import { ArrowLeft, Plus, RefreshCw, Trash2, Globe, FileText, Type, X, Upload, CheckCircle2, Clock, Zap, AlertCircle, HelpCircle, Trash } from 'lucide-react';
import { useState, useRef } from 'react';
import { useTranslation, Trans } from 'react-i18next';

const SOURCE_TYPES = {
    url:     { icon: Globe,    labelKey: 'ai.source_url' },
    file:    { icon: Upload,   labelKey: 'ai.source_file' },
    text:    { icon: Type,     labelKey: 'ai.source_text' },
    sitemap: { icon: Globe,    labelKey: 'ai.source_sitemap' },
    faq:     { icon: HelpCircle, labelKey: 'ai.source_faq' },
};

const STATUS_CONFIG = {
    indexed:  { color: 'bg-green-100 text-green-700 dark:bg-green-900/40 dark:text-green-300',   icon: CheckCircle2 },
    pending:  { color: 'bg-yellow-100 text-yellow-700 dark:bg-yellow-900/40 dark:text-yellow-300', icon: Clock },
    indexing: { color: 'bg-blue-100 text-blue-700 dark:bg-blue-900/40 dark:text-blue-300',        icon: Zap },
    error:    { color: 'bg-red-100 text-red-700 dark:bg-red-900/40 dark:text-red-300',            icon: AlertCircle },
};

export default function AiKnowledgeBaseShow({ kb }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const [showAdd, setShowAdd] = useState(false);
    const [dragOver, setDragOver] = useState(false);
    const [faqPairs, setFaqPairs] = useState([{ question: '', answer: '' }]);
    const fileRef = useRef();

    const { data, setData, reset } = useForm({
        source_type: 'url',
        source_ref: '',
        title: '',
        file: null,
    });
    const [processing, setProcessing] = useState(false);

    const addFaqPair = () => setFaqPairs(p => [...p, { question: '', answer: '' }]);
    const removeFaqPair = (i) => setFaqPairs(p => p.filter((_, idx) => idx !== i));
    const updateFaqPair = (i, field, value) => setFaqPairs(p => p.map((pair, idx) => idx === i ? { ...pair, [field]: value } : pair));

    const handleAdd = (e) => {
        e.preventDefault();
        setProcessing(true);
        const formData = new FormData();
        formData.append('source_type', data.source_type);
        formData.append('title', data.title);
        if (data.source_type === 'faq') {
            formData.append('source_ref', JSON.stringify(faqPairs.filter(p => p.question.trim())));
        } else {
            formData.append('source_ref', data.source_ref);
        }
        if (data.file) formData.append('file', data.file);
        router.post(route('client.ai.knowledge-bases.documents.add', kb.uuid), formData, {
            preserveScroll: true,
            onSuccess: () => { reset(); setFaqPairs([{ question: '', answer: '' }]); setShowAdd(false); setProcessing(false); },
            onError: () => setProcessing(false),
        });
    };

    const handleDelete = (docId) => {
        if (confirm(t('ai.remove_document_confirm'))) {
            router.delete(route('client.ai.documents.destroy', docId), { preserveScroll: true });
        }
    };

    const handleReindex = (docId) => {
        router.post(route('client.ai.documents.reindex', docId), {}, { preserveScroll: true });
    };

    const handleDrop = (e) => {
        e.preventDefault();
        setDragOver(false);
        const file = e.dataTransfer.files[0];
        if (file) { setData('file', file); setData('source_type', 'file'); }
    };

    const totalTokens = kb.documents?.reduce((s, d) => s + (d.tokens ?? 0), 0) ?? 0;
    const indexedCount = kb.documents?.filter(d => d.status === 'indexed').length ?? 0;

    return (
        <ClientLayout title={kb.name}>
            <Head title={`${kb.name} · ${t('ai.kb_title')}`} />
            <div className="space-y-6">
                {/* Header */}
                <div className="flex items-center gap-3">
                    <Link href={route('client.ai.knowledge-bases.index')} className="rounded-lg p-1.5 text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200 hover:bg-neutral-100 dark:hover:bg-neutral-800 transition">
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                    <div className="flex-1 min-w-0">
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100 truncate">{kb.name}</h2>
                    </div>
                    {(
                        <button
                            onClick={() => setShowAdd(true)}
                            className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-3 py-2 text-sm font-medium text-white hover:bg-brand-700 transition shrink-0"
                        >
                            <Plus className="h-4 w-4" /> {t('ai.add_document')}
                        </button>
                    )}
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 border border-green-200 dark:border-green-800 text-green-800 dark:text-green-200 px-4 py-2.5 text-sm">
                        {flash.success}
                    </div>
                )}

                {/* Stats */}
                {(kb.documents?.length ?? 0) > 0 && (
                    <div className="grid grid-cols-3 gap-3">
                        {[
                            { label: t('ai.total_documents'), value: kb.documents?.length ?? 0 },
                            { label: t('ai.indexed'), value: indexedCount },
                            { label: t('ai.total_tokens'), value: totalTokens.toLocaleString() },
                        ].map(({ label, value }) => (
                            <div key={label} className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 px-4 py-3">
                                <p className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 leading-none">{value}</p>
                                <p className="text-xs text-neutral-500 dark:text-neutral-400 mt-1">{label}</p>
                            </div>
                        ))}
                    </div>
                )}

                {/* Documents Table */}
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 overflow-hidden">
                    {(kb.documents?.length ?? 0) > 0 ? (
                        <table className="min-w-full divide-y divide-neutral-100 dark:divide-neutral-800 text-sm">
                            <thead className="bg-neutral-50 dark:bg-neutral-800/60">
                                <tr>
                                    {[
                                        { key: 'document', label: t('ai.col_document') },
                                        { key: 'type', label: t('ai.col_type') },
                                        { key: 'status', label: t('ai.col_status') },
                                        { key: 'tokens', label: t('ai.col_tokens') },
                                        { key: 'actions', label: '' },
                                    ].map(h => (
                                        <th key={h.key} className="px-4 py-3 text-left text-xs font-semibold uppercase tracking-wider text-neutral-400 dark:text-neutral-500">{h.label}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                {kb.documents.map(doc => {
                                    const { icon: TypeIcon, labelKey: typeLabelKey } = SOURCE_TYPES[doc.source_type] ?? SOURCE_TYPES.file;
                                    const { color, icon: StatusIcon } = STATUS_CONFIG[doc.status] ?? STATUS_CONFIG.pending;
                                    return (
                                        <tr key={doc.id} className="hover:bg-neutral-50 dark:hover:bg-neutral-800/40 transition">
                                            <td className="px-4 py-3 max-w-xs">
                                                <p className="font-medium text-neutral-900 dark:text-neutral-100 truncate">{doc.title || doc.source_ref || '—'}</p>
                                                {doc.title && doc.source_ref && (
                                                    <p className="text-xs text-neutral-400 dark:text-neutral-500 truncate mt-0.5">{doc.source_ref}</p>
                                                )}
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="inline-flex items-center gap-1 rounded-md bg-neutral-100 dark:bg-neutral-800 px-2 py-0.5 text-xs text-neutral-600 dark:text-neutral-400">
                                                    <TypeIcon className="h-3 w-3" /> {t(typeLabelKey)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium ${color}`}>
                                                    <StatusIcon className="h-3 w-3" /> {t(`ai.doc_status_${doc.status}`)}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 text-neutral-500 dark:text-neutral-400 tabular-nums">
                                                {(doc.tokens ?? 0).toLocaleString()}
                                            </td>
                                            <td className="px-4 py-3">
                                                <div className="flex items-center gap-1 justify-end">
                                                    <button
                                                        onClick={() => handleReindex(doc.uuid)}
                                                        title={t('ai.reindex')}
                                                        className="rounded-md p-1.5 text-neutral-400 hover:text-brand-600 hover:bg-brand-50 dark:hover:bg-brand-900/20 transition"
                                                    >
                                                        <RefreshCw className="h-3.5 w-3.5" />
                                                    </button>
                                                    <button
                                                        onClick={() => handleDelete(doc.uuid)}
                                                        className="rounded-md p-1.5 text-neutral-400 hover:text-red-500 hover:bg-red-50 dark:hover:bg-red-900/20 transition"
                                                    >
                                                        <Trash2 className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    ) : (
                        <EmptyState
                            icon={<FileText className="h-8 w-8" />}
                            title={t('ai.docs_empty_title')}
                            description={t('ai.docs_empty_description')}
                            action={{ label: t('ai.add_first_document'), onClick: () => setShowAdd(true) }}
                        />
                    )}
                </div>
            </div>

            {/* Add Document Modal */}
            {showAdd && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 backdrop-blur-sm p-4">
                    <div className="w-full max-w-md rounded-2xl bg-white dark:bg-neutral-900 shadow-2xl">
                        <div className="flex items-center justify-between px-6 pt-5 pb-4 border-b border-neutral-100 dark:border-neutral-800">
                            <h3 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">{t('ai.add_document')}</h3>
                            <button onClick={() => setShowAdd(false)} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                <X className="h-4 w-4" />
                            </button>
                        </div>

                        <form onSubmit={handleAdd} className="px-6 py-4 space-y-4">
                            {/* Source Type Tabs */}
                            <div>
                                <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-2">{t('ai.source_type')}</label>
                                <div className="grid grid-cols-5 gap-1 rounded-lg bg-neutral-100 dark:bg-neutral-800 p-1">
                                    {Object.entries(SOURCE_TYPES).map(([type, { icon: Icon, labelKey }]) => (
                                        <button
                                            key={type}
                                            type="button"
                                            onClick={() => setData('source_type', type)}
                                            className={`flex flex-col items-center gap-0.5 rounded-md py-1.5 px-1 text-xs font-medium transition ${
                                                data.source_type === type
                                                    ? 'bg-white dark:bg-neutral-700 text-brand-600 dark:text-brand-400 shadow-sm'
                                                    : 'text-neutral-500 dark:text-neutral-400 hover:text-neutral-700 dark:hover:text-neutral-200'
                                            }`}
                                        >
                                            <Icon className="h-3.5 w-3.5" />
                                            {t(labelKey)}
                                        </button>
                                    ))}
                                </div>
                            </div>

                            {/* Input Area */}
                            {data.source_type === 'file' ? (
                                <div
                                    onDragOver={e => { e.preventDefault(); setDragOver(true); }}
                                    onDragLeave={() => setDragOver(false)}
                                    onDrop={handleDrop}
                                    onClick={() => fileRef.current?.click()}
                                    className={`relative cursor-pointer rounded-lg border-2 border-dashed p-6 text-center transition ${
                                        dragOver
                                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                            : 'border-neutral-300 dark:border-neutral-600 hover:border-brand-400 dark:hover:border-brand-600'
                                    }`}
                                >
                                    <input ref={fileRef} type="file" accept=".pdf,.txt,.md,.docx" className="hidden" onChange={e => setData('file', e.target.files[0])} />
                                    <Upload className="h-6 w-6 mx-auto mb-2 text-neutral-400" />
                                    {data.file ? (
                                        <p className="text-sm font-medium text-brand-600 dark:text-brand-400">{data.file.name}</p>
                                    ) : (
                                        <>
                                            <p className="text-sm text-neutral-600 dark:text-neutral-400"><Trans i18nKey="ai.drop_a_file_or_browse" components={{ 1: <span className="text-brand-600 dark:text-brand-400 font-medium" /> }} /></p>
                                            <p className="text-xs text-neutral-400 mt-1">PDF, TXT, MD, DOCX</p>
                                        </>
                                    )}
                                </div>
                            ) : data.source_type === 'text' ? (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.text_content')}</label>
                                    <textarea
                                        value={data.source_ref}
                                        onChange={e => setData('source_ref', e.target.value)}
                                        rows={5}
                                        placeholder={t('ai.text_content_placeholder')}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
                                    />
                                </div>
                            ) : data.source_type === 'faq' ? (
                                <div className="space-y-2">
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300">{t('ai.questions_and_answers')}</label>
                                    <div className="space-y-3 max-h-52 overflow-y-auto pr-1">
                                        {faqPairs.map((pair, i) => (
                                            <div key={i} className="rounded-lg border border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 p-3 space-y-2">
                                                <div className="flex items-center gap-2">
                                                    <span className="text-xs font-semibold text-neutral-400 w-4 shrink-0">Q{i + 1}</span>
                                                    <input
                                                        type="text"
                                                        value={pair.question}
                                                        onChange={e => updateFaqPair(i, 'question', e.target.value)}
                                                        placeholder={t('ai.faq_question_placeholder')}
                                                        className="flex-1 rounded-md border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                                    />
                                                    {faqPairs.length > 1 && (
                                                        <button type="button" onClick={() => removeFaqPair(i)} className="text-neutral-300 hover:text-red-400 transition">
                                                            <Trash className="h-3.5 w-3.5" />
                                                        </button>
                                                    )}
                                                </div>
                                                <div className="flex items-start gap-2">
                                                    <span className="text-xs font-semibold text-neutral-400 w-4 shrink-0 mt-1.5">A</span>
                                                    <textarea
                                                        value={pair.answer}
                                                        onChange={e => updateFaqPair(i, 'answer', e.target.value)}
                                                        rows={2}
                                                        placeholder={t('ai.faq_answer_placeholder')}
                                                        className="flex-1 rounded-md border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-xs text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 resize-none transition"
                                                    />
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                    <button
                                        type="button"
                                        onClick={addFaqPair}
                                        className="w-full rounded-lg border border-dashed border-neutral-300 dark:border-neutral-600 py-1.5 text-xs text-neutral-500 dark:text-neutral-400 hover:border-brand-400 hover:text-brand-600 dark:hover:text-brand-400 transition"
                                    >
                                        {t('ai.add_another_qa')}
                                    </button>
                                </div>
                            ) : (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                        {data.source_type === 'sitemap' ? t('ai.sitemap_url') : t('ai.source_url')}
                                    </label>
                                    <input
                                        type="url"
                                        value={data.source_ref}
                                        onChange={e => setData('source_ref', e.target.value)}
                                        placeholder={data.source_type === 'sitemap' ? 'https://example.com/sitemap.xml' : 'https://example.com'}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                    />
                                    {data.source_type === 'sitemap' && (
                                        <p className="mt-1 text-xs text-neutral-400">{t('ai.sitemap_hint')}</p>
                                    )}
                                </div>
                            )}

                            {data.source_type !== 'faq' && (
                                <div>
                                    <label className="block text-xs font-medium text-neutral-700 dark:text-neutral-300 mb-1">{t('ai.title_label')} <span className="text-neutral-400 font-normal">({t('common.optional')})</span></label>
                                    <input
                                        type="text"
                                        value={data.title}
                                        onChange={e => setData('title', e.target.value)}
                                        placeholder={t('ai.title_placeholder')}
                                        className="w-full rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-2 text-sm text-neutral-900 dark:text-neutral-100 placeholder-neutral-400 focus:outline-none focus:ring-2 focus:ring-brand-500 transition"
                                    />
                                </div>
                            )}

                            <div className="flex gap-2 pt-1 pb-2">
                                <button
                                    type="submit"
                                    disabled={processing}
                                    className="flex-1 rounded-lg bg-brand-600 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60 transition"
                                >
                                    {processing ? t('ai.adding') : t('ai.add_and_index')}
                                </button>
                                <button
                                    type="button"
                                    onClick={() => setShowAdd(false)}
                                    className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition"
                                >
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
