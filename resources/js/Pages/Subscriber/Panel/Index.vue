<script setup>
import { Head, router, usePage } from "@inertiajs/vue3";
import {
    Bot,
    CreditCard,
    Headphones,
    History,
    LayoutGrid,
    MessageSquare,
    Sparkles,
    User,
    Wallet,
} from "lucide-vue-next";
import { computed } from "vue";
import PanelLimitsWidget from "@/components/subscriber/panel/PanelLimitsWidget.vue";
import PanelRecentPayments from "@/components/subscriber/panel/PanelRecentPayments.vue";
import PanelStatCard from "@/components/subscriber/panel/PanelStatCard.vue";
import PanelToolsGrid from "@/components/subscriber/panel/PanelToolsGrid.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const props = defineProps({
    dashboard: {
        type: Object,
        default: () => ({
            subscription: null,
            stats: { cabinets_total: 0, active_bots: 0, cabinets_by_tool: {} },
            recent_payments: [],
        }),
    },
});

const page = usePage();
const { balance, hasSeenTour } = useSubscriberContext();

const userName = computed(() => page.props.auth?.user?.name ?? "подписчик");
const subscription = computed(() => props.dashboard?.subscription ?? null);
const stats = computed(() => props.dashboard?.stats ?? {});

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(balance.value ?? 0)
    )
);

const planLabel = computed(() => {
    if (!subscription.value?.plan_name) {
        return "Не выбран";
    }

    return subscription.value.plan_name;
});

const planHint = computed(() => {
    if (!subscription.value) {
        return "Выберите тариф";
    }

    if (!subscription.value.end_date) {
        return subscription.value.status ? "Активная подписка" : "Подписка неактивна";
    }

    if (!subscription.value.status) {
        return `Истекла ${subscription.value.end_date}`;
    }

    return `Действует до ${subscription.value.end_date}`;
});

function markTourSeen() {
    router.post("/panel/user/tour-seen", {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Панель" />

    <SubscriberLayout title="Главная">
        <section class="subscriber-cabinet__fade subscriber-cabinet__fade--1 mb-8">
            <p
                class="inline-flex items-center gap-2 rounded-full border border-border/70 bg-card/80 px-4 py-1.5 text-xs font-medium text-muted-foreground backdrop-blur"
            >
                <Sparkles class="h-3.5 w-3.5 text-primary" />
                С возвращением, {{ userName }}
            </p>

            <h2 class="mt-4 text-2xl font-bold tracking-tight sm:text-3xl">
                Сводка по
                <span class="bg-gradient-to-r from-foreground to-primary bg-clip-text text-transparent">
                    вашему аккаунту
                </span>
            </h2>

            <p class="mt-3 max-w-2xl text-sm leading-relaxed text-muted-foreground sm:text-base">
                Баланс, тариф, подключённые кабинеты и лимиты — всё в одном месте. Выберите инструмент или
                перейдите к тарифам для подключения полного доступа.
            </p>

            <div class="mt-5 flex flex-wrap gap-2">
                <Button href="/panel/plans" size="sm" class="gap-2">
                    <CreditCard class="h-4 w-4" />
                    Тарифы
                </Button>
                <Button href="/panel/user/profile" variant="outline" size="sm" class="gap-2">
                    <User class="h-4 w-4" />
                    Профиль
                </Button>
            </div>
        </section>

        <div class="subscriber-cabinet__fade subscriber-cabinet__fade--2 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <PanelStatCard :label="'Баланс'" :value="formattedBalance" hint="Пополнение в профиле" :icon="Wallet" />
            <PanelStatCard :label="'Тариф'" :value="planLabel" :hint="planHint" :icon="CreditCard" />
            <PanelStatCard
                :label="'Кабинеты'"
                :value="String(stats.cabinets_total ?? 0)"
                hint="Подключено во всех инструментах"
                :icon="LayoutGrid"
            />
            <PanelStatCard
                :label="'Автоответчики'"
                :value="String(stats.active_bots ?? 0)"
                hint="Активные боты WB и Ozon"
                :icon="MessageSquare"
            />
        </div>

        <Card class="subscriber-cabinet__fade subscriber-cabinet__fade--3 subscriber-card--static mt-6 border-border/70 bg-card/80 p-6 backdrop-blur dark:bg-card/95 dark:backdrop-blur-none">
            <h2 class="mb-4 text-base font-semibold tracking-tight">Быстрые действия</h2>
            <div class="flex flex-wrap gap-2">
                <Button href="/panel/plans" variant="outline" size="sm" class="gap-2">
                    <CreditCard class="h-4 w-4" />
                    Тарифы
                </Button>
                <Button href="/panel/user/profile" variant="outline" size="sm" class="gap-2">
                    <User class="h-4 w-4" />
                    Профиль
                </Button>
                <Button href="/panel/user/history" variant="outline" size="sm" class="gap-2">
                    <History class="h-4 w-4" />
                    История платежей
                </Button>
                <Button href="/panel/manager" variant="outline" size="sm" class="gap-2">
                    <Headphones class="h-4 w-4" />
                    Личный менеджер
                </Button>
            </div>
        </Card>

        <div class="subscriber-cabinet__fade subscriber-cabinet__fade--4 mt-6">
            <PanelToolsGrid :cabinets-by-tool="stats.cabinets_by_tool ?? {}" />
        </div>

        <div class="subscriber-cabinet__fade subscriber-cabinet__fade--5 mt-6 grid gap-4 lg:grid-cols-2">
            <PanelLimitsWidget :remaining-limits="subscription?.remaining_limits ?? {}" />
            <PanelRecentPayments :payments="dashboard.recent_payments ?? []" />
        </div>

        <Card
            v-if="!hasSeenTour"
            class="subscriber-cabinet__fade subscriber-cabinet__fade--5 subscriber-card--static mt-6 border-primary/30 bg-gradient-to-br from-card/95 to-primary/5 p-6 shadow-lg shadow-primary/5 backdrop-blur"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="space-y-2">
                    <div class="flex items-center gap-2">
                        <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                            <Bot class="h-5 w-5" />
                        </div>
                        <h2 class="text-base font-semibold">Добро пожаловать в CW Platform</h2>
                    </div>
                    <p class="text-sm leading-relaxed text-muted-foreground">
                        Начните с подключения кабинета Wildberries или Ozon в разделе отзывов, затем изучите
                        ценообразование, репрайсер и ИИ-инструменты.
                    </p>
                </div>
                <Button variant="outline" size="sm" class="shrink-0" @click="markTourSeen">
                    Понятно
                </Button>
            </div>
        </Card>
    </SubscriberLayout>
</template>