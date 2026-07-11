<script setup>
import { AlertTriangle, CalendarClock } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";

defineProps({
    pendingDowngrade: { type: Object, required: true },
    loading: { type: Boolean, default: false },
});

const emit = defineEmits(["cancel"]);
</script>

<template>
    <section class="rounded-xl border border-amber-500/35 bg-amber-500/5 px-4 py-4 sm:px-5">
        <div class="flex gap-3">
            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-amber-500/15 text-amber-600 dark:text-amber-400">
                <CalendarClock class="h-5 w-5" />
            </div>

            <div class="min-w-0 flex-1">
                <p class="font-semibold text-foreground">
                    Запланирован переход на тариф «{{ pendingDowngrade.plan_name }}»
                </p>
                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">
                    С <strong class="text-foreground">{{ pendingDowngrade.period_end }}</strong>
                    у вас будет тариф «{{ pendingDowngrade.plan_name }}».
                    До этой даты продолжает действовать текущий тариф и его лимиты.
                </p>

                <div
                    v-if="pendingDowngrade.limit_overages?.length"
                    class="mt-3 rounded-lg border border-amber-500/25 bg-background/70 px-3 py-3"
                >
                    <p class="flex items-center gap-2 text-sm font-medium text-foreground">
                        <AlertTriangle class="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        При смене тарифа лишние ресурсы удалятся автоматически
                    </p>
                    <ul class="mt-2 space-y-1.5 text-sm text-muted-foreground">
                        <li v-for="overage in pendingDowngrade.limit_overages" :key="overage.key">
                            <strong class="text-foreground">{{ overage.label }}</strong> —
                            сейчас {{ overage.used }}, на новом тарифе {{ overage.allowed }},
                            удалим {{ overage.deficit }}
                        </li>
                    </ul>
                </div>

                <Button
                    variant="outline"
                    size="sm"
                    class="mt-4"
                    :disabled="loading"
                    @click="emit('cancel')"
                >
                    {{ loading ? "Отмена…" : "Отменить переход" }}
                </Button>
            </div>
        </div>
    </section>
</template>