<script setup>
import { formatCredits } from "@/utils/credits";

defineProps({
    entries: { type: Array, default: () => [] },
    title: { type: String, default: "История кредитов" },
    emptyText: { type: String, default: "Операций с кредитами пока нет." },
});

function isCredit(entry) {
    return entry.direction === "credit";
}
</script>

<template>
    <div>
        <h3 class="mb-3 font-medium">{{ title }}</h3>
        <div class="max-h-96 space-y-0 overflow-y-auto divide-y">
            <div
                v-for="entry in entries"
                :key="entry.id"
                class="py-2 text-sm first:pt-0"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-medium">{{ entry.user_label }}</p>
                            <span
                                v-if="entry.type === 'hold'"
                                class="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-medium text-amber-900"
                            >Зарезервировано</span>
                        </div>
                        <p class="mt-1 text-xs text-muted-foreground">{{ entry.created_at }}</p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p
                            class="font-semibold tabular-nums"
                            :class="isCredit(entry) ? 'text-emerald-600' : 'text-red-600'"
                        >
                            {{ isCredit(entry) ? "+" : "−" }}{{ formatCredits(entry.amount) }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            Осталось {{ formatCredits(entry.available_after ?? 0) }}
                        </p>
                    </div>
                </div>
            </div>
            <p v-if="!entries.length" class="text-sm text-muted-foreground">
                {{ emptyText }}
            </p>
        </div>
    </div>
</template>
