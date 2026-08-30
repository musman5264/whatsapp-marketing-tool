import { useState, useEffect } from 'react';
import AdminLayout from '@/Layouts/AdminLayout';
import { Head, usePage, router } from '@inertiajs/react';
import { Button, Modal } from '@/Components/ui';
import PlanTable from './PlanTable';
import PlanModal from './PlanModal';
import { useTranslation } from 'react-i18next';

function Toast({ message, onDismiss }) {
    useEffect(() => {
        if (!message) return;
        const timer = setTimeout(onDismiss, 4000);
        return () => clearTimeout(timer);
    }, [message, onDismiss]);

    if (!message) return null;
    return (
        <div
            className="fixed bottom-4 right-4 z-50 flex items-center gap-3 rounded-lg bg-neutral-900 dark:bg-neutral-800 text-white px-4 py-3 shadow-lg border border-neutral-700 transition-opacity duration-200"
            role="alert"
        >
            <span className="text-sm font-medium">{message}</span>
        </div>
    );
}

function DeleteConfirmModal({ show, plan, onClose, onConfirm }) {
    const { t } = useTranslation();
    if (!plan) return null;
    return (
        <Modal show={show} onClose={onClose} maxWidth="sm">
            <Modal.Header title={t('admin.delete_plan')} onClose={onClose} />
            <Modal.Body>
                <p className="text-neutral-600 dark:text-neutral-400">
                    {t('admin.confirm_delete_plan', { name: plan.name })}
                </p>
                <p className="mt-2 font-medium text-neutral-900 dark:text-neutral-100">{plan.name}</p>
            </Modal.Body>
            <Modal.Footer>
                <Button variant="outline" onClick={onClose}>
                    {t('common.cancel')}
                </Button>
                <Button
                    variant="primary"
                    className="bg-red-600 hover:bg-red-700 text-white"
                    onClick={() => onConfirm(plan)}
                >
                    {t('common.delete')}
                </Button>
            </Modal.Footer>
        </Modal>
    );
}

export default function AdminPlansIndex({ plans = [], currencies = [], defaultCurrency = 'USD' }) {
    const { t } = useTranslation();
    const { flash } = usePage().props;
    const openEditPlanId = flash?.openEditPlanId ?? null;

    const [toast, setToast] = useState(flash?.success ?? '');
    const [modalOpen, setModalOpen] = useState(false);
    const [editPlan, setEditPlan] = useState(null);
    const [deletePlan, setDeletePlan] = useState(null);

    useEffect(() => {
        if (flash?.success) setToast(flash.success);
    }, [flash?.success]);

    useEffect(() => {
        if (openEditPlanId && plans.length) {
            const plan = plans.find((p) => p.id === openEditPlanId);
            if (plan) {
                setEditPlan(plan);
                setModalOpen(true);
            }
        }
    }, [openEditPlanId, plans]);

    const handleEdit = (plan) => {
        setEditPlan(plan);
        setModalOpen(true);
    };

    const handleAdd = () => {
        setEditPlan(null);
        setModalOpen(true);
    };

    const handleCloseModal = () => {
        setModalOpen(false);
        setEditPlan(null);
    };

    const handleDuplicate = (plan) => {
        router.post(route('admin.plans.duplicate', plan.id), {}, {
            preserveScroll: true,
        });
    };

    const handleToggleEnabled = (plan) => {
        router.put(route('admin.plans.update', plan.id), {
            ...plan,
            enabled: !plan.enabled,
            limits: plan.limits ?? {},
            features: plan.features ?? [],
        }, {
            preserveScroll: true,
            onSuccess: () => setToast(plan.enabled ? 'Plan disabled.' : 'Plan enabled.'),
        });
    };

    const handleDeleteClick = (plan) => {
        setDeletePlan(plan);
    };

    const handleDeleteConfirm = (plan) => {
        router.delete(route('admin.plans.destroy', plan.id), {
            preserveScroll: true,
            onSuccess: () => {
                setDeletePlan(null);
            },
        });
    };

    const handleReorder = (newOrder) => {
        router.post(route('admin.plans.reorder'), { order: newOrder }, {
            preserveScroll: true,
        });
    };

    return (
        <AdminLayout title={t('admin.price_plans')}>
            <Head title={`${t('admin.price_plans')} · Admin`} />
            <div className="space-y-6">
                {/* Section 1 — Header */}
                <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold text-neutral-900 dark:text-neutral-100">
                            {t('admin.price_plans')}
                        </h1>
                        <p className="mt-0.5 text-sm text-neutral-500 dark:text-neutral-400">
                            {t('admin.plans_desc')}
                        </p>
                    </div>
                    <div>
                        <Button
                            onClick={handleAdd}
                            className="bg-indigo-600 hover:bg-indigo-700 text-white px-4 py-2 rounded-lg shadow-sm"
                        >
                            + {t('admin.add_plan')}
                        </Button>
                    </div>
                </div>

                {/* Section 2 — Table */}
                <PlanTable
                    plans={plans}
                    onEdit={handleEdit}
                    onDuplicate={handleDuplicate}
                    onToggleEnabled={handleToggleEnabled}
                    onDelete={handleDeleteClick}
                    onReorder={handleReorder}
                />

                <PlanModal key={editPlan?.id ?? 'new'} show={modalOpen} onClose={handleCloseModal} plan={editPlan} currencies={currencies} defaultCurrency={defaultCurrency} />

                <DeleteConfirmModal
                    show={!!deletePlan}
                    plan={deletePlan}
                    onClose={() => setDeletePlan(null)}
                    onConfirm={handleDeleteConfirm}
                />
            </div>

            <Toast message={toast} onDismiss={() => setToast('')} />
        </AdminLayout>
    );
}
