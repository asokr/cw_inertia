<script setup>
defineProps({
    items: { type: Array, default: () => [] },
    zeroOffset: { type: Number, default: 0 },
    showZeroAxis: { type: Boolean, default: false },
});
</script>

<template>
    <div class="min-w-0 rounded-2xl border border-border bg-muted/40 p-3 sm:p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-1 sm:mb-4">
            <h3 class="text-sm font-semibold">Структура доходов и расходов</h3>
            <span class="text-[11px] text-muted-foreground">в рублях</span>
        </div>

        <div class="flex flex-col gap-3">
            <div
                v-for="item in items"
                :key="item.key"
                class="flex min-w-0 flex-col gap-1.5 sm:flex-row sm:items-center sm:gap-3"
            >
                <div class="flex items-center justify-between gap-2 sm:w-32 sm:shrink-0 sm:justify-start lg:w-36">
                    <span class="truncate text-sm font-medium text-muted-foreground">{{ item.label }}</span>
                    <span
                        class="shrink-0 text-sm font-semibold sm:hidden"
                        :class="item.value < 0 ? 'text-destructive' : 'text-foreground'"
                    >
                        {{ item.display }}
                    </span>
                </div>
                <div class="relative flex min-w-0 flex-1 items-center gap-2">
                    <div class="relative h-3.5 w-full min-w-0 flex-1 overflow-hidden rounded-full bg-muted sm:h-4">
                        <div
                            v-if="showZeroAxis"
                            class="absolute top-0 bottom-0 w-0.5 -translate-x-1/2 rounded-full bg-muted-foreground/50"
                            :style="{ left: `${zeroOffset}%` }"
                        />
                        <div
                            v-if="item.negativeWidth > 0"
                            :class="item.color"
                            class="absolute top-0 bottom-0 rounded-l-full"
                            :style="{ left: `${zeroOffset - item.negativeWidth}%`, width: `${item.negativeWidth}%` }"
                        />
                        <div
                            v-if="item.positiveWidth > 0"
                            :class="item.color"
                            class="absolute top-0 bottom-0 rounded-r-full"
                            :style="{ left: `${zeroOffset}%`, width: `${item.positiveWidth}%` }"
                        />
                    </div>
                    <span
                        class="hidden w-24 shrink-0 text-right text-sm font-semibold sm:block lg:w-28"
                        :class="item.value < 0 ? 'text-destructive' : 'text-foreground'"
                    >
                        {{ item.display }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>
