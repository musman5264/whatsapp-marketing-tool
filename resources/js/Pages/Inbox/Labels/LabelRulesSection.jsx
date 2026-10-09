import { router } from '@inertiajs/react';
import { useState } from 'react';
import { Plus, Pencil, Trash2, X, Check } from 'lucide-react';

const MATCH_TYPES = [
    { value: 'keyword_any', label: 'Message contains any keyword', needsPattern: true },
    { value: 'keyword_all', label: 'Message contains all keywords', needsPattern: true },
    { value: 'regex', label: 'Message matches regular expression', needsPattern: true },
    { value: 'first_message', label: 'First message in a conversation', needsPattern: false },
    { value: 'new_contact', label: 'First message from a new contact', needsPattern: false },
];

const EMPTY = {
    name: '',
    inbox_label_id: '',
    channel_account_id: '',
    match_type: 'keyword_any',
    pattern: '',
    is_active: true,
    priority: 100,
};

const inputClass = 'w-full rounded-lg border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-800 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-brand-500';

export default function LabelRulesSection({ rules = [], labels = [], channelAccounts = [] }) {
    const [showForm, setShowForm] = useState(false);
    const [editing, setEditing] = useState(null);
    const [form, setForm] = useState(EMPTY);
    const [errors, setErrors] = useState({});
    const [busy, setBusy] = useState(false);

    const matchType = MATCH_TYPES.find((m) => m.value === form.match_type);

    const reset = () => {
        setForm(EMPTY);
        setErrors({});
        setEditing(null);
        setShowForm(false);
    };

    const openCreate = () => {
        setEditing(null);
        setForm({ ...EMPTY, inbox_label_id: labels[0]?.id ?? '' });
        setErrors({});
        setShowForm(true);
    };

    const openEdit = (rule) => {
        setEditing(rule);
        setForm({
            name: rule.name,
            inbox_label_id: rule.inbox_label_id,
            channel_account_id: rule.channel_account_id ?? '',
            match_type: rule.match_type,
            pattern: rule.pattern ?? '',
            is_active: rule.is_active,
            priority: rule.priority,
        });
        setErrors({});
        setShowForm(true);
    };

    const submit = (e) => {
        e.preventDefault();
        setBusy(true);
        const payload = { ...form, channel_account_id: form.channel_account_id || null };
        const opts = {
            preserveScroll: true,
            onSuccess: reset,
            onError: (err) => setErrors(err),
            onFinish: () => setBusy(false),
        };
        if (editing) {
            router.put(route('client.inbox.labels.rules.update', editing.id), payload, opts);
        } else {
            router.post(route('client.inbox.labels.rules.store'), payload, opts);
        }
    };

    const toggleActive = (rule) => {
        router.put(
            route('client.inbox.labels.rules.update', rule.id),
            {
                name: rule.name,
                inbox_label_id: rule.inbox_label_id,
                channel_account_id: rule.channel_account_id ?? null,
                match_type: rule.match_type,
                pattern: rule.pattern ?? '',
                is_active: !rule.is_active,
                priority: rule.priority,
            },
            { preserveScroll: true },
        );
    };

    const destroy = (rule) => {
        if (!confirm(`Delete rule "${rule.name}"?`)) return;
        router.delete(route('client.inbox.labels.rules.destroy', rule.id), { preserveScroll: true });
    };

    const noLabels = labels.length === 0;

    return (
        <section className="space-y-4">
            <div className="flex items-center justify-between">
                <div>
                    <h2 className="text-lg font-semibold text-neutral-900 dark:text-neutral-100">Auto-label rules</h2>
                    <p className="text-sm text-neutral-500 mt-1">
                        Apply a label automatically when an incoming message matches. Keywords are case-insensitive and work in Arabic and Urdu too.
                    </p>
                </div>
                <button
                    type="button"
                    onClick={openCreate}
                    disabled={noLabels}
                    className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition disabled:opacity-50"
                >
                    <Plus className="h-4 w-4" /> New rule
                </button>
            </div>

            {noLabels && (
                <p className="text-xs text-neutral-500">Create a label first, then add a rule that applies it.</p>
            )}

            {showForm && (
                <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 p-5 shadow-sm">
                    <div className="flex items-center justify-between mb-4">
                        <h3 className="font-semibold text-neutral-900 dark:text-neutral-100">{editing ? 'Edit rule' : 'New rule'}</h3>
                        <button type="button" onClick={reset} className="text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200">
                            <X className="h-4 w-4" />
                        </button>
                    </div>
                    <form onSubmit={submit} className="space-y-4">
                        <div>
                            <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Rule name</label>
                            <input value={form.name} onChange={(e) => setForm((f) => ({ ...f, name: e.target.value }))} className={inputClass} />
                            {errors.name && <p className="text-red-500 text-xs mt-1">{errors.name}</p>}
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Apply label</label>
                                <select value={form.inbox_label_id} onChange={(e) => setForm((f) => ({ ...f, inbox_label_id: e.target.value }))} className={inputClass}>
                                    {labels.map((l) => (
                                        <option key={l.id} value={l.id}>{l.name}</option>
                                    ))}
                                </select>
                                {errors.inbox_label_id && <p className="text-red-500 text-xs mt-1">{errors.inbox_label_id}</p>}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Channel</label>
                                <select value={form.channel_account_id} onChange={(e) => setForm((f) => ({ ...f, channel_account_id: e.target.value }))} className={inputClass}>
                                    <option value="">All channels</option>
                                    {channelAccounts.map((c) => (
                                        <option key={c.id} value={c.id}>{c.display_name || `${c.channel} #${c.id}`}</option>
                                    ))}
                                </select>
                                {errors.channel_account_id && <p className="text-red-500 text-xs mt-1">{errors.channel_account_id}</p>}
                            </div>
                        </div>

                        <div className="grid gap-4 sm:grid-cols-2">
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">When</label>
                                <select value={form.match_type} onChange={(e) => setForm((f) => ({ ...f, match_type: e.target.value }))} className={inputClass}>
                                    {MATCH_TYPES.map((m) => (
                                        <option key={m.value} value={m.value}>{m.label}</option>
                                    ))}
                                </select>
                                {errors.match_type && <p className="text-red-500 text-xs mt-1">{errors.match_type}</p>}
                            </div>
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">Priority</label>
                                <input type="number" min={0} max={1000} value={form.priority} onChange={(e) => setForm((f) => ({ ...f, priority: Number(e.target.value) }))} className={inputClass} />
                                <p className="text-[11px] text-neutral-400 mt-1">Lower numbers run first.</p>
                                {errors.priority && <p className="text-red-500 text-xs mt-1">{errors.priority}</p>}
                            </div>
                        </div>

                        {matchType?.needsPattern && (
                            <div>
                                <label className="block text-sm font-medium text-neutral-700 dark:text-neutral-300 mb-1">
                                    {form.match_type === 'regex' ? 'Regular expression' : 'Keywords'}
                                </label>
                                <textarea
                                    rows={2}
                                    dir="auto"
                                    value={form.pattern}
                                    onChange={(e) => setForm((f) => ({ ...f, pattern: e.target.value }))}
                                    placeholder={form.match_type === 'regex' ? '/order\\s*#?\\d+/' : 'price, pricing, قیمت'}
                                    className={inputClass}
                                />
                                <p className="text-[11px] text-neutral-400 mt-1">
                                    {form.match_type === 'regex'
                                        ? 'Case-insensitive. Wrap in slashes to set flags yourself, e.g. /abc/u.'
                                        : 'Separate keywords with commas or new lines.'}
                                </p>
                                {errors.pattern && <p className="text-red-500 text-xs mt-1">{errors.pattern}</p>}
                            </div>
                        )}

                        <label className="flex items-center gap-2 text-sm text-neutral-700 dark:text-neutral-300">
                            <input type="checkbox" checked={form.is_active} onChange={(e) => setForm((f) => ({ ...f, is_active: e.target.checked }))} />
                            Rule is active
                        </label>

                        <div className="flex gap-2 justify-end">
                            <button type="button" onClick={reset} className="rounded-lg border border-neutral-200 dark:border-neutral-700 px-4 py-2 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 transition">
                                Cancel
                            </button>
                            <button type="submit" disabled={busy} className="flex items-center gap-1.5 rounded-lg bg-brand-600 px-4 py-2 text-sm font-medium text-white hover:bg-brand-700 transition disabled:opacity-50">
                                <Check className="h-4 w-4" /> Save
                            </button>
                        </div>
                    </form>
                </div>
            )}

            <div className="rounded-xl border border-neutral-200 dark:border-neutral-700 bg-white dark:bg-neutral-900 divide-y divide-neutral-100 dark:divide-neutral-800">
                {rules.length === 0 ? (
                    <p className="p-6 text-center text-sm text-neutral-500">No rules yet.</p>
                ) : (
                    rules.map((rule) => (
                        <div key={rule.id} className="flex items-center gap-4 px-5 py-3">
                            <span className="h-3 w-3 rounded-full shrink-0" style={{ backgroundColor: rule.label?.color ?? '#94a3b8' }} />
                            <div className="flex-1 min-w-0">
                                <p className="text-sm font-medium text-neutral-800 dark:text-neutral-200 truncate">
                                    {rule.name} <span className="text-neutral-400 font-normal">→ {rule.label?.name ?? 'deleted label'}</span>
                                </p>
                                <p className="text-xs text-neutral-500 truncate" dir="auto">
                                    {MATCH_TYPES.find((m) => m.value === rule.match_type)?.label ?? rule.match_type}
                                    {rule.pattern ? `: ${rule.pattern}` : ''}
                                    {rule.channel_account_id ? ` · ${rule.channel_account?.display_name ?? 'channel'}` : ' · all channels'}
                                    {` · ${rule.hits} hits`}
                                </p>
                            </div>
                            <button
                                type="button"
                                role="switch"
                                aria-checked={rule.is_active}
                                onClick={() => toggleActive(rule)}
                                className={`relative inline-flex h-5 w-9 shrink-0 items-center rounded-full transition ${rule.is_active ? 'bg-emerald-500' : 'bg-neutral-300 dark:bg-neutral-600'}`}
                            >
                                <span className={`inline-block h-4 w-4 transform rounded-full bg-white shadow transition ${rule.is_active ? 'translate-x-4' : 'translate-x-0.5'}`} />
                            </button>
                            <div className="flex items-center gap-1.5">
                                <button type="button" onClick={() => openEdit(rule)} className="rounded p-1.5 hover:bg-neutral-100 dark:hover:bg-neutral-800 text-neutral-400 hover:text-neutral-600 dark:hover:text-neutral-200 transition">
                                    <Pencil className="h-3.5 w-3.5" />
                                </button>
                                <button type="button" onClick={() => destroy(rule)} className="rounded p-1.5 hover:bg-red-50 dark:hover:bg-red-900/20 text-neutral-400 hover:text-red-500 transition">
                                    <Trash2 className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        </div>
                    ))
                )}
            </div>
        </section>
    );
}
