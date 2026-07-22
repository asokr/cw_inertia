<script setup>
import { useForm } from "@inertiajs/vue3";
import { computed, watch } from "vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const props = defineProps({
    open: { type: Boolean, default: false },
    /** Стартовая сумма; если не задана — shortfall или 500 */
    initialAmount: { type: [Number, String, null], default: null },
});

const emit = defineEmits(["update:open"]);

const { balance, daysIndicator } = useSubscriberContext();

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
            return;
        }
        form.clearErrors();
        form.amount = resolveDefaultAmount();
    }
);

function selectPreset(amount) {
    form.amount = String(amount);
}

function isPresetActive(amount) {
    return Number(form.amount) === amount;
}

function submit() {
    form.post("/panel/payments/deposit", {
        preserveScroll: true,
        onSuccess: () => {
            emit("update:open", false);
        },
    });
}

function close() {
    if (form.processing) {
        return;
    }
    emit("update:open", false);
}
</script>

<template>
    <Dialog
        :open="open"
        title="Пополнение баланса"
        description="Средства зачислятся после успешной оплаты"
        class="max-w-md"
        @update:open="emit('update:open', $event)"
    >
        <form class="space-y-4" @submit.prevent="submit">
            <div class="rounded-md border bg-muted/40 px-3 py-2 text-sm">
                <span class="text-muted-foreground">Текущий баланс:</span>
                <span class="ml-1.5 font-medium tabular-nums">{{ formattedBalance }}</span>
            </div>

            <div
                v-if="shortfall > 0"
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
    </Dialog>
</template>
