<script setup>
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { Pencil, Shield } from "lucide-vue-next";
import { computed, ref } from "vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import ExtraLimitsShop from "@/components/subscriber/profile/ExtraLimitsShop.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";
import { formatLimitLabel } from "@/utils/limitLabels";

const props = defineProps({
    subscriptionData: { type: Object, default: null },
    extraLimitsCatalog: { type: Array, default: () => [] },
    userExtraLimits: { type: Object, default: () => ({}) },
});

const page = usePage();
const { balance, daysIndicator } = useSubscriberContext();
const editingName = ref(false);

const profileForm = useForm({
    name: page.props.auth?.user?.name ?? "",
});

const depositPresets = [500, 1000, 3000, 5000, 10000];

const renewalShortfall = computed(() => {
    const value = Number(daysIndicator.value?.shortfall ?? 0);
    return value > 0 ? Math.ceil(value) : 0;
});

const depositForm = useForm({
    amount: String(renewalShortfall.value > 0 ? renewalShortfall.value : 500),
});

const subscription = computed(() => props.subscriptionData?.subscription ?? null);
const plan = computed(() => props.subscriptionData?.plan ?? null);
const nextActions = computed(() => props.subscriptionData?.next ?? []);
const hasStopAction = computed(() => nextActions.value?.some((item) => item.action === "STOP"));

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

function selectDepositAmount(amount) {
    depositForm.amount = String(amount);
}

function isDepositPresetActive(amount) {
    return Number(depositForm.amount) === amount;
}

function unsubscribe() {
    if (!subscription.value || !confirm("Отменить подписку в конце периода?")) return;
    router.post("/panel/user/unsubscribe", { id: subscription.value.id }, { preserveScroll: true });
}

function resubscribe() {
    if (!subscription.value) return;
    router.post("/panel/user/resubscribe", { id: subscription.value.id }, { preserveScroll: true });
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

            <Card id="balance-section" class="p-6">
                <h2 class="mb-4 text-base font-semibold">Баланс</h2>
                <p class="mb-4 text-2xl font-semibold tabular-nums">{{ formattedBalance }}</p>
                <form class="max-w-sm space-y-3" @submit.prevent="deposit">
                    <div
                        v-if="renewalShortfall > 0"
                        class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-950 dark:text-amber-100"
                    >
                        Для автопродления не хватает
                        <strong class="tabular-nums">
                            {{ renewalShortfall.toLocaleString("ru-RU") }} ₽
                        </strong>
                        — сумма подставлена в поле ниже.
                    </div>
                    <div class="space-y-2">
                        <Label for="amount">Сумма пополнения</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="preset in depositPresets"
                                :key="preset"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors"
                                :class="
                                    isDepositPresetActive(preset)
                                        ? 'border-primary bg-primary/10 font-medium text-primary'
                                        : 'border-border/70 text-muted-foreground hover:border-border hover:bg-muted/50'
                                "
                                @click="selectDepositAmount(preset)"
                            >
                                {{ preset.toLocaleString("ru-RU") }} ₽
                            </button>
                            <button
                                v-if="renewalShortfall > 0 && !depositPresets.includes(renewalShortfall)"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors"
                                :class="
                                    isDepositPresetActive(renewalShortfall)
                                        ? 'border-amber-500 bg-amber-500/15 font-medium text-amber-800 dark:text-amber-200'
                                        : 'border-amber-500/40 text-amber-800 hover:bg-amber-500/10 dark:text-amber-200'
                                "
                                @click="selectDepositAmount(renewalShortfall)"
                            >
                                {{ renewalShortfall.toLocaleString("ru-RU") }} ₽
                                <span class="ml-1 text-[10px] uppercase tracking-wide opacity-80">нехватка</span>
                            </button>
                        </div>
                    </div>
                    <div class="flex gap-2">
                        <div class="flex-1 space-y-1">
                            <Input id="amount" v-model="depositForm.amount" type="number" min="1" step="1" />
                            <p v-if="depositForm.errors.amount" class="text-xs text-destructive">
                                {{ depositForm.errors.amount }}
                            </p>
                        </div>
                        <Button type="submit" class="self-start" :disabled="depositForm.processing">Пополнить</Button>
                    </div>
                </form>
                <Link href="/panel/user/history" class="mt-4 inline-block text-sm text-primary hover:underline">
                    История платежей
                </Link>
            </Card>
        </div>

        <Card class="mt-6 p-6">
            <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
                <div class="flex items-center gap-2">
                    <Shield class="h-5 w-5 text-primary" />
                    <h2 class="text-base font-semibold">Текущая подписка</h2>
                </div>
                <Button href="/panel/plans" variant="outline" size="sm">Сменить тариф</Button>
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
                    <p class="mb-2 text-xs text-muted-foreground">Дополнительные лимиты</p>
                    <div class="flex flex-wrap gap-2">
                        <span
                            v-for="[key, value] in limitEntries(subscription.extra_limits_month)"
                            :key="key"
                            class="inline-flex items-center gap-1.5 rounded-full border border-primary/20 bg-primary/5 px-2.5 py-1 text-xs"
                        >
                            <span class="text-muted-foreground">{{ formatLimitLabel(key) }}</span>
                            <strong class="tabular-nums text-primary">+{{ value }}</strong>
                        </span>
                    </div>
                </div>

                <div class="max-w-xs">
                    <Button v-if="!hasStopAction" variant="outline" @click="unsubscribe">Отменить подписку</Button>
                    <div v-else class="space-y-2">
                        <p class="text-sm font-medium text-destructive">Вы отменили подписку</p>
                        <Button variant="outline" @click="resubscribe">Возобновить подписку</Button>
                    </div>
                </div>
            </template>
            <div v-else class="space-y-3">
                <p class="text-sm text-muted-foreground">Активная подписка не найдена.</p>
                <Button href="/panel/plans">Выбрать тариф</Button>
            </div>
        </Card>

        <ExtraLimitsShop
            :catalog="extraLimitsCatalog"
            :user-extra-limits="userExtraLimits"
            :balance="balance"
        />
    </SubscriberLayout>
</template>