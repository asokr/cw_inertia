<script setup>
import { Check, CircleHelp } from "lucide-vue-next";
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";

const props = defineProps({
    plan: { type: Object, required: true },
    balance: { type: Number, default: 0 },
    disabled: { type: Boolean, default: false },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["select", "cancel"]);

/** Prefer server-prepared display_limits (unified WB cabinets + DB names). */
const limitEntries = computed(() => {
    if (Array.isArray(props.plan.display_limits) && props.plan.display_limits.length) {
        return props.plan.display_limits;
    }

    return [];
});

const formattedPrice = computed(() =>
    new Intl.NumberFormat("ru-RU", { style: "currency", currency: "RUB", maximumFractionDigits: 0 }).format(
        Number(props.plan.price ?? 0)
    )
);

const shortfall = computed(() => Math.max(0, Math.ceil(Number(props.plan.price ?? 0) - Number(props.balance ?? 0))));

const ctaLabel = computed(() => {
    if (props.plan.is_pending_downgrade) return "Отменить переход";
    if (props.plan.is_current) return "Текущий тариф";
    if (props.plan.lower) return "Понизить тариф";
    if (shortfall.value > 0) return `Пополнить ${shortfall.value.toLocaleString("ru-RU")} ₽ и перейти`;
    return "Перейти на тариф";
});

function handleSelect() {
    if (props.disabled || props.plan.is_current || props.loading) {
        return;
    }

    if (props.plan.is_pending_downgrade) {
        emit("cancel", props.plan);
        return;
    }

    emit("select", props.plan);
}
</script>

<template>
    <article
        class="plan-card group relative flex flex-col rounded-2xl border p-6 transition-all duration-300"
        :class="
            plan.recommended
                ? 'border-primary/50 bg-card shadow-lg shadow-primary/10 ring-1 ring-primary/20'
                : 'border-border/60 bg-card/80 hover:border-border hover:shadow-md'
        "
    >
        <div v-if="plan.recommended" class="absolute -top-3 left-1/2 -translate-x-1/2">
            <Badge class="bg-primary px-3 py-0.5 text-xs font-semibold text-primary-foreground shadow-sm">
                Рекомендуем
            </Badge>
        </div>

        <div class="mb-6 flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                <h3 class="truncate text-xl font-bold tracking-tight">{{ plan.name }}</h3>
                <!-- eslint-disable-next-line vue/no-v-html -->
                <div
                    v-if="plan.description"
                    class="plan-card__description mt-1 text-sm text-muted-foreground [&_a]:font-medium [&_a]:text-primary [&_a]:underline [&_li]:ml-4 [&_ol]:list-decimal [&_p+p]:mt-2 [&_ul]:list-disc"
                    v-html="plan.description"
                />
            </div>
            <Badge
                v-if="plan.is_pending_downgrade"
                class="shrink-0 whitespace-nowrap border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300"
            >
                С конца периода
            </Badge>
            <Badge v-else-if="plan.is_current" class="shrink-0 whitespace-nowrap" variant="secondary">Текущий</Badge>
        </div>

        <div class="mb-6">
            <p class="text-4xl font-semibold tabular-nums tracking-tight text-foreground">{{ formattedPrice }}</p>
            <p class="mt-1 text-sm text-muted-foreground">на {{ plan.duration }} дней</p>
        </div>

        <ul v-if="limitEntries.length" class="mb-8 flex-1 space-y-2.5">
            <li v-for="item in limitEntries" :key="item.key" class="flex items-start gap-2 text-sm">
                <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                <span class="min-w-0">
                    <span class="inline-flex items-center gap-1 text-muted-foreground">
                        {{ item.label }}
                        <span
                            v-if="item.hint"
                            class="group/hint relative inline-flex shrink-0"
                        >
                            <CircleHelp
                                class="h-3.5 w-3.5 cursor-help text-muted-foreground/70 transition-colors group-hover/hint:text-primary"
                                aria-hidden="true"
                            />
                            <span
                                role="tooltip"
                                class="pointer-events-none invisible absolute bottom-full left-1/2 z-50 mb-1.5 w-52 -translate-x-1/2 rounded-md border border-neutral-200 bg-white px-2.5 py-1.5 text-center text-xs font-normal leading-snug text-neutral-900 opacity-0 shadow-md transition-opacity group-hover/hint:visible group-hover/hint:opacity-100 group-focus-within/hint:visible group-focus-within/hint:opacity-100"
                            >
                                {{ item.hint }}
                            </span>
                        </span>
                        :
                    </span>
                    <strong class="ml-1 tabular-nums text-foreground">{{ item.value }}</strong>
                </span>
            </li>
        </ul>
        <p v-else class="mb-8 flex-1 text-sm text-muted-foreground">
            Все инструменты платформы без ограничений по кабинетам
        </p>

        <Button
            class="w-full"
            :variant="plan.is_pending_downgrade ? 'outline' : plan.recommended && !plan.is_current ? 'default' : 'outline'"
            :disabled="disabled || plan.is_current || loading"
            @click="handleSelect"
        >
            {{ ctaLabel }}
        </Button>
    </article>
</template>
