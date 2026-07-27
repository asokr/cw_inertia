<script setup>
import { computed } from "vue";
import { Check } from "lucide-vue-next";

const props = defineProps({
    steps: {
        type: Array,
        required: true,
    },
    currentStep: {
        type: Number,
        required: true,
    },
});

const items = computed(() =>
    props.steps.map((step) => {
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
    <aside class="rounded-xl border border-border/70 bg-card/60 p-4 backdrop-blur">
        <p class="mb-4 text-xs font-semibold uppercase tracking-[0.14em] text-muted-foreground">
            Этапы эксперимента
        </p>
        <ol class="space-y-1">
            <li
                v-for="(step, index) in items"
                :key="step.id"
                class="relative flex gap-3 rounded-lg px-2 py-2.5 transition"
                :class="{
                    'bg-primary/10': step.state === 'current',
                    'opacity-70': step.state === 'upcoming',
                }"
            >
                <div class="relative flex flex-col items-center">
                    <div
                        class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full border text-xs font-semibold"
                        :class="{
                            'border-primary bg-primary text-primary-foreground': step.state === 'current',
                            'border-emerald-500/50 bg-emerald-500/15 text-emerald-600 dark:text-emerald-400': step.state === 'completed',
                            'border-border bg-muted/40 text-muted-foreground': step.state === 'upcoming',
                        }"
                    >
                        <Check v-if="step.state === 'completed'" class="h-3.5 w-3.5" />
                        <span v-else>{{ step.id }}</span>
                    </div>
                    <div
                        v-if="index < items.length - 1"
                        class="mt-1 w-px flex-1 min-h-[1.25rem]"
                        :class="step.state === 'completed' ? 'bg-emerald-500/40' : 'bg-border'"
                    />
                </div>
                <div class="min-w-0 pb-2">
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
                    <p v-if="step.description" class="mt-0.5 text-xs leading-snug text-muted-foreground">
                        {{ step.description }}
                    </p>
                </div>
            </li>
        </ol>
    </aside>
</template>
