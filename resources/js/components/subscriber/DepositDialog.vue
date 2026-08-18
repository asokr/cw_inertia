<script setup>
import { router, useForm } from "@inertiajs/vue3";
import { computed, ref, watch } from "vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Tabs from "@/components/ui/Tabs.vue";
import TabsContent from "@/components/ui/TabsContent.vue";
import TabsList from "@/components/ui/TabsList.vue";
import TabsTrigger from "@/components/ui/TabsTrigger.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";
import { formatCredits } from "@/utils/credits";

const props = defineProps({
    open: { type: Boolean, default: false },
    /** Стартовая сумма; если не задана — shortfall или 500 */
    initialAmount: { type: [Number, String, null], default: null },
    /** Вкладка при открытии: deposit | credits */
    initialTab: { type: String, default: "deposit" },
});

const emit = defineEmits(["update:open"]);

const { balance, daysIndicator, credits, rublesPerCredit, subscription } = useSubscriberContext();

const tab = ref("deposit");
const quantityPresets = [100, 300, 500, 1000, 5000];
const quantity = ref(100);
const purchasing = ref(false);
const confirmOpen = ref(false);
const depositReason = ref(null);

const depositPresets = [500, 1000, 3000, 5000, 10000];

const form = useForm({
    amount: "500",
});

const shortfall = computed(() => {
    const value = Number(daysIndicator.value?.shortfall ?? 0);
    return value > 0 ? Math.ceil(value) : 0;
});

const formattedBalance = computed(() =>
    new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(Number(balance.value ?? 0))
);

const formattedShortfall = computed(() =>
    new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(shortfall.value)
);

const availableCredits = computed(() => Number(credits.value?.available ?? 0));
const unitPrice = computed(() => Number(rublesPerCredit.value ?? 0));
const hasActiveSubscription = computed(() => Number(subscription.value?.status ?? 0) === 1);

const parsedQuantity = computed(() => {
    const n = Number(quantity.value);
    return Number.isFinite(n) && n > 0 ? Math.floor(n) : 0;
});

const totalPrice = computed(() => Math.round(parsedQuantity.value * unitPrice.value * 100) / 100);

const canAffordCredits = computed(() => Number(balance.value ?? 0) >= Number(totalPrice.value ?? 0));

const creditsShortfall = computed(() =>
    Math.max(0, Math.ceil(Number(totalPrice.value ?? 0) - Number(balance.value ?? 0)))
);

const dialogTitle = computed(() =>
    tab.value === "credits" ? "Покупка кредитов" : "Пополнение счёта"
);

const dialogDescription = computed(() =>
    tab.value === "credits"
        ? "Купленные кредиты не сгорают при продлении тарифа"
        : "Средства зачислятся после успешной оплаты"
);

function formatPrice(price) {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 2,
    }).format(Number(price ?? 0));
}

function resolveDefaultAmount() {
    if (props.initialAmount != null && Number(props.initialAmount) > 0) {
        return String(Math.ceil(Number(props.initialAmount)));
    }
    if (shortfall.value > 0) {
        return String(shortfall.value);
    }
    return "500";
}

watch(
    () => props.open,
    (isOpen) => {
        if (!isOpen) {
            confirmOpen.value = false;
            depositReason.value = null;
            return;
        }
        form.clearErrors();
        form.amount = resolveDefaultAmount();
        quantity.value = 100;
        tab.value = props.initialTab === "credits" ? "credits" : "deposit";
    }
);

function selectPreset(amount) {
    form.amount = String(amount);
    depositReason.value = null;
}

function isPresetActive(amount) {
    return Number(form.amount) === amount;
}

function selectQuantity(preset) {
    quantity.value = preset;
}

function isQuantityActive(preset) {
    return parsedQuantity.value === preset;
}

function goToDepositForCredits() {
    if (creditsShortfall.value < 1) {
        tab.value = "deposit";
        return;
    }
    form.amount = String(creditsShortfall.value);
    depositReason.value = "credits";
    tab.value = "deposit";
}

function openConfirm() {
    if (parsedQuantity.value < 1 || !hasActiveSubscription.value) {
        return;
    }
    if (!canAffordCredits.value) {
        goToDepositForCredits();
        return;
    }
    confirmOpen.value = true;
}

function closeConfirm() {
    confirmOpen.value = false;
}

function buyCredits() {
    if (parsedQuantity.value < 1 || !canAffordCredits.value) {
        return;
    }

    purchasing.value = true;
    closeConfirm();

    router.post(
        "/panel/credits/purchase",
        {
            quantity: parsedQuantity.value,
        },
        {
            preserveScroll: true,
            onSuccess: () => {
                emit("update:open", false);
            },
            onFinish: () => {
                purchasing.value = false;
            },
        }
    );
}

function submitDeposit() {
    form.post("/panel/payments/deposit", {
        preserveScroll: true,
        onSuccess: () => {
            emit("update:open", false);
        },
    });
}

function close() {
    if (form.processing || purchasing.value) {
        return;
    }
    emit("update:open", false);
}
</script>

<template>
    <Dialog
        :open="open"
        :title="dialogTitle"
        :description="dialogDescription"
        class="max-w-md"
        @update:open="emit('update:open', $event)"
    >
        <Tabs :model-value="tab" class="space-y-0" @update:model-value="tab = $event">
            <TabsList class="grid w-full grid-cols-2">
                <TabsTrigger value="deposit" class="w-full">Счёт</TabsTrigger>
                <TabsTrigger value="credits" class="w-full">Кредиты</TabsTrigger>
            </TabsList>

            <TabsContent value="deposit" class="mt-4">
                <form class="space-y-4" @submit.prevent="submitDeposit">
                    <div class="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Текущий баланс:</span>
                        <span class="ml-1.5 font-medium tabular-nums">{{ formattedBalance }}</span>
                    </div>

                    <div
                        v-if="depositReason === 'credits'"
                        class="rounded-md border border-primary/20 bg-primary/5 px-3 py-2 text-sm"
                    >
                        Чтобы купить {{ formatCredits(parsedQuantity) }}, не хватает
                        <strong class="tabular-nums">{{ formatPrice(creditsShortfall) }}</strong>.
                        Сумма уже подставлена — можно изменить.
                    </div>

                    <div
                        v-else-if="shortfall > 0"
                        class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-950 dark:text-amber-100"
                    >
                        Для автопродления тарифа не хватает
                        <strong class="tabular-nums">{{ formattedShortfall }}</strong>.
                        Сумма уже подставлена — можно изменить.
                    </div>

                    <div class="space-y-2">
                        <Label for="deposit-dialog-amount">Сумма пополнения</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="preset in depositPresets"
                                :key="preset"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors"
                                :class="
                                    isPresetActive(preset)
                                        ? 'border-primary bg-primary/10 font-medium text-primary'
                                        : 'border-border/70 text-muted-foreground hover:border-border hover:bg-muted/50'
                                "
                                @click="selectPreset(preset)"
                            >
                                {{ preset.toLocaleString("ru-RU") }} ₽
                            </button>
                            <button
                                v-if="shortfall > 0 && !depositPresets.includes(shortfall)"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors"
                                :class="
                                    isPresetActive(shortfall)
                                        ? 'border-amber-500 bg-amber-500/15 font-medium text-amber-800 dark:text-amber-200'
                                        : 'border-amber-500/40 text-amber-800 hover:bg-amber-500/10 dark:text-amber-200'
                                "
                                @click="selectPreset(shortfall)"
                            >
                                {{ shortfall.toLocaleString("ru-RU") }} ₽
                                <span class="ml-1 text-[10px] uppercase tracking-wide opacity-80">нехватка</span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Input
                            id="deposit-dialog-amount"
                            v-model="form.amount"
                            type="number"
                            min="1"
                            step="1"
                            inputmode="numeric"
                            autofocus
                        />
                        <p v-if="form.errors.amount" class="text-xs text-destructive">
                            {{ form.errors.amount }}
                        </p>
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" :disabled="form.processing" @click="close">
                            Отмена
                        </Button>
                        <Button type="submit" :disabled="form.processing">
                            {{ form.processing ? "Переход к оплате…" : "Перейти к оплате" }}
                        </Button>
                    </div>
                </form>
            </TabsContent>

            <TabsContent value="credits" class="mt-4">
                <form class="space-y-4" @submit.prevent="openConfirm">
                    <div class="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="text-muted-foreground">Кредиты:</span>
                                <span class="ml-1.5 font-medium tabular-nums">
                                    {{ formatCredits(availableCredits) }}
                                </span>
                            </span>
                            <span>
                                <span class="text-muted-foreground">Счёт:</span>
                                <span class="ml-1.5 font-medium tabular-nums">{{ formattedBalance }}</span>
                            </span>
                        </div>
                    </div>

                    <p
                        v-if="!hasActiveSubscription"
                        class="rounded-md border border-amber-500/30 bg-amber-500/10 px-3 py-2 text-sm text-amber-950 dark:text-amber-100"
                    >
                        Чтобы покупать кредиты, нужна активная подписка.
                    </p>

                    <div class="space-y-2">
                        <Label>Количество</Label>
                        <div class="flex flex-wrap gap-2">
                            <button
                                v-for="preset in quantityPresets"
                                :key="preset"
                                type="button"
                                class="rounded-md border px-3 py-1.5 text-sm tabular-nums transition-colors"
                                :class="
                                    isQuantityActive(preset)
                                        ? 'border-primary bg-primary/10 font-medium text-primary'
                                        : 'border-border/70 text-muted-foreground hover:border-border hover:bg-muted/50'
                                "
                                @click="selectQuantity(preset)"
                            >
                                {{ preset.toLocaleString("ru-RU") }}
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1">
                        <Label for="deposit-dialog-credits-qty">Своё количество</Label>
                        <Input
                            id="deposit-dialog-credits-qty"
                            v-model.number="quantity"
                            type="number"
                            min="1"
                            step="1"
                            class="tabular-nums"
                        />
                    </div>

                    <div class="rounded-md border border-border/70 bg-muted/30 px-3 py-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span class="text-muted-foreground">К списанию со счёта</span>
                            <span class="font-semibold tabular-nums">{{ formatPrice(totalPrice) }}</span>
                        </div>
                        <div class="mt-1 flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                            <span>
                                {{ parsedQuantity.toLocaleString("ru-RU") }} × {{ formatPrice(unitPrice) }}
                            </span>
                            <span
                                v-if="hasActiveSubscription && !canAffordCredits"
                                class="font-medium text-amber-700 dark:text-amber-300"
                            >
                                Не хватает {{ creditsShortfall.toLocaleString("ru-RU") }} ₽
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <Button type="button" variant="outline" :disabled="purchasing" @click="close">
                            Отмена
                        </Button>
                        <Button
                            type="submit"
                            :disabled="purchasing || parsedQuantity < 1 || !hasActiveSubscription"
                            :variant="canAffordCredits ? 'default' : 'outline'"
                        >
                            <span v-if="purchasing">Оформляем…</span>
                            <span v-else-if="canAffordCredits">Купить со счёта</span>
                            <span v-else>Пополнить на {{ creditsShortfall.toLocaleString("ru-RU") }} ₽</span>
                        </Button>
                    </div>
                </form>
            </TabsContent>
        </Tabs>

        <Dialog
            :open="confirmOpen"
            title="Подтвердите покупку"
            :description="`${formatCredits(parsedQuantity)} за ${formatPrice(totalPrice)}`"
            class="max-w-sm"
            @update:open="confirmOpen = $event"
        >
            <div class="space-y-4">
                <div class="rounded-xl border border-border/70 bg-muted/40 p-4 text-sm">
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Количество</span>
                        <span class="font-semibold tabular-nums">
                            +{{ parsedQuantity.toLocaleString("ru-RU") }}
                        </span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Цена 1 кредита</span>
                        <span class="font-medium tabular-nums">{{ formatPrice(unitPrice) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3 border-t border-border/60 pt-2">
                        <span class="text-muted-foreground">Списание со счёта</span>
                        <span class="font-semibold tabular-nums">{{ formatPrice(totalPrice) }}</span>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-3">
                        <span class="text-muted-foreground">Останется на счёте</span>
                        <span class="font-medium tabular-nums">
                            {{
                                formatPrice(
                                    Math.max(0, Number(balance ?? 0) - Number(totalPrice ?? 0))
                                )
                            }}
                        </span>
                    </div>
                </div>

                <div class="flex justify-end gap-2">
                    <Button variant="ghost" @click="closeConfirm">Отмена</Button>
                    <Button
                        :disabled="!canAffordCredits || purchasing || parsedQuantity < 1"
                        @click="buyCredits"
                    >
                        {{ purchasing ? "Оформляем…" : "Подтвердить покупку" }}
                    </Button>
                </div>
            </div>
        </Dialog>
    </Dialog>
</template>
