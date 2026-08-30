import { Head, Link, router, usePage } from '@inertiajs/react';
import ClientLayout from '@/Layouts/ClientLayout';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Handsontable from 'handsontable';
import 'handsontable/styles/handsontable.min.css';
import 'handsontable/styles/ht-theme-main.min.css';
import { ArrowLeft, FileDown } from 'lucide-react';
import { downloadSampleWorkbook, emptyMatrix, matrixToPayload, parseWorkbookToMatrix } from '@/Pages/Contacts/bulkImportExcel';
import { useTranslation, Trans } from 'react-i18next';
import i18n from '@/i18n';

const DEFAULT_ROWS = 15;
const HOT_LICENSE_KEY =
    typeof import.meta !== 'undefined' && import.meta.env?.VITE_HANDSONTABLE_LICENSE_KEY
        ? import.meta.env.VITE_HANDSONTABLE_LICENSE_KEY
        : 'non-commercial-and-evaluation';

function buildHotSettings(tags, segments) {
    const tagSource = ['', ...tags.map((t) => t.name)];
    const segSource = ['', ...segments.map((s) => s.name)];

    return {
        data: emptyMatrix(DEFAULT_ROWS),
        colHeaders: [
            i18n.t('common.name'),
            i18n.t('contacts_page.phone_e164'),
            i18n.t('contacts_page.bulk_col_contact_list'),
            i18n.t('contacts_page.bulk_col_segment'),
        ],
        columns: [
            { type: 'text', className: 'htLeft' },
            { type: 'text', className: 'htLeft' },
            {
                type: 'dropdown',
                source: tagSource,
                strict: false,
                allowInvalid: true,
                className: 'htLeft',
            },
            {
                type: 'dropdown',
                source: segSource,
                strict: false,
                allowInvalid: true,
                className: 'htLeft',
            },
        ],
        rowHeaders: true,
        stretchH: 'all',
        height: 460,
        licenseKey: HOT_LICENSE_KEY,
        minSpareRows: 3,
        contextMenu: ['row_above', 'row_below', 'remove_row', '---------', 'copy', 'cut'],
        manualColumnResize: true,
        manualRowResize: true,
        filters: true,
        dropdownMenu: true,
        copyPaste: true,
        fillHandle: { direction: 'vertical' },
        undo: true,
        outsideClickDeselects: false,
        autoWrapRow: true,
        autoWrapCol: false,
    };
}

export default function ContactsBulkImport({ tags, segments }) {
    const { t } = useTranslation();
    const { props } = usePage();
    const flash = props.flash ?? {};
    const pageErrors = props.errors ?? {};

    const [parsingError, setParsingError] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const fileRef = useRef(null);
    const containerRef = useRef(null);
    const hotRef = useRef(null);

    const tagSig = useMemo(() => tags.map((t) => `${t.id}:${t.name}`).join('|'), [tags]);
    const segSig = useMemo(() => segments.map((s) => `${s.id}:${s.name}`).join('|'), [segments]);

    useEffect(() => {
        const el = containerRef.current;
        if (!el) {
            return undefined;
        }
        el.classList.add('ht-theme-main');
        const hot = new Handsontable(el, buildHotSettings(tags, segments));
        hotRef.current = hot;
        return () => {
            hot.destroy();
            hotRef.current = null;
        };
        // tagSig / segSig reflect tag & segment lists without re-running on unrelated parent re-renders.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tagSig, segSig]);

    const resetTable = useCallback(() => {
        setParsingError('');
        if (fileRef.current) {
            fileRef.current.value = '';
        }
        hotRef.current?.loadData(emptyMatrix(DEFAULT_ROWS));
    }, []);

    const downloadSample = useCallback(() => {
        downloadSampleWorkbook();
    }, []);

    const onFile = useCallback(
        async (e) => {
            const file = e.target.files?.[0];
            setParsingError('');
            if (!file) {
                return;
            }
            if (!/\.xlsx$/i.test(file.name)) {
                setParsingError(t('contacts_page.bulk_err_xlsx_only'));
                return;
            }
            try {
                const buf = await file.arrayBuffer();
                const parsed = await parseWorkbookToMatrix(buf, tags, segments);
                if (parsed === null) {
                    setParsingError(t('contacts_page.bulk_err_no_phone_column'));
                    return;
                }
                const minRows = Math.max(parsed.length, DEFAULT_ROWS);
                const padded =
                    parsed.length >= minRows
                        ? parsed
                        : [...parsed, ...emptyMatrix(minRows - parsed.length)];
                hotRef.current?.loadData(padded);
            } catch {
                setParsingError(t('contacts_page.bulk_err_unreadable'));
            }
        },
        [tags, segments, t],
    );

    const confirmUpload = () => {
        const hot = hotRef.current;
        if (!hot) {
            return;
        }
        setSubmitting(true);
        const data = hot.getData();
        const payload = matrixToPayload(data, tags, segments);
        router.post(route('client.contacts.bulk-store'), { rows: payload }, {
            preserveScroll: true,
            preserveState: true,
            onFinish: () => setSubmitting(false),
        });
    };

    const addRows = () => {
        const hot = hotRef.current;
        if (!hot) {
            return;
        }
        const cur = hot.getData();
        hot.loadData([...cur, ...emptyMatrix(10)]);
    };

    return (
        <ClientLayout title={t('contacts_page.bulk_import_title')}>
            <Head title={t('contacts_page.bulk_import_title')} />
            <div className="space-y-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <div className="flex items-center gap-3">
                        <Link
                            href={route('client.contacts.index')}
                            className="inline-flex items-center gap-1 rounded-lg border border-neutral-300 dark:border-neutral-600 px-2.5 py-1.5 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800"
                        >
                            <ArrowLeft className="h-4 w-4" />
                            {t('contacts_page.title')}
                        </Link>
                        <h2 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">{t('contacts_page.bulk_import_title')}</h2>
                    </div>
                </div>

                {flash.success && (
                    <div className="rounded-lg bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 px-4 py-2 text-sm">{flash.success}</div>
                )}
                {pageErrors.rows && (
                    <div className="rounded-lg bg-red-50 dark:bg-red-900/30 text-red-800 dark:text-red-200 px-4 py-2 text-sm">{pageErrors.rows}</div>
                )}

                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 space-y-4">
                    <div>
                        <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('contacts_page.bulk_choose_file')}</label>
                        <input
                            ref={fileRef}
                            type="file"
                            accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                            onChange={onFile}
                            className="mt-1 block w-full text-sm text-neutral-600 dark:text-neutral-400 file:mr-3 file:rounded-lg file:border-0 file:bg-brand-50 file:px-3 file:py-2 file:text-sm file:font-medium file:text-brand-700 hover:file:bg-brand-100 dark:file:bg-brand-900/30 dark:file:text-brand-200"
                        />
                        <p className="mt-2 text-sm text-red-600 dark:text-red-400">
                            <Trans
                                i18nKey="contacts_page.bulk_file_hint"
                                components={{
                                    b: <strong />,
                                    code: <code className="rounded bg-neutral-100 dark:bg-neutral-800 px-1" />,
                                    sample: (
                                        <button type="button" onClick={downloadSample} className="font-medium text-brand-600 underline hover:text-brand-700" />
                                    ),
                                }}
                            />
                        </p>
                        {parsingError && <p className="mt-2 text-sm text-red-600 dark:text-red-400">{parsingError}</p>}
                    </div>

                    <div className="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                        <div ref={containerRef} className="ht-wrapper min-h-[200px]" />
                    </div>

                    <div className="flex flex-wrap gap-2">
                        {(
                            <button
                                type="button"
                                disabled={submitting}
                                onClick={confirmUpload}
                                className="rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 disabled:opacity-60"
                            >
                                {submitting ? t('contacts_page.bulk_uploading') : t('contacts_page.bulk_confirm_upload')}
                            </button>
                        )}
                        <button
                            type="button"
                            onClick={resetTable}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-800 dark:text-neutral-200 hover:bg-neutral-50 dark:hover:bg-neutral-800"
                        >
                            {t('contacts_page.bulk_reset_table')}
                        </button>
                        <button
                            type="button"
                            onClick={downloadSample}
                            className="inline-flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700"
                        >
                            <FileDown className="h-4 w-4" />
                            {t('contacts_page.bulk_sample_download')}
                        </button>
                        <button
                            type="button"
                            onClick={addRows}
                            className="rounded-lg border border-neutral-300 dark:border-neutral-600 px-4 py-2 text-sm text-neutral-700 dark:text-neutral-300 hover:bg-neutral-50 dark:hover:bg-neutral-800"
                        >
                            {t('contacts_page.bulk_add_rows')}
                        </button>
                    </div>

                </div>
            </div>
        </ClientLayout>
    );
}
