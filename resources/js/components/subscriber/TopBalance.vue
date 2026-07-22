<script setup>
import { AlertTriangle, Clock, Wallet } from "lucide-vue-next";
import { computed, ref } from "vue";
import DepositDialog from "@/components/subscriber/DepositDialog.vue";
import { useSubscriberContext } from "@/composables/useSubscriberContext";

const { balance, daysIndicator } = useSubscriberContext();

const depositOpen = ref(false);

const formattedBalance = computed(() => {
    const value = Number(balance.value ?? 0);
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(value);
});

const indicator = computed(() => {
    const data = daysIndicator.value;
    if (!data?.visible) {
        return null;
    }
    return data;
});

const isUrgent = computed(() => !!indicator.value?.urgent);

const shortfall = computed(() => {
    const value = Number(daysIndicator.value?.shortfall ?? 0);
    return value > 0 ? Math.ceil(value) : null;
});

const daysLabel = computed(() => {
    if (!indicator.value) {
        return "";
    }

    const days = Number(indicator.value.days_left ?? 0);
    if (days <= 0) {
        return "Сегодня";
    }

    return `${days} ${pluralizeDays(days)}`;
});

const daysTitle = computed(() => {
    if (!indicator.value) {
        return "";
    }

    if (isUrgent.value) {
        if (shortfall.value) {
            const amount = new Intl.NumberFormat("ru-RU", {
                style: "currency",
                currency: "RUB",
                maximumFractionDigits: 0,
            }).format(shortfall.value);
            return `Не хватает ${amount} для автопродления — пополните баланс`;
        }
        return "Не хватает средств для автопродления — пополните баланс";
    }

    const days = Number(indicator.value.days_left ?? 0);
    if (days <= 0) {
        return "Подписка действует до конца сегодняшнего дня";
    }

    return `Осталось ${days} ${pluralizeDays(days)} по тарифу`;
});

function openDeposit() {
    depositOpen.value = true;
}

function pluralizeDays(n) {
    const abs = Math.abs(Number(n)) % 100;
    const last = abs % 10;
    if (abs > 10 && abs < 20) {
        return "дней";
    }
    if (last === 1) {
        return "день";
    }
    if (last >= 2 && last <= 4) {
        return "дня";
    }
    return "дней";
}
</script>

<template>
    <div
        class="flex items-center gap-2 rounded-md border px-3 py-1.5 transition-colors"
        :class="
            isUrgent
                ? 'border-amber-500/40 bg-amber-500/10 dark:bg-amber-500/15'
                : 'border-border bg-card'
        "
    >
        <Wallet
            class="h-4 w-4 shrink-0"
            :class="isUrgent ? 'text-amber-600 dark:text-amber-400' : 'text-muted-foreground'"
        />
        <span
            class="text-sm font-medium tabular-nums"
            :class="isUrgent ? 'text-amber-950 dark:text-amber-100' : ''"
        >
            {{ formattedBalance }}
        </span>

        <template v-if="indicator">
            <span
                class="h-4 w-px shrink-0"
                :class="isUrgent ? 'bg-amber-500/40' : 'bg-border'"
                aria-hidden="true"
            />
            <span
                class="inline-flex items-center gap-1 text-xs font-medium tabular-nums sm:text-sm"
                :class="
                    isUrgent
                        ? 'text-amber-800 dark:text-amber-200'
                        : 'text-muted-foreground'
                "
                :title="daysTitle"
            >
                <component
                    :is="isUrgent ? AlertTriangle : Clock"
                    class="h-3.5 w-3.5 shrink-0"
                    :class="isUrgent ? 'text-amber-600 dark:text-amber-400' : ''"
                    aria-hidden="true"
                />
                <span class="whitespace-nowrap">
                    <span class="sm:hidden">{{ daysLabel }}</span>
                    <span class="hidden sm:inline">
                        <template v-if="Number(indicator.days_left) <= 0">Последний день</template>
                        <template v-else>Осталось {{ daysLabel }}</template>
                    </span>
                </span>
            </span>
        </template>

        <button
            type="button"
            class="inline-flex h-7 items-center rounded-md px-2 text-xs font-medium transition-colors"
            :class="
                isUrgent
                    ? 'bg-amber-600 text-white hover:bg-amber-600/90 dark:bg-amber-500 dark:hover:bg-amber-500/90'
                    : 'border border-input bg-background hover:bg-accent hover:text-accent-foreground'
            "
            @click="openDeposit"
        >
            Пополнить
        </button>

        <DepositDialog v-model:open="depositOpen" :initial-amount="shortfall" />
    </div>
</template>
