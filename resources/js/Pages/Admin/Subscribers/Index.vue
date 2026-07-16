<script setup>
import { Head, router } from "@inertiajs/vue3";
import { h, reactive, ref } from "vue";
import { actionsColumn, renderRowActions } from "@/lib/tableActions";
import LimitList from "@/components/admin/widgets/LimitList.vue";
import OAuthProviderBadge from "@/components/admin/OAuthProviderBadge.vue";
import { formatLimitLabel } from "@/utils/limitLabels";
import AdminLayout from "@/Layouts/AdminLayout.vue";
import PageHeader from "@/components/admin/PageHeader.vue";
import SubscribersSubnav from "@/components/admin/SubscribersSubnav.vue";
import DataTable from "@/components/DataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Select from "@/components/ui/Select.vue";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    subscribers: { type: Object, required: true },
    plans: { type: Array, default: () => [] },
    filters: { type: Object, required: true },
    searchMode: { type: Boolean, default: false },
});

const search = ref(props.filters.search ?? "");
const planId = ref(props.filters.plan_id ?? "");
const tooltip = reactive({ visible: false, x: 0, y: 0, subscription: null });

function hasLimits(obj) {
    return obj && Object.keys(obj).length > 0;
}

function countLimits(obj) {
    return obj ? Object.keys(obj).length : 0;
}

function limitsSummary(subscription) {
    if (!subscription) return "—";

    const planCount = countLimits(subscription.limits_plan);
    const monthCount = countLimits(subscription.limits_month);
    const extraCount = countLimits(subscription.extra_limits_month);

    if (!planCount && !monthCount && !extraCount) {
        return "Нет лимитов";
    }

    const parts = [];
    if (planCount) parts.push(`${planCount} план.`);
    if (monthCount) parts.push(`${monthCount} мес.`);
    if (extraCount) parts.push(`+${extraCount} доп.`);

    return parts.join(" / ");
}

function firstLimitPreview(subscription) {
    const subscriptionLimits = subscription?.limits_plan ?? {};
    const tariffLimits = subscription?.plan?.limits_plan ?? {};
    const firstEntry = Object.entries(subscriptionLimits)[0];
    if (!firstEntry) return null;

    const [key, value] = firstEntry;
    const tariffValue = tariffLimits[key];
    if (tariffValue !== undefined) {
        return `${formatLimitLabel(key)}: ${value} / ${tariffValue}`;
    }

    return `${formatLimitLabel(key)}: ${value}`;
}

function openTooltip(subscription, event) {
    const rect = event.currentTarget.getBoundingClientRect();
    tooltip.visible = true;
    tooltip.subscription = subscription;
    tooltip.x = rect.left + rect.width / 2;
    tooltip.y = rect.bottom + 8;
}

function closeTooltip() {
    tooltip.visible = false;
    tooltip.subscription = null;
}

const formatCurrency = (amount) => new Intl.NumberFormat("ru-RU", {
    style: "currency",
    currency: "RUB",
    maximumFractionDigits: 2,
}).format(Number(amount ?? 0));

const balanceValue = (balances) => {
    if (!balances) return 0;
    if (typeof balances === "object" && "value" in balances) return balances.value;
    return balances?.[0]?.value ?? 0;
};

const columns = [
    {
        accessorKey: "user.name",
        header: "Имя",
        cell: ({ row }) => {
            const user = row.original.user;
            return h("span", { class: "inline-flex items-center" }, [
                user?.name ?? "—",
                h(OAuthProviderBadge, {
                    vkId: user?.vk_id,
                    yandexId: user?.yandex_id,
                }),
            ]);
        },
    },
    {
        id: "contact",
        header: "Почта / Телефон",
        cell: ({ row }) => {
            const user = row.original.user;
            return h("div", { class: "text-xs" }, [
                h("div", user?.email ?? "—"),
                h("div", { class: "text-muted-foreground" }, user?.phone ?? "—"),
            ]);
        },
    },
    {
        id: "subscriptions",
        header: "Подписки",
        cell: ({ row }) => {
            const subs = row.original.subscriptions ?? [];
            if (!subs.length) return "Не выбран";
            return h("div", { class: "text-xs space-y-0.5" }, subs.map((s) =>
                h("div", `${s.plan?.name ?? "—"} до ${s.end_date ?? "—"}`)
            ));
        },
    },
    {
        id: "limits",
        header: "Лимиты",
        cell: ({ row }) => {
            const subscription = row.original.subscriptions?.[0];
            if (!subscription) return "—";

            const preview = firstLimitPreview(subscription);

            return h(
                "button",
                {
                    type: "button",
                    class: "text-left text-xs text-primary hover:underline",
                    onMouseenter: (event) => openTooltip(subscription, event),
                    onMouseleave: closeTooltip,
                    onFocus: (event) => openTooltip(subscription, event),
                    onBlur: closeTooltip,
                },
                [
                    h("div", limitsSummary(subscription)),
                    preview ? h("div", { class: "text-muted-foreground" }, preview) : null,
                ],
            );
        },
    },
    {
        id: "balance",
        header: "Баланс",
        cell: ({ row }) => formatCurrency(balanceValue(row.original.user?.balances)),
    },
    {
        ...actionsColumn,
        cell: ({ row }) => renderRowActions([
            { label: "Открыть", href: `/cw-page/subscribers/${row.original.id}` },
        ]),
    },
];

function applyFilters() {
    const params = {
        plan_id: planId.value || undefined,
        per_page: props.filters.per_page,
        sort_field: props.filters.sort_field,
        sort_order: props.filters.sort_order,
    };

    if (search.value && search.value.length >= 2) {
        router.get("/cw-page/subscribers/search", { q: search.value, ...params }, { preserveState: true });
        return;
    }

    router.get("/cw-page/subscribers", params, { preserveState: true });
}
</script>

<template>
    <Head title="Подписчики" />

    <AdminLayout
        title="Подписчики"
        :breadcrumbs="[{ label: 'Админка', href: '/cw-page' }, { label: 'Подписчики' }]"
    >
        <PageHeader
            title="Подписчики"
            :description="searchMode ? 'Результаты поиска' : 'Список подписчиков и их подписок'"
        />

        <SubscribersSubnav />

        <Card class="mb-4 p-4">
            <div class="grid gap-3 md:grid-cols-3">
                <div>
                    <label class="mb-1 block text-sm">Поиск</label>
                    <Input v-model="search" placeholder="Имя, email (мин. 2 символа)" @keyup.enter="applyFilters" />
                </div>
                <div>
                    <label class="mb-1 block text-sm">Тариф</label>
                    <Select v-model="planId">
                        <option value="">Все</option>
                        <option v-for="plan in plans" :key="plan.id" :value="plan.id">{{ plan.name }}</option>
                    </Select>
                </div>
                <div class="flex items-end">
                    <Button @click="applyFilters">Применить</Button>
                </div>
            </div>
        </Card>

        <Card class="p-4">
            <DataTable :columns="columns" :data="subscribers.data ?? []" />
            <div v-if="subscribers.last_page > 1" class="mt-4 flex items-center justify-between text-sm text-muted-foreground">
                <span>Страница {{ subscribers.current_page }} из {{ subscribers.last_page }} ({{ subscribers.total }})</span>
                <div class="flex gap-2">
                    <Button
                        v-if="subscribers.current_page > 1"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/subscribers', { ...filters, page: subscribers.current_page - 1 })"
                    >
                        Назад
                    </Button>
                    <Button
                        v-if="subscribers.current_page < subscribers.last_page"
                        variant="outline"
                        size="sm"
                        @click="router.get('/cw-page/subscribers', { ...filters, page: subscribers.current_page + 1 })"
                    >
                        Далее
                    </Button>
                </div>
            </div>
        </Card>

        <Teleport to="body">
            <div
                v-if="tooltip.visible && tooltip.subscription"
                class="pointer-events-none fixed z-50 w-72 max-w-[90vw] rounded-lg border bg-card p-3 text-sm shadow-lg"
                :style="{ left: `${tooltip.x}px`, top: `${tooltip.y}px`, transform: 'translateX(-50%)' }"
            >
                <div class="mb-1 font-semibold">Тарифные лимиты</div>
                <p class="mb-2 text-xs text-muted-foreground">остаток / по тарифу</p>
                <div v-if="hasLimits(tooltip.subscription.limits_plan) || hasLimits(tooltip.subscription.extra_limits_plan) || hasLimits(tooltip.subscription.plan?.limits_plan)">
                    <LimitList
                        :base="tooltip.subscription.limits_plan"
                        :extra="tooltip.subscription.extra_limits_plan"
                        :tariff="tooltip.subscription.plan?.limits_plan"
                    />
                </div>
                <div v-else class="mb-2 text-muted-foreground">Нет плановых лимитов</div>

                <div class="mb-1 font-semibold">Месячные лимиты</div>
                <p class="mb-2 text-xs text-muted-foreground">остаток / по тарифу + доп.</p>
                <div v-if="hasLimits(tooltip.subscription.limits_month) || hasLimits(tooltip.subscription.extra_limits_month) || hasLimits(tooltip.subscription.plan?.limits_month)">
                    <LimitList
                        :base="tooltip.subscription.limits_month"
                        :extra="tooltip.subscription.extra_limits_month"
                        :tariff="tooltip.subscription.plan?.limits_month"
                    />
                </div>
                <div v-else class="text-muted-foreground">Нет месячных лимитов</div>
            </div>
        </Teleport>
    </AdminLayout>
</template>