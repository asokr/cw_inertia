<script setup>
defineProps({
    items: { type: Array, default: () => [] },
    zeroOffset: { type: Number, default: 0 },
    showZeroAxis: { type: Boolean, default: false },
});
</script>

<template>
    <div class="rounded-2xl border border-border bg-muted/40 p-4">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold">Структура доходов и расходов</h3>
            <span class="text-[11px] text-muted-foreground">в рублях</span>
        </div>

        <div class="flex flex-col gap-3">
            <div v-for="item in items" :key="item.key" class="flex items-center gap-3">
                <div class="w-36 truncate text-sm font-medium text-muted-foreground">{{ item.label }}</div>
                <div class="relative flex flex-1 items-center gap-2">
                    <div class="relative hidden h-4 flex-1 overflow-hidden rounded-full bg-muted sm:block">
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
                        class="w-28 text-right text-sm font-semibold"
                        :class="item.value < 0 ? 'text-destructive' : 'text-foreground'"
                    >
                        {{ item.display }}
                    </span>
                </div>
            </div>
        </div>
    </div>
</template>