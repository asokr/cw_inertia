<script setup>
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { Pencil, Shield } from "lucide-vue-next";
import { computed, ref } from "vue";
import NotificationBanner from "@/components/subscriber/NotificationBanner.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";
import { formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    subscriptionData: { type: Object, default: null },
    availablePlans: { type: Array, default: () => [] },
    nextActions: { type: Array, default: () => [] },
    extraLimitsCatalog: { type: Array, default: () => [] },
    userExtraLimits: { type: Object, default: () => ({}) },
});

const page = usePage();
const { balance } = useSubscriberContext();
const editingName = ref(false);

const profileForm = useForm({
    name: page.props.auth?.user?.name ?? "",
});

const depositForm = useForm({
    amount: "",
});

const subscription = computed(() => props.subscriptionData?.subscription ?? null);
const plan = computed(() => props.subscriptionData?.plan ?? null);
const hasStopAction = computed(() =>
    props.nextActions?.some((item) => item.action === "STOP")
);

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(balance.value ?? 0)
    )
);

function saveName() {
    profileForm.put("/panel/user/profile", {
        preserveScroll: true,
        onSuccess: () => {
            editingName.value = false;
        },
    });
}

function deposit() {
    depositForm.post("/panel/payments/deposit", { preserveScroll: true });
}

function changePlan(planId, lower) {
    if (!confirm(lower ? "Тариф сменится в конце текущего периода. Продолжить?" : "Перейти на выбранный тариф?")) {
        return;
    }

    router.post("/panel/user/change-plan", { plan_id: planId }, { preserveScroll: true });
}

function unsubscribe() {
    if (!subscription.value || !confirm("Отменить подписку в конце периода?")) return;
    router.post("/panel/user/unsubscribe", { id: subscription.value.id }, { preserveScroll: true });
}

function resubscribe() {
    if (!subscription.value) return;
    router.post("/panel/user/resubscribe", { id: subscription.value.id }, { preserveScroll: true });
}

function buyExtraLimit(id) {
    router.post("/panel/user/extra-limits", { id }, { preserveScroll: true });
}

function limitEntries(limits) {
    if (!limits || typeof limits !== "object") return [];
    return Object.entries(limits);
}
</script>

<template>
    <Head title="Профиль" />

    <SubscriberLayout
        title="Профиль"
        :breadcrumbs="[{ label: 'Панель', href: '/panel' }, { label: 'Профиль' }]"
    >
        <NotificationBanner />

        <div class="grid gap-4 lg:grid-cols-2">
            <Card class="p-6">
                <h2 class="mb-4 text-base font-semibold">Данные аккаунта</h2>
                <div class="space-y-4 text-sm">
                    <div>
                        <p class="text-xs text-muted-foreground">Имя</p>
                        <div v-if="!editingName" class="mt-1 flex items-center gap-2">
                            <span class="font-medium">{{ page.props.auth?.user?.name }}</span>
                            <Button variant="ghost" size="icon" class="h-7 w-7" @click="editingName = true">
                                <Pencil class="h-3.5 w-3.5" />
                            </Button>
                        </div>
                        <form v-else class="mt-2 flex gap-2" @submit.prevent="saveName">
                            <Input v-model="profileForm.name" class="max-w-xs" />
                            <Button type="submit" size="sm" :disabled="profileForm.processing">Сохранить</Button>
                            <Button type="button" variant="ghost" size="sm" @click="editingName = false">Отмена</Button>
                        </form>
                    </div>
                    <div>
                        <p class="text-xs text-muted-foreground">Email</p>
                        <p class="mt-1 font-medium">{{ page.props.auth?.user?.email }}</p>
                    </div>
                </div>
            </Card>

            <Card class="p-6">
                <h2 class="mb-4 text-base font-semibold">Баланс</h2>
                <p class="mb-4 text-2xl font-semibold tabular-nums">{{ formattedBalance }}</p>
                <form class="flex max-w-sm gap-2" @submit.prevent="deposit">
                    <div class="flex-1 space-y-1">
                        <Label for="amount">Сумма пополнения</Label>
                        <Input id="amount" v-model="depositForm.amount" type="number" min="1" step="1" />
                        <p v-if="depositForm.errors.amount" class="text-xs text-destructive">{{ depositForm.errors.amount }}</p>
                    </div>
                    <Button type="submit" class="self-end" :disabled="depositForm.processing">Пополнить</Button>
                </form>
                <Link href="/panel/user/history" class="mt-4 inline-block text-sm text-primary hover:underline">
                    История платежей
                </Link>
            </Card>
        </div>

        <Card class="mt-6 p-6">
            <div class="mb-4 flex items-center gap-2">
                <Shield class="h-5 w-5 text-primary" />
                <h2 class="text-base font-semibold">Текущая подписка</h2>
            </div>

            <template v-if="subscription && plan">
                <div class="mb-4 flex flex-wrap gap-4 text-sm">
                    <div><span class="text-muted-foreground">Тариф:</span> <strong>{{ plan.name }}</strong></div>
                    <div><span class="text-muted-foreground">Оплачен до:</span> <strong>{{ subscription.end_date }}</strong></div>
                    <div><span class="text-muted-foreground">Затем спишется:</span> <strong>{{ plan.price }} ₽</strong></div>
                </div>

                <div v-if="limitEntries(subscription.limits_plan).length" class="mb-3">
                    <p class="mb-1 text-xs text-muted-foreground">По тарифу</p>
                    <ul class="list-inside list-disc text-sm">
                        <li v-for="[key, value] in limitEntries(subscription.limits_plan)" :key="key">
                            {{ formatLimitLabel(key) }}: <strong>{{ value }}</strong>
                        </li>
                    </ul>
                </div>

                <div v-if="limitEntries(subscription.limits_month).length" class="mb-3">
                    <p class="mb-1 text-xs text-muted-foreground">На действие тарифа</p>
                    <ul class="list-inside list-disc text-sm">
                        <li v-for="[key, value] in limitEntries(subscription.limits_month)" :key="key">
                            {{ formatLimitLabel(key) }}: <strong>{{ value }}</strong>
                        </li>
                    </ul>
                </div>

                <div v-if="limitEntries(subscription.extra_limits_month).length" class="mb-4">
                    <p class="mb-1 text-xs text-muted-foreground">Дополнительные лимиты</p>
                    <ul class="list-inside list-disc text-sm">
                        <li v-for="[key, value] in limitEntries(subscription.extra_limits_month)" :key="key">
                            {{ formatLimitLabel(key) }}: <strong>{{ value }}</strong>
                        </li>
                    </ul>
                </div>

                <div class="max-w-xs">
                    <Button v-if="!hasStopAction" variant="outline" @click="unsubscribe">Отменить подписку</Button>
                    <div v-else class="space-y-2">
                        <p class="text-sm font-medium text-destructive">Вы отменили подписку</p>
                        <Button variant="outline" @click="resubscribe">Возобновить подписку</Button>
                    </div>
                </div>
            </template>
            <p v-else class="text-sm text-muted-foreground">Активная подписка не найдена. Выберите тариф ниже.</p>
        </Card>

        <Card v-if="extraLimitsCatalog.length" class="mt-6 p-6">
            <h2 class="mb-4 text-base font-semibold">Дополнительные лимиты</h2>
            <div class="flex flex-wrap gap-2">
                <Button
                    v-for="item in extraLimitsCatalog"
                    :key="item.id"
                    variant="outline"
                    size="sm"
                    @click="buyExtraLimit(item.id)"
                >
                    {{ formatLimitLabel(item.limit_name) }} +{{ item.quantity }} — {{ item.price }} ₽
                </Button>
            </div>
        </Card>

        <div v-if="availablePlans.length && !hasStopAction" class="mt-6">
            <h2 class="mb-4 text-base font-semibold">Доступные тарифы</h2>
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="item in availablePlans" :key="item.id" class="flex flex-col p-6">
                    <div class="flex-1">
                        <h3 class="text-lg font-semibold">{{ item.name }}</h3>
                        <p class="text-sm text-muted-foreground">{{ item.duration }} дней</p>
                        <p class="mt-4 text-2xl font-bold">{{ item.price }} ₽</p>
                    </div>
                    <Button class="mt-4 w-full" variant="outline" @click="changePlan(item.id, item.lower)">
                        {{ item.lower ? "Понизить тариф" : "Перейти на тариф" }}
                    </Button>
                </Card>
            </div>
        </div>
    </SubscriberLayout>
</template>