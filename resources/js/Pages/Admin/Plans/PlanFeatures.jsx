import { Button, Input } from '@/Components/ui';
import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

export default function PlanFeatures({ features = [], onChange }) {
    const { t } = useTranslation();
    const list = Array.isArray(features) ? [...features] : [];

    const add = () => {
        onChange([...list, '']);
    };

    const remove = (index) => {
        onChange(list.filter((_, i) => i !== index));
    };

    const update = (index, text) => {
        const next = [...list];
        next[index] = text;
        onChange(next);
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between">
                <span className="text-sm font-medium text-neutral-700 dark:text-neutral-300">{t('admin.feature_list')}</span>
                <Button type="button" variant="outline" size="sm" onClick={add}>
                    <Plus className="h-4 w-4 mr-1" />
                    {t('common.add')}
                </Button>
            </div>
            <p className="text-xs text-neutral-500 dark:text-neutral-400">
                {t('admin.feature_list_hint')}
            </p>
            <div className="space-y-2">
                {list.map((text, index) => (
                    <div key={index} className="flex gap-2 items-center">
                        <Input
                            value={text}
                            onChange={(e) => update(index, e.target.value)}
                            placeholder={t('admin.feature_description')}
                            className="flex-1"
                        />
                        <button
                            type="button"
                            onClick={() => remove(index)}
                            className="p-2 rounded-lg text-neutral-500 hover:bg-neutral-100 hover:text-red-600 dark:hover:bg-neutral-800 dark:hover:text-red-400 transition"
                            aria-label={t('admin.remove_feature')}
                        >
                            <Trash2 className="h-4 w-4" />
                        </button>
                    </div>
                ))}
                {list.length === 0 && (
                    <p className="text-sm text-neutral-500 dark:text-neutral-400 py-2">{t('admin.no_features_added')}</p>
                )}
            </div>
        </div>
    );
}
