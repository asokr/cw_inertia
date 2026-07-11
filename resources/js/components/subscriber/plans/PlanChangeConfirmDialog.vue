<script setup>
import { AlertTriangle, ArrowRight, Clock, TrendingDown, Wallet, X } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, watch } from "vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";

const props = defineProps({
    open: { type: Boolean, default: false },
    plan: { type: Object, default: null },
    currentPlanName: { type: String, default: null },
    balance: { type: Number, default: 0 },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["update:open", "confirm"]);

const variant = computed(() => {
    if (!props.plan) {
        return null;
    }

    if (props.plan.lower) {
        return "downgrade";
    }

    if (shortfall.value > 0) {
        return "deposit";
    }

    return "change";
});

const shortfall = computed(() =>
    props.plan ? Math.max(0, Math.ceil(Number(props.plan.price ?? 0) - Number(props.balance ?? 0))) : 0
);

const formattedPrice = computed(() => formatCurrency(props.plan?.price ?? 0));
const formattedBalance = computed(() => formatCurrency(props.balance ?? 0));
const formattedShortfall = computed(() => formatCurrency(shortfall.value));

const header = computed(() => {
    if (variant.value === "downgrade") {
        return {
            title: "Понижение тарифа",
            subtitle: "Вступит в силу в конце периода",
            icon: TrendingDown,
        };
    }

    if (variant.value === "deposit") {
        return {
            title: "Пополнение баланса",
            subtitle: "Тариф активируется после оплаты",
            icon: Wallet,
        };
    }

    return {
        title: "Переход на тариф",
        subtitle: "Лимиты обновятся сразу",
        icon: ArrowRight,
    };
});

const downgradeOverages = computed(() => props.plan?.downgrade_overages ?? []);

const confirmLabel = computed(() => {
    if (variant.value === "downgrade") return "Запланировать понижение";
    if (variant.value === "deposit") return `Пополнить ${formattedShortfall.value}`;
    return "Подтвердить переход";
});

function formatCurrency(value) {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(Number(value ?? 0));
}

function close() {
    if (props.loading) {
        return;
    }

    emit("update:open", false);
}

function confirm() {
    emit("confirm");
}

function onKeydown(event) {
    if (event.key === "Escape" && props.open) {
        close();
    }
}

onMounted(() => document.addEventListener("keydown", onKeydown));
onUnmounted(() => document.removeEventListener("keydown", onKeydown));

watch(
    () => props.open,
    (isOpen) => {
        document.body.style.overflow = isOpen ? "hidden" : "";
    }
);
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-50 flex items-center justify-center p-4 sm:p-6">
            <div class="absolute inset-0 bg-black/55 backdrop-blur-[2px]" @click="close" />

            <div
                v-if="plan"
                class="plan-confirm relative z-10 w-full max-w-[26rem] overflow-hidden rounded-2xl border border-border/60 bg-card shadow-2xl"
                :class="`plan-confirm--${variant}`"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="`plan-confirm-title-${plan.id}`"
            >
                <header class="plan-confirm__header relative px-5 pb-5 pt-5 sm:px-6 sm:pt-6">
                    <div class="plan-confirm__header-glow" aria-hidden="true" />

                    <Button
                        variant="ghost"
                        size="icon"
                        class="absolute right-3 top-3 h-8 w-8 text-muted-foreground hover:bg-background/60 hover:text-foreground"
                        :disabled="loading"
                        @click="close"
                    >
                        <X class="h-4 w-4" />
                    </Button>

                    <div class="relative flex items-start gap-3.5 pr-8">
                        <div class="plan-confirm__icon flex h-11 w-11 shrink-0 items-center justify-center rounded-xl">
                            <component :is="header.icon" class="h-5 w-5" />
                        </div>

                        <div class="min-w-0 pt-0.5">
                            <h2
                                :id="`plan-confirm-title-${plan.id}`"
                                class="text-lg font-semibold leading-tight tracking-tight"
                            >
                                {{ header.title }}
                            </h2>
                            <p class="mt-1 text-sm leading-snug text-muted-foreground">
                                {{ header.subtitle }}
                            </p>
                        </div>
                    </div>
                </header>

                <div class="space-y-4 px-5 pb-5 sm:px-6">
                    <div class="rounded-xl border border-border/50 bg-background/50 p-4">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                                    Новый тариф
                                </p>
                                <h3 class="mt-1 truncate font-serif text-xl font-bold tracking-tight">
                                    {{ plan.name }}
                                </h3>
                            </div>
                            <Badge v-if="plan.recommended" variant="secondary" class="shrink-0 text-[10px]">
                                Рекомендуем
                            </Badge>
                        </div>

                        <div class="mt-3 flex items-end justify-between gap-3 border-t border-border/40 pt-3">
                            <div>
                                <p class="text-[1.875rem] font-semibold tabular-nums leading-none tracking-tight text-foreground">
                                    {{ formattedPrice }}
                                </p>
                                <p class="mt-1 text-xs text-muted-foreground">{{ plan.duration }} дней</p>
                            </div>
                            <p v-if="currentPlanName" class="max-w-[9rem] text-right text-xs leading-snug text-muted-foreground">
                                Сейчас: <span class="font-medium text-foreground">{{ currentPlanName }}</span>
                            </p>
                        </div>
                    </div>

                    <div v-if="variant === 'deposit'" class="grid grid-cols-2 gap-2.5">
                        <div class="rounded-lg border border-border/50 bg-muted/20 px-3 py-2.5">
                            <p class="text-[11px] uppercase tracking-wide text-muted-foreground">Баланс</p>
                            <p class="mt-1 text-sm font-semibold tabular-nums">{{ formattedBalance }}</p>
                        </div>
                        <div class="rounded-lg border border-primary/25 bg-primary/5 px-3 py-2.5">
                            <p class="text-[11px] uppercase tracking-wide text-muted-foreground">Пополнить</p>
                            <p class="mt-1 text-sm font-semibold tabular-nums text-primary">{{ formattedShortfall }}</p>
                        </div>
                    </div>

                    <p class="text-sm leading-relaxed text-muted-foreground">
                        <template v-if="variant === 'downgrade'">
                            Тариф «{{ plan.name }}» вступит в силу в конце текущего периода.
                            До этого момента сохранятся лимиты
                            <template v-if="currentPlanName">тарифа «{{ currentPlanName }}»</template>.
                        </template>
                        <template v-else-if="variant === 'deposit'">
                            После пополнения на <strong class="text-foreground">{{ formattedShortfall }}</strong>
                            тариф «{{ plan.name }}» активируется автоматически.
                        </template>
                        <template v-else>
                            С баланса спишется <strong class="text-foreground">{{ formattedPrice }}</strong>.
                            Доступ к инструментам и лимиты обновятся сразу.
                        </template>
                    </p>

                    <div
                        v-if="variant === 'downgrade' && downgradeOverages.length"
                        class="rounded-lg border border-amber-500/25 bg-amber-500/5 px-3 py-3 text-sm"
                    >
                        <p class="flex items-center gap-2 font-medium text-foreground">
                            <AlertTriangle class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                            Лишние ресурсы удалятся автоматически
                        </p>
                        <ul class="mt-2 space-y-1 text-muted-foreground">
                            <li v-for="overage in downgradeOverages" :key="overage.key">
                                {{ overage.label }} — сейчас {{ overage.used }}, на новом тарифе {{ overage.allowed }},
                                удалим {{ overage.deficit }}
                            </li>
                        </ul>
                    </div>

                    <div
                        v-else-if="variant === 'downgrade'"
                        class="flex items-center gap-2 rounded-lg bg-muted/40 px-3 py-2 text-xs text-muted-foreground"
                    >
                        <Clock class="h-3.5 w-3.5 shrink-0" />
                        Изменения применятся автоматически в конце периода
                    </div>
                </div>

                <footer class="flex flex-col-reverse gap-2 border-t border-border/50 bg-muted/20 px-5 py-4 sm:flex-row sm:justify-end sm:px-6">
                    <Button variant="outline" class="w-full sm:w-auto" :disabled="loading" @click="close">
                        Отмена
                    </Button>
                    <Button
                        class="w-full sm:w-auto"
                        :variant="variant === 'downgrade' ? 'secondary' : 'default'"
                        :disabled="loading"
                        @click="confirm"
                    >
                        {{ loading ? "Обработка…" : confirmLabel }}
                    </Button>
                </footer>
            </div>
        </div>
    </Teleport>
</template>

<style scoped>
.plan-confirm__header {
    border-bottom: 1px solid hsl(var(--border) / 0.5);
}

.plan-confirm__header-glow {
    position: absolute;
    inset: 0;
    background:
        radial-gradient(ellipse 90% 80% at 0% 0%, hsl(var(--primary) / 0.14), transparent 60%),
        radial-gradient(ellipse 70% 60% at 100% 100%, hsl(var(--primary) / 0.08), transparent 55%);
    pointer-events: none;
}

.plan-confirm__icon {
    background: hsl(var(--primary) / 0.12);
    color: hsl(var(--primary));
}

.plan-confirm--downgrade .plan-confirm__header-glow {
    background:
        radial-gradient(ellipse 90% 80% at 0% 0%, rgb(245 158 11 / 0.16), transparent 60%),
        radial-gradient(ellipse 70% 60% at 100% 100%, rgb(245 158 11 / 0.08), transparent 55%);
}

.plan-confirm--downgrade .plan-confirm__icon {
    background: rgb(245 158 11 / 0.14);
    color: rgb(217 119 6);
}
</style>