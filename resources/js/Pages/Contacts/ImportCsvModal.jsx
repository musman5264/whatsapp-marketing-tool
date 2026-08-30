import { useCallback, useMemo, useRef, useState } from 'react';
import { router } from '@inertiajs/react';
import {
    AlertTriangle, ArrowLeft, ArrowRight, CheckCircle2, FileSpreadsheet,
    Loader2, RefreshCw, Table2, Upload, UserPlus, Users, X, XCircle,
} from 'lucide-react';
import { useTranslation } from 'react-i18next';

const MAX_FILE_SIZE = 10 * 1024 * 1024; // 10 MB
const MAX_ROWS = 10000;
const CHUNK_SIZE = 250;

const TARGET_FIELDS = [
    'first_name', 'last_name', 'name', 'phone_e164', 'email',
    'country', 'language', 'tags', 'opt_in_whatsapp', 'opt_in_sms', 'opt_in_email',
];

// Mirrors ContactService::IMPORT_FIELD_ALIASES for header auto-detection.
const FIELD_ALIASES = {
    first_name: ['first_name', 'firstname', 'first', 'fname', 'given_name'],
    last_name: ['last_name', 'lastname', 'last', 'lname', 'surname', 'family_name'],
    name: ['name', 'full_name', 'contact_name', 'contact'],
    phone_e164: ['phone_e164', 'phone', 'phone_number', 'mobile', 'mobile_number', 'whatsapp', 'whatsapp_number', 'tel', 'telephone', 'msisdn', 'number'],
    email: ['email', 'email_address', 'e_mail', 'mail'],
    country: ['country', 'country_code'],
    language: ['language', 'lang', 'locale'],
    tags: ['tags', 'tag', 'labels', 'label'],
    opt_in_whatsapp: ['opt_in_whatsapp', 'whatsapp_opt_in', 'opt_in_wa', 'wa_opt_in'],
    opt_in_sms: ['opt_in_sms', 'sms_opt_in'],
    opt_in_email: ['opt_in_email', 'email_opt_in'],
};

const normalizeHeader = (h) => String(h ?? '')
    .toLowerCase().trim()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/_+/g, '_')
    .replace(/^_|_$/g, '');

const autoDetectField = (header) => {
    const normal = normalizeHeader(header);
    for (const [field, aliases] of Object.entries(FIELD_ALIASES)) {
        if (aliases.includes(normal)) return field;
    }
    return 'skip';
};

function detectDelimiter(text) {
    const firstLine = text.slice(0, text.indexOf('\n') === -1 ? text.length : text.indexOf('\n'));
    let best = ',', bestCount = 0;
    for (const d of [',', ';', '\t', '|']) {
        const count = firstLine.split(d).length - 1;
        if (count > bestCount) { best = d; bestCount = count; }
    }
    return best;
}

// RFC-4180-ish CSV parser: quoted fields, escaped quotes, CR/LF, custom delimiter.
function parseCsv(text, delimiter) {
    const rows = [];
    let row = [], field = '', inQuotes = false;
    for (let i = 0; i < text.length; i++) {
        const ch = text[i];
        if (inQuotes) {
            if (ch === '"') {
                if (text[i + 1] === '"') { field += '"'; i++; }
                else inQuotes = false;
            } else field += ch;
        } else if (ch === '"') {
            inQuotes = true;
        } else if (ch === delimiter) {
            row.push(field); field = '';
        } else if (ch === '\n' || ch === '\r') {
            if (ch === '\r' && text[i + 1] === '\n') i++;
            row.push(field); field = '';
            rows.push(row); row = [];
        } else {
            field += ch;
        }
    }
    if (field !== '' || row.length > 0) { row.push(field); rows.push(row); }
    return rows.filter(r => r.some(c => String(c).trim() !== ''));
}

const prettyBytes = (n) => n < 1024 ? `${n} B` : n < 1048576 ? `${(n / 1024).toFixed(1)} KB` : `${(n / 1048576).toFixed(1)} MB`;

function ChipPicker({ options, selected, onToggle }) {
    return (
        <div className="flex flex-wrap gap-2 max-h-28 overflow-y-auto">
            {options.map(({ key, label }) => {
                const checked = selected.has(key);
                return (
                    <button
                        key={key}
                        type="button"
                        onClick={() => onToggle(key)}
                        className={`rounded-full border px-3 py-1 text-xs transition ${checked
                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/30 text-brand-700 dark:text-brand-300'
                            : 'border-neutral-300 dark:border-neutral-600 text-neutral-600 dark:text-neutral-400 hover:border-brand-400'}`}
                    >
                        {label}
                    </button>
                );
            })}
        </div>
    );
}

export default function ImportCsvModal({ open, onClose, tags = [], segments = [] }) {
    const { t } = useTranslation();
    const fileInput = useRef();
    const cancelRef = useRef(false);

    const [step, setStep] = useState('upload'); // upload | map | importing | done
    const [dragging, setDragging] = useState(false);
    const [fileMeta, setFileMeta] = useState(null); // { name, size }
    const [fileError, setFileError] = useState(null);
    const [truncated, setTruncated] = useState(false);
    const [rawRows, setRawRows] = useState([]);
    const [hasHeader, setHasHeader] = useState(true);
    const [mapping, setMapping] = useState([]); // target field per column, 'skip' = ignore
    const [progress, setProgress] = useState({ processed: 0, total: 0 });
    const [stats, setStats] = useState({ created: 0, updated: 0, skipped: 0, errors: [] });
    const [importError, setImportError] = useState(null);
    const [cancelled, setCancelled] = useState(false);
    const [applyTagNames, setApplyTagNames] = useState(new Set());
    const [applySegmentIds, setApplySegmentIds] = useState(new Set());
    const [newTag, setNewTag] = useState('');

    const toggleIn = (setter) => (value) => setter(prev => {
        const next = new Set(prev);
        next.has(value) ? next.delete(value) : next.add(value);
        return next;
    });
    const toggleApplyTag = toggleIn(setApplyTagNames);
    const toggleApplySegment = toggleIn(setApplySegmentIds);

    const addNewTag = () => {
        const name = newTag.trim().slice(0, 64);
        if (!name) return;
        setApplyTagNames(prev => new Set([...prev, name]));
        setNewTag('');
    };

    // Workspace tags plus freshly typed names not saved yet.
    const tagOptions = [...new Set([...tags.map(tg => tg.name), ...applyTagNames])];

    const headers = useMemo(() => {
        if (rawRows.length === 0) return [];
        const width = Math.max(...rawRows.map(r => r.length));
        return Array.from({ length: width }, (_, i) =>
            hasHeader ? String(rawRows[0]?.[i] ?? '').trim() || t('contacts_import.column_n', { n: i + 1 }) : t('contacts_import.column_n', { n: i + 1 })
        );
    }, [rawRows, hasHeader, t]);

    const dataRows = useMemo(() => (hasHeader ? rawRows.slice(1) : rawRows), [rawRows, hasHeader]);

    const sampleFor = useCallback((col) => {
        const samples = [];
        for (const row of dataRows) {
            const v = String(row[col] ?? '').trim();
            if (v) samples.push(v);
            if (samples.length >= 3) break;
        }
        return samples;
    }, [dataRows]);

    const identifierMapped = mapping.includes('phone_e164') || mapping.includes('email');
    const duplicateTargets = useMemo(() => {
        const seen = {}, dupes = new Set();
        mapping.forEach(f => { if (f !== 'skip') { if (seen[f]) dupes.add(f); seen[f] = true; } });
        return dupes;
    }, [mapping]);

    const resetAll = () => {
        setStep('upload'); setDragging(false); setFileMeta(null); setFileError(null);
        setTruncated(false); setRawRows([]); setHasHeader(true); setMapping([]);
        setProgress({ processed: 0, total: 0 }); setStats({ created: 0, updated: 0, skipped: 0, errors: [] });
        setImportError(null); setCancelled(false);
        setApplyTagNames(new Set()); setApplySegmentIds(new Set()); setNewTag('');
        cancelRef.current = false;
    };

    const closeModal = (reload = false) => {
        if (step === 'importing') return; // use Cancel button instead
        onClose();
        if (reload) router.reload({ only: ['contacts'] });
        resetAll();
    };

    const acceptFile = (file) => {
        setFileError(null);
        if (!file) return;
        if (!/\.(csv|txt|tsv)$/i.test(file.name)) {
            setFileError(t('contacts_import.err_file_type'));
            return;
        }
        if (file.size > MAX_FILE_SIZE) {
            setFileError(t('contacts_import.err_file_size'));
            return;
        }
        const reader = new FileReader();
        reader.onload = () => {
            try {
                const text = String(reader.result ?? '').replace(/^\uFEFF/, '');
                const rows = parseCsv(text, detectDelimiter(text));
                if (rows.length < 1) {
                    setFileError(t('contacts_import.err_empty'));
                    return;
                }
                const headerLikely = rows.length > 1 && rows[0].some(c => autoDetectField(c) !== 'skip');
                setTruncated(rows.length - 1 > MAX_ROWS);
                setRawRows(rows.slice(0, MAX_ROWS + 1));
                setHasHeader(headerLikely);
                setFileMeta({ name: file.name, size: file.size });
                const width = Math.max(...rows.map(r => r.length));
                setMapping(Array.from({ length: width }, (_, i) => (headerLikely ? autoDetectField(rows[0]?.[i]) : 'skip')));
                setStep('map');
            } catch {
                setFileError(t('contacts_import.err_parse'));
            }
        };
        reader.onerror = () => setFileError(t('contacts_import.err_parse'));
        reader.readAsText(file);
    };

    const onDrop = (e) => {
        e.preventDefault();
        setDragging(false);
        acceptFile(e.dataTransfer.files?.[0]);
    };

    const buildPayloadRows = () => dataRows
        .map(row => {
            const obj = {};
            mapping.forEach((field, col) => {
                if (field === 'skip') return;
                const v = String(row[col] ?? '').trim();
                if (v !== '' && obj[field] === undefined) obj[field] = v;
            });
            return obj;
        })
        .filter(obj => Object.keys(obj).length > 0);

    const startImport = async () => {
        const rows = buildPayloadRows();
        if (rows.length === 0) {
            setImportError(t('contacts_import.err_no_rows'));
            return;
        }
        cancelRef.current = false;
        setCancelled(false);
        setImportError(null);
        setStats({ created: 0, updated: 0, skipped: 0, errors: [] });
        setProgress({ processed: 0, total: rows.length });
        setStep('importing');

        const totals = { created: 0, updated: 0, skipped: 0, errors: [] };
        try {
            for (let offset = 0; offset < rows.length; offset += CHUNK_SIZE) {
                if (cancelRef.current) { setCancelled(true); break; }
                const chunk = rows.slice(offset, offset + CHUNK_SIZE);
                const { data } = await window.axios.post(route('client.contacts.import-rows'), {
                    rows: chunk,
                    tag_names: [...applyTagNames],
                    segment_ids: [...applySegmentIds],
                });
                totals.created += data.created ?? 0;
                totals.updated += data.updated ?? 0;
                totals.skipped += data.skipped ?? 0;
                (data.errors ?? []).forEach(err => {
                    if (totals.errors.length < 25) totals.errors.push({ ...err, row: offset + err.row });
                });
                setStats({ ...totals });
                setProgress({ processed: Math.min(offset + chunk.length, rows.length), total: rows.length });
            }
        } catch (e) {
            setImportError(e?.response?.data?.message || t('contacts_import.err_import_failed'));
        }
        setStep('done');
    };

    if (!open) return null;

    const percent = progress.total > 0 ? Math.round((progress.processed / progress.total) * 100) : 0;
    const steps = [
        { key: 'upload', label: t('contacts_import.step_upload') },
        { key: 'map', label: t('contacts_import.step_map') },
        { key: 'importing', label: t('contacts_import.step_import') },
        { key: 'done', label: t('contacts_import.step_done') },
    ];
    const stepIndex = steps.findIndex(s => s.key === step);

    const selectableFields = ['skip', ...TARGET_FIELDS];
    const fieldLabel = (f) => t(`contacts_import.field_${f}`);

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4" onMouseDown={(e) => { if (e.target === e.currentTarget) closeModal(step === 'done'); }}>
            <div className="w-full max-w-3xl max-h-[90vh] flex flex-col rounded-xl bg-white dark:bg-neutral-900 shadow-xl">
                {/* Header */}
                <div className="flex items-start justify-between border-b border-neutral-200 dark:border-neutral-700 px-6 py-4">
                    <div>
                        <h3 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100 flex items-center gap-2">
                            <FileSpreadsheet className="h-5 w-5 text-brand-600" />
                            {t('contacts_import.title')}
                        </h3>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">{t('contacts_import.subtitle')}</p>
                    </div>
                    {step !== 'importing' && (
                        <button type="button" onClick={() => closeModal(step === 'done')} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                            <X className="h-5 w-5" />
                        </button>
                    )}
                </div>

                {/* Step indicator */}
                <div className="flex items-center gap-2 px-6 py-3 border-b border-neutral-100 dark:border-neutral-800">
                    {steps.map((s, i) => (
                        <div key={s.key} className="flex items-center gap-2">
                            <div className={`flex items-center gap-1.5 text-xs font-medium ${i <= stepIndex ? 'text-brand-600 dark:text-brand-400' : 'text-neutral-400 dark:text-neutral-500'}`}>
                                <span className={`flex h-5 w-5 items-center justify-center rounded-full text-[10px] ${i < stepIndex ? 'bg-brand-600 text-white' : i === stepIndex ? 'border-2 border-brand-600 text-brand-600 dark:text-brand-400' : 'border border-neutral-300 dark:border-neutral-600'}`}>
                                    {i < stepIndex ? '✓' : i + 1}
                                </span>
                                {s.label}
                            </div>
                            {i < steps.length - 1 && <div className={`h-px w-6 ${i < stepIndex ? 'bg-brand-500' : 'bg-neutral-200 dark:bg-neutral-700'}`} />}
                        </div>
                    ))}
                </div>

                <div className="flex-1 overflow-y-auto px-6 py-5">
                    {/* STEP 1 — Upload */}
                    {step === 'upload' && (
                        <div className="space-y-4">
                            <div
                                onDragOver={(e) => { e.preventDefault(); setDragging(true); }}
                                onDragLeave={() => setDragging(false)}
                                onDrop={onDrop}
                                onClick={() => fileInput.current?.click()}
                                className={`flex cursor-pointer flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed px-6 py-12 text-center transition
                                    ${dragging
                                        ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                                        : 'border-neutral-300 dark:border-neutral-600 hover:border-brand-400 hover:bg-neutral-50 dark:hover:bg-neutral-800/50'}`}
                            >
                                <div className={`rounded-full p-3 ${dragging ? 'bg-brand-100 dark:bg-brand-900/40' : 'bg-neutral-100 dark:bg-neutral-800'}`}>
                                    <Upload className={`h-7 w-7 ${dragging ? 'text-brand-600' : 'text-neutral-400'}`} />
                                </div>
                                <div>
                                    <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('contacts_import.drop_title')}</p>
                                    <p className="mt-1 text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_import.drop_hint')}</p>
                                </div>
                                <span className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                                    {t('contacts_import.browse')}
                                </span>
                                <input ref={fileInput} type="file" accept=".csv,.txt,.tsv" className="hidden" onChange={(e) => { acceptFile(e.target.files?.[0]); e.target.value = ''; }} />
                            </div>

                            {fileError && (
                                <div className="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 px-4 py-2.5 text-sm text-red-700 dark:text-red-300">
                                    <XCircle className="h-4 w-4 flex-shrink-0" /> {fileError}
                                </div>
                            )}

                            <div className="rounded-lg bg-neutral-50 dark:bg-neutral-800/60 px-4 py-3 text-xs text-neutral-500 dark:text-neutral-400 leading-relaxed">
                                {t('contacts_import.upload_note')}
                            </div>
                        </div>
                    )}

                    {/* STEP 2 — Map columns */}
                    {step === 'map' && (
                        <div className="space-y-4">
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <div className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                                    <FileSpreadsheet className="h-4 w-4 text-brand-600" />
                                    <span className="font-medium">{fileMeta?.name}</span>
                                    <span className="text-neutral-400">({prettyBytes(fileMeta?.size ?? 0)})</span>
                                    <span className="rounded-full bg-brand-50 dark:bg-brand-900/30 px-2 py-0.5 text-xs font-medium text-brand-700 dark:text-brand-300">
                                        {t('contacts_import.rows_detected', { count: dataRows.length })}
                                    </span>
                                </div>
                                <label className="flex items-center gap-1.5 text-xs text-neutral-600 dark:text-neutral-400 cursor-pointer">
                                    <input type="checkbox" checked={hasHeader} onChange={e => setHasHeader(e.target.checked)} className="rounded" />
                                    {t('contacts_import.has_header')}
                                </label>
                            </div>

                            {truncated && (
                                <div className="flex items-center gap-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 px-4 py-2.5 text-sm text-amber-700 dark:text-amber-300">
                                    <AlertTriangle className="h-4 w-4 flex-shrink-0" /> {t('contacts_import.truncated', { max: MAX_ROWS.toLocaleString() })}
                                </div>
                            )}

                            <p className="text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_import.map_hint')}</p>

                            <div className="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                                <table className="min-w-full divide-y divide-neutral-200 dark:divide-neutral-700 text-sm">
                                    <thead className="bg-neutral-50 dark:bg-neutral-800">
                                        <tr>
                                            {[t('contacts_import.csv_column'), t('contacts_import.preview'), t('contacts_import.maps_to')].map(h => (
                                                <th key={h} className="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">{h}</th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody className="divide-y divide-neutral-100 dark:divide-neutral-800">
                                        {headers.map((header, col) => {
                                            const field = mapping[col] ?? 'skip';
                                            const isDupe = field !== 'skip' && duplicateTargets.has(field);
                                            return (
                                                <tr key={col} className={field === 'skip' ? 'opacity-60' : ''}>
                                                    <td className="px-4 py-2.5 font-medium text-neutral-800 dark:text-neutral-200 whitespace-nowrap">{header}</td>
                                                    <td className="px-4 py-2.5 text-xs text-neutral-500 dark:text-neutral-400 max-w-[220px]">
                                                        <div className="truncate">{sampleFor(col).join(' · ') || '—'}</div>
                                                    </td>
                                                    <td className="px-4 py-2.5">
                                                        <select
                                                            value={field}
                                                            onChange={e => setMapping(prev => prev.map((f, i) => (i === col ? e.target.value : f)))}
                                                            className={`w-44 rounded-lg border bg-white dark:bg-neutral-800 px-2.5 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500
                                                                ${isDupe ? 'border-amber-400' : field !== 'skip' ? 'border-brand-300 dark:border-brand-700' : 'border-neutral-300 dark:border-neutral-600'}`}
                                                        >
                                                            {selectableFields.map(f => (
                                                                <option key={f} value={f}>{fieldLabel(f)}</option>
                                                            ))}
                                                        </select>
                                                        {isDupe && <p className="mt-1 text-[11px] text-amber-600 dark:text-amber-400">{t('contacts_import.duplicate_mapping')}</p>}
                                                    </td>
                                                </tr>
                                            );
                                        })}
                                    </tbody>
                                </table>
                            </div>

                            {/* Apply tags/segments to every imported contact */}
                            <div className="rounded-lg border border-neutral-200 dark:border-neutral-700 p-4 space-y-3">
                                <div>
                                    <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200">{t('contacts_import.apply_all_title')}</p>
                                    <p className="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400">{t('contacts_import.apply_all_hint')}</p>
                                </div>

                                <div>
                                    <p className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">{t('contacts_import.apply_tags_label')}</p>
                                    <div className="flex gap-2 mb-2">
                                        <input
                                            type="text"
                                            value={newTag}
                                            onChange={e => setNewTag(e.target.value)}
                                            onKeyDown={e => { if (e.key === 'Enter') { e.preventDefault(); addNewTag(); } }}
                                            placeholder={t('contacts_import.new_tag_placeholder')}
                                            className="flex-1 rounded-lg border border-neutral-300 dark:border-neutral-600 bg-white dark:bg-neutral-800 px-3 py-1.5 text-sm text-neutral-900 dark:text-neutral-100 focus:outline-none focus:ring-2 focus:ring-brand-500"
                                        />
                                        <button type="button" onClick={addNewTag} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-1.5 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                            {t('contacts_import.add_tag_btn')}
                                        </button>
                                    </div>
                                    {tagOptions.length > 0
                                        ? <ChipPicker options={tagOptions.map(name => ({ key: name, label: name }))} selected={applyTagNames} onToggle={toggleApplyTag} />
                                        : <p className="text-xs text-neutral-400 dark:text-neutral-500">{t('contacts_import.no_tags_hint')}</p>}
                                </div>

                                <div>
                                    <p className="mb-1.5 text-xs font-semibold uppercase tracking-wider text-neutral-500 dark:text-neutral-400">{t('contacts_import.apply_segments_label')}</p>
                                    {segments.length > 0
                                        ? <ChipPicker options={segments.map(seg => ({ key: seg.id, label: seg.name }))} selected={applySegmentIds} onToggle={toggleApplySegment} />
                                        : <p className="text-xs text-neutral-400 dark:text-neutral-500">{t('contacts_import.no_segments_hint')}</p>}
                                </div>
                            </div>

                            {!identifierMapped && (
                                <div className="flex items-center gap-2 rounded-lg bg-amber-50 dark:bg-amber-900/20 px-4 py-2.5 text-sm text-amber-700 dark:text-amber-300">
                                    <AlertTriangle className="h-4 w-4 flex-shrink-0" /> {t('contacts_import.need_identifier')}
                                </div>
                            )}
                            {importError && (
                                <div className="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-900/20 px-4 py-2.5 text-sm text-red-700 dark:text-red-300">
                                    <XCircle className="h-4 w-4 flex-shrink-0" /> {importError}
                                </div>
                            )}
                        </div>
                    )}

                    {/* STEP 3 — Importing */}
                    {step === 'importing' && (
                        <div className="space-y-5 py-6">
                            <div className="flex items-center justify-center gap-2 text-sm font-medium text-neutral-700 dark:text-neutral-300">
                                <Loader2 className="h-4 w-4 animate-spin text-brand-600" />
                                {t('contacts_import.importing', { processed: progress.processed.toLocaleString(), total: progress.total.toLocaleString() })}
                            </div>
                            <div className="h-3 w-full overflow-hidden rounded-full bg-neutral-100 dark:bg-neutral-800">
                                <div className="h-full rounded-full bg-brand-600 transition-all duration-300" style={{ width: `${percent}%` }} />
                            </div>
                            <p className="text-center text-xs text-neutral-500 dark:text-neutral-400">{percent}%</p>
                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    [t('contacts_import.created'), stats.created, 'text-green-600 dark:text-green-400'],
                                    [t('contacts_import.updated'), stats.updated, 'text-blue-600 dark:text-blue-400'],
                                    [t('contacts_import.skipped'), stats.skipped, 'text-amber-600 dark:text-amber-400'],
                                ].map(([label, value, color]) => (
                                    <div key={label} className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-3 py-2.5 text-center">
                                        <p className={`text-lg font-semibold ${color}`}>{value.toLocaleString()}</p>
                                        <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}

                    {/* STEP 4 — Done */}
                    {step === 'done' && (
                        <div className="space-y-5 py-4">
                            <div className="flex flex-col items-center gap-2 text-center">
                                {importError
                                    ? <XCircle className="h-10 w-10 text-red-500" />
                                    : cancelled
                                        ? <AlertTriangle className="h-10 w-10 text-amber-500" />
                                        : <CheckCircle2 className="h-10 w-10 text-green-500" />}
                                <h4 className="text-base font-semibold text-neutral-900 dark:text-neutral-100">
                                    {importError ? t('contacts_import.err_import_failed') : cancelled ? t('contacts_import.cancelled') : t('contacts_import.complete')}
                                </h4>
                                {importError && <p className="text-sm text-red-600 dark:text-red-400">{importError}</p>}
                            </div>

                            <div className="grid grid-cols-3 gap-3">
                                {[
                                    [t('contacts_import.created'), stats.created, 'text-green-600 dark:text-green-400', UserPlus],
                                    [t('contacts_import.updated'), stats.updated, 'text-blue-600 dark:text-blue-400', RefreshCw],
                                    [t('contacts_import.skipped'), stats.skipped, 'text-amber-600 dark:text-amber-400', Users],
                                ].map(([label, value, color, Icon]) => (
                                    <div key={label} className="rounded-xl border border-neutral-200 dark:border-neutral-700 px-4 py-4 text-center">
                                        <Icon className={`mx-auto mb-1.5 h-5 w-5 ${color}`} />
                                        <p className={`text-2xl font-semibold ${color}`}>{value.toLocaleString()}</p>
                                        <p className="text-xs text-neutral-500 dark:text-neutral-400">{label}</p>
                                    </div>
                                ))}
                            </div>

                            {stats.errors.length > 0 && (
                                <div className="rounded-lg border border-amber-200 dark:border-amber-800 overflow-hidden">
                                    <div className="bg-amber-50 dark:bg-amber-900/20 px-4 py-2 text-xs font-semibold text-amber-700 dark:text-amber-300">
                                        {t('contacts_import.errors_title', { count: stats.errors.length })}
                                    </div>
                                    <ul className="max-h-36 overflow-y-auto divide-y divide-neutral-100 dark:divide-neutral-800 text-xs">
                                        {stats.errors.map((err, i) => (
                                            <li key={i} className="px-4 py-1.5 text-neutral-600 dark:text-neutral-400">
                                                <span className="font-medium">{t('contacts_import.row_n', { n: err.row })}:</span> {err.message}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            )}
                        </div>
                    )}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-between gap-2 border-t border-neutral-200 dark:border-neutral-700 px-6 py-4">
                    <div>
                        {step === 'map' && (
                            <button type="button" onClick={() => { setStep('upload'); setImportError(null); }} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-3 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                <ArrowLeft className="h-4 w-4" /> {t('contacts_import.back')}
                            </button>
                        )}
                    </div>
                    <div className="flex gap-2">
                        {step === 'upload' && (
                            <button type="button" onClick={() => closeModal(false)} className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                {t('common.cancel')}
                            </button>
                        )}
                        {step === 'map' && (
                            <button
                                type="button"
                                onClick={startImport}
                                disabled={!identifierMapped}
                                className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-50 disabled:cursor-not-allowed transition"
                            >
                                {t('contacts_import.start_import', { count: dataRows.length })} <ArrowRight className="h-4 w-4" />
                            </button>
                        )}
                        {step === 'importing' && (
                            <button type="button" onClick={() => { cancelRef.current = true; }} className="rounded-lg border border-red-300 dark:border-red-700 px-4 py-2 text-sm text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition">
                                {t('contacts_import.cancel_import')}
                            </button>
                        )}
                        {step === 'done' && (
                            <>
                                <button type="button" onClick={resetAll} className="flex items-center gap-1.5 rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                    <Table2 className="h-4 w-4" /> {t('contacts_import.import_more')}
                                </button>
                                <button type="button" onClick={() => closeModal(true)} className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition">
                                    {t('contacts_import.done')}
                                </button>
                            </>
                        )}
                    </div>
                </div>
            </div>
        </div>
    );
}
