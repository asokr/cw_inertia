<script setup>
import { Head, router, useForm } from "@inertiajs/vue3";
import { computed, ref } from "vue";
import PlanCard from "@/components/subscriber/plans/PlanCard.vue";
import PlanChangeConfirmDialog from "@/components/subscriber/plans/PlanChangeConfirmDialog.vue";
import PlansDowngradeBanner from "@/components/subscriber/plans/PlansDowngradeBanner.vue";
import PlansHero from "@/components/subscriber/plans/PlansHero.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const props = defineProps({
    plans: { type: Array, default: () => [] },
    subscriptionData: { type: Object, default: null },
    nextActions: { type: Array, default: () => [] },
    pendingDowngrade: { type: Object, default: null },
});

const { balance } = useSubscriberContext();
const processingPlanId = ref(null);
const confirmOpen = ref(false);
const pendingPlan = ref(null);

const depositForm = useForm({
    amount: "",
    plan_id: null,
});

const subscription = computed(() => props.subscriptionData?.subscription ?? null);
const currentPlan = computed(() => props.subscriptionData?.plan ?? null);
const hasStopAction = computed(() => props.nextActions?.some((item) => item.action === "STOP"));

const currentPlanName = computed(() => currentPlan.value?.name ?? null);

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(balance.value ?? 0)
    )
);

function finishPlanAction() {
    processingPlanId.value = null;
    confirmOpen.value = false;
    pendingPlan.value = null;
}

function changePlan(planId) {
    processingPlanId.value = planId;
    router.post(
        "/panel/user/change-plan",
        { plan_id: planId },
        {
            preserveScroll: true,
            onFinish: finishPlanAction,
        }
    );
}

function depositForPlan(plan) {
    const shortfall = Math.max(0, Math.ceil(Number(plan.price) - Number(balance.value ?? 0)));

    depositForm.amount = String(shortfall);
    depositForm.plan_id = plan.id;
    processingPlanId.value = plan.id;

    depositForm.post("/panel/payments/deposit", {
        preserveScroll: true,
        onFinish: finishPlanAction,
    });
}

function selectPlan(plan) {
    if (hasStopAction.value) {
        return;
    }

    pendingPlan.value = plan;
    confirmOpen.value = true;
}

function confirmPlanChange() {
    const plan = pendingPlan.value;

    if (!plan) {
        return;
    }

    const shortfall = Math.max(0, Math.ceil(Number(plan.price) - Number(balance.value ?? 0)));

    if (shortfall > 0) {
        depositForPlan(plan);
        return;
    }

    changePlan(plan.id);
}

function cancelScheduledDowngrade(plan) {
    processingPlanId.value = plan.id;
    router.post(
        "/panel/user/cancel-downgrade",
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                processingPlanId.value = null;
            },
        }
    );
}

function closeConfirmDialog(open) {
    if (processingPlanId.value !== null) {
        return;
    }

    confirmOpen.value = open;

    if (!open) {
        pendingPlan.value = null;
    }
}
</script>

<template>
    <Head title="Тарифы" />

    <SubscriberLayout
        title="Тарифы"
        :breadcrumbs="[{ label: 'Панель', href: '/panel' }, { label: 'Тарифы' }]"
    >
        <div class="plans-page mx-auto max-w-6xl space-y-8">
            <PlansHero
                class="subscriber-cabinet__fade subscriber-cabinet__fade--1"
                :current-plan-name="currentPlanName"
            />

            <PlansDowngradeBanner
                v-if="pendingDowngrade"
                class="subscriber-cabinet__fade subscriber-cabinet__fade--2"
                :pending-downgrade="pendingDowngrade"
                :loading="processingPlanId === pendingDowngrade.plan_id"
                @cancel="cancelScheduledDowngrade({ id: pendingDowngrade.plan_id })"
            />

            <div
                class="subscriber-cabinet__fade subscriber-cabinet__fade--3 flex flex-wrap items-center justify-between gap-3 rounded-xl border border-border/70 bg-card/80 px-4 py-3 text-sm backdrop-blur"
            >
                <span class="text-muted-foreground">
                    Баланс:
                    <strong class="tabular-nums text-foreground">{{ formattedBalance }}</strong>
                </span>
                <span v-if="hasStopAction" class="text-destructive">
                    Подписка отменена — смена тарифа недоступна до возобновления
                </span>
            </div>

            <div v-if="plans.length" class="subscriber-cabinet__fade subscriber-cabinet__fade--4 grid gap-6 md:grid-cols-2 xl:grid-cols-3">
                <PlanCard
                    v-for="plan in plans"
                    :key="plan.id"
                    :plan="plan"
                    :balance="Number(balance ?? 0)"
                    :disabled="hasStopAction"
                    :loading="processingPlanId === plan.id"
                    @select="selectPlan"
                    @cancel="cancelScheduledDowngrade"
                />
            </div>

            <p v-else class="rounded-xl border border-dashed border-border/70 px-6 py-12 text-center text-muted-foreground">
                Сейчас нет доступных тарифов. Обратитесь в поддержку.
            </p>

            <section class="subscriber-cabinet__fade subscriber-cabinet__fade--5 rounded-2xl border border-border/70 bg-card/60 px-6 py-8 text-center backdrop-blur">
                <h2 class="text-lg font-semibold">Мгновенная активация</h2>
                <p class="mx-auto mt-2 max-w-xl text-sm text-muted-foreground">
                    Оплата списывается с баланса кабинета. Если средств не хватает — пополните на недостающую сумму,
                    тариф активируется автоматически после оплаты.
                </p>
            </section>
        </div>

        <PlanChangeConfirmDialog
            :open="confirmOpen"
            :plan="pendingPlan"
            :current-plan-name="currentPlanName"
            :balance="Number(balance ?? 0)"
            :loading="pendingPlan !== null && processingPlanId === pendingPlan?.id"
            @update:open="closeConfirmDialog"
            @confirm="confirmPlanChange"
        />
    </SubscriberLayout>
</template>

<style scoped>
.plans-page {
    position: relative;
    font-feature-settings: "kern" 1, "liga" 1;
}

.plans-page::before {
    content: "";
    position: absolute;
    top: -1.5rem;
    right: 0;
    left: 0;
    z-index: 0;
    height: 14rem;
    pointer-events: none;
    background: radial-gradient(
        ellipse 90% 100% at 50% -10%,
        hsl(var(--primary) / 0.07),
        transparent 68%
    );
}

.plans-page > * {
    position: relative;
    z-index: 1;
}
</style>