<script setup>
import { computed } from "vue";
import { Check } from "lucide-vue-next";

const props = defineProps({
    currentStep: {
        type: Number,
        required: true,
    },
});

const steps = [
    { id: 1, title: "Загрузка", description: "Отчёт по акции" },
    { id: 2, title: "Расчёт", description: "Рентабельность" },
    { id: 3, title: "Результаты", description: "Экспорт и репрайсер" },
];

const items = computed(() =>
    steps.map((step) => {
        let state = "upcoming";
        if (step.id < props.currentStep) {
            state = "completed";
        } else if (step.id === props.currentStep) {
            state = "current";
        }
        return { ...step, state };
    }),
);
</script>

<template>
    <nav aria-label="Этапы расчёта" class="w-full">
        <ol class="grid gap-2 sm:grid-cols-3">
            <li
                v-for="step in items"
                :key="step.id"
                class="flex items-center gap-3 rounded-xl border px-3 py-3 transition"
                :class="{
                    'border-primary/40 bg-primary/5': step.state === 'current',
                    'border-emerald-500/30 bg-emerald-500/5': step.state === 'completed',
                    'border-border/70 bg-card/40 opacity-70': step.state === 'upcoming',
                }"
            >
                <div
                    class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                    :class="{
                        'border-primary bg-primary text-primary-foreground': step.state === 'current',
                        'border-emerald-500/50 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400':
                            step.state === 'completed',
                        'border-border bg-muted/40 text-muted-foreground': step.state === 'upcoming',
                    }"
                >
                    <Check v-if="step.state === 'completed'" class="h-3.5 w-3.5" />
                    <span v-else>{{ step.id }}</span>
                </div>
                <div class="min-w-0">
                    <p
                        class="text-sm font-medium leading-tight"
                        :class="{
                            'text-primary': step.state === 'current',
                            'text-foreground': step.state === 'completed',
                            'text-muted-foreground': step.state === 'upcoming',
                        }"
                    >
                        {{ step.title }}
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground">{{ step.description }}</p>
                </div>
            </li>
        </ol>
    </nav>
</template>
