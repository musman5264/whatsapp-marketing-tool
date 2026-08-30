import { useState, useEffect } from 'react';
import { router } from '@inertiajs/react';
import { Dropdown } from '@/Components/ui';
import { ChevronUp, ChevronDown, MoreVertical, Pencil, Copy, Power, PowerOff, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

function formatPrice(cents, currency = 'USD') {
    if (cents == null) return '—';
    const amount = (cents || 0) / 100;
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency: currency || 'USD',
            minimumFractionDigits: 0,
            maximumFractionDigits: 0,
        }).format(amount);
    } catch {
        // Non-ISO / custom currency codes make Intl throw — fall back to code + amount.
        return `${currency || ''} ${amount.toLocaleString()}`.trim();
    }
}

function FeaturesSummary({ features = [], limits = {} }) {
    const displayItems = [];
    if (Array.isArray(features)) {
        features.forEach((f) => {
            if (f && String(f).trim()) displayItems.push(String(f).trim());
        });
    }
    const limitLabels = {
        users: 'Team',
        storage: 'Storage',
    };
    Object.entries(limits || {}).forEach(([key, val]) => {
        if (val != null && val !== '' && limitLabels[key]) {
            displayItems.push(`${limitLabels[key]}: ${val}`);
        }
    });
    const first3 = displayItems.slice(0, 3);
    const rest = displayItems.length - 3;
    return (
        <div className="text-sm text-neutral-600 dark:text-neutral-400">
            {first3.length === 0 && '—'}
            {first3.map((item, i) => (
                <div key={i}>{item}</div>
            ))}
            {rest > 0 && <div className="text-neutral-500 dark:text-neutral-500">+{rest} more</div>}
        </div>
    );
}

function StatusCell({ plan }) {
    const { t } = useTranslation();
    return (
        <div className="flex flex-wrap gap-1">
            <span
                className={`inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium ${
                    plan.enabled
                        ? 'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-300'
                        : 'bg-neutral-100 text-neutral-600 dark:bg-neutral-800 dark:text-neutral-400'
                }`}
            >
                {plan.enabled ? t('admin.active') : t('admin.disabled')}
            </span>
            {plan.popular && (
                <span className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/30 dark:text-amber-300">
                    {t('admin.popular')}
                </span>
            )}
            {plan.featured && (
                <span className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-medium bg-indigo-100 text-indigo-800 dark:bg-indigo-900/30 dark:text-indigo-300">
                    {t('admin.featured')}
                </span>
            )}
        </div>
    );
}

function PlanRow({ plan, index, total, onEdit, onDuplicate, onToggleEnabled, onDelete, onMoveUp, onMoveDown }) {
    const { t } = useTranslation();
    return (
        <tr className="border-b border-neutral-100 dark:border-neutral-800 hover:bg-gray-50 dark:hover:bg-neutral-800/50">
            <td className="py-3 px-4 w-16">
                <div className="flex flex-col gap-0.5">
                    <button
                        type="button"
                        disabled={index === 0}
                        onClick={() => onMoveUp(index)}
                        className="p-0.5 rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-700 disabled:opacity-30 disabled:cursor-not-allowed"
                        aria-label={t('admin.move_up')}
                    >
                        <ChevronUp className="h-4 w-4" />
                    </button>
                    <button
                        type="button"
                        disabled={index === total - 1}
                        onClick={() => onMoveDown(index)}
                        className="p-0.5 rounded text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-700 disabled:opacity-30 disabled:cursor-not-allowed"
                        aria-label={t('admin.move_down')}
                    >
                        <ChevronDown className="h-4 w-4" />
                    </button>
                </div>
            </td>
            <td className="py-3 px-4">
                <span className="font-medium text-neutral-900 dark:text-neutral-100">{plan.name}</span>
            </td>
            <td className="py-3 px-4 max-w-[180px]">
                <span className="text-sm text-neutral-600 dark:text-neutral-400 line-clamp-2">
                    {plan.description || '—'}
                </span>
            </td>
            <td className="py-3 px-4 text-sm text-neutral-700 dark:text-neutral-300 whitespace-nowrap">
                {formatPrice(plan.monthly_price_cents, plan.currency_code)}
            </td>
            <td className="py-3 px-4 text-sm text-neutral-700 dark:text-neutral-300 whitespace-nowrap">
                {plan.yearly_price_cents != null ? formatPrice(plan.yearly_price_cents, plan.currency_code) : '—'}
            </td>
            <td className="py-3 px-4">
                <FeaturesSummary features={plan.features} limits={plan.limits} />
            </td>
            <td className="py-3 px-4">
                <StatusCell plan={plan} />
            </td>
            <td className="py-3 px-4 text-right">
                <Dropdown>
                    <Dropdown.Trigger>
                        <button
                            type="button"
                            className="p-2 rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-neutral-700 dark:hover:bg-neutral-700 dark:hover:text-neutral-300 transition"
                            aria-label={t('admin.plan_actions')}
                        >
                            <MoreVertical className="h-4 w-4" />
                        </button>
                    </Dropdown.Trigger>
                    <Dropdown.Content align="right" width="56">
                        <Dropdown.Item onClick={() => onEdit(plan)}>
                            <Pencil className="h-4 w-4 mr-2 inline" />
                            {t('admin.edit_plan')}
                        </Dropdown.Item>
                        <Dropdown.Item onClick={() => onDuplicate(plan)}>
                            <Copy className="h-4 w-4 mr-2 inline" />
                            {t('admin.duplicate_plan')}
                        </Dropdown.Item>
                        <Dropdown.Item onClick={() => onToggleEnabled(plan)}>
                            {plan.enabled ? (
                                <>
                                    <PowerOff className="h-4 w-4 mr-2 inline" />
                                    {t('admin.disable_plan')}
                                </>
                            ) : (
                                <>
                                    <Power className="h-4 w-4 mr-2 inline" />
                                    {t('admin.enable_plan')}
                                </>
                            )}
                        </Dropdown.Item>
                        <Dropdown.Divider />
                        <Dropdown.Item
                            onClick={() => onDelete(plan)}
                            className="text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20"
                        >
                            <Trash2 className="h-4 w-4 mr-2 inline" />
                            {t('admin.delete_plan')}
                        </Dropdown.Item>
                    </Dropdown.Content>
                </Dropdown>
            </td>
        </tr>
    );
}

export default function PlanTable({
    plans = [],
    onEdit,
    onDuplicate,
    onToggleEnabled,
    onDelete,
    onReorder,
}) {
    const { t } = useTranslation();
    const idsKey = plans.map((p) => p.id).join(',');
    const [order, setOrder] = useState(() => plans.map((p) => p.id));
    useEffect(() => {
        setOrder(plans.map((p) => p.id));
    }, [idsKey]);

    const orderedPlans = order
        .map((id) => plans.find((p) => p.id === id))
        .filter(Boolean);

    const move = (fromIndex, toIndex) => {
        if (toIndex < 0 || toIndex >= order.length) return;
        const newOrder = [...order];
        const [item] = newOrder.splice(fromIndex, 1);
        newOrder.splice(toIndex, 0, item);
        setOrder(newOrder);
        onReorder(newOrder);
    };

    return (
        <div className="bg-white dark:bg-neutral-900 rounded-xl shadow-sm border border-neutral-200 dark:border-neutral-800 overflow-hidden">
            <div className="overflow-x-auto">
                <table className="min-w-full text-sm">
                    <thead>
                        <tr className="border-b border-neutral-200 dark:border-neutral-700 bg-neutral-50 dark:bg-neutral-800/50 text-left text-neutral-500 dark:text-neutral-400">
                            <th className="py-3 px-4 w-16 font-medium">{t('admin.col_order').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.plan_name').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.col_description').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.col_monthly').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.col_yearly').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.col_features').toUpperCase()}</th>
                            <th className="py-3 px-4 font-medium">{t('admin.col_status').toUpperCase()}</th>
                            <th className="py-3 px-4 text-right font-medium">{t('admin.plan_actions').toUpperCase()}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {orderedPlans.map((plan, index) => (
                            <PlanRow
                                key={plan.id}
                                plan={plan}
                                index={index}
                                total={orderedPlans.length}
                                onEdit={onEdit}
                                onDuplicate={onDuplicate}
                                onToggleEnabled={onToggleEnabled}
                                onDelete={onDelete}
                                onMoveUp={() => move(index, index - 1)}
                                onMoveDown={() => move(index, index + 1)}
                            />
                        ))}
                    </tbody>
                </table>
            </div>
            {plans.length === 0 && (
                <div className="py-12 text-center text-neutral-500 dark:text-neutral-400">
                    {t('admin.no_plans_found')}
                </div>
            )}
        </div>
    );
}

export { formatPrice, FeaturesSummary, StatusCell };
