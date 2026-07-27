<script setup>
import { computed } from "vue";

const props = defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: "" },
    products: { type: Array, default: () => [] },
    palette: { type: Array, default: () => ["#4F46E5", "#10B981", "#A855F7", "#F97316", "#0EA5E9"] },
    emptyMessage: { type: String, default: "Нет данных." },
    valueSuffix: { type: String, default: "%" },
});

const fallbackPalette = ["#4F46E5", "#10B981", "#A855F7", "#F97316", "#0EA5E9"];
const effectivePalette = computed(() => (props.palette.length ? props.palette : fallbackPalette));

function formatValue(value, suffix) {
    const absolute = Math.abs(value);
    if (absolute === 0) return `0${suffix}`;
    if (absolute >= 10) {
        return `${value > 0 ? Math.round(value) : -Math.round(absolute)}${suffix}`;
    }
    const precise = Number(absolute.toFixed(1));
    return `${value > 0 ? precise : -precise}${suffix}`;
}

function formatCurrency(value) {
    return new Intl.NumberFormat("ru-RU", {
        style: "currency",
        currency: "RUB",
        maximumFractionDigits: 0,
    }).format(value);
}

const normalizedProducts = computed(() => props.products
    .map((product, index) => {
        const valueRaw = Number(product?.percentValue);
        if (!Number.isFinite(valueRaw)) return null;

        const color = effectivePalette.value[index % effectivePalette.value.length];
        const totalMarginRaw = Number(product?.total_margin);
        const totalMarginDisplay = Number.isFinite(totalMarginRaw) ? formatCurrency(totalMarginRaw) : "";

        const initials = product?.title
            ? product.title.split(/\s+/).filter(Boolean).slice(0, 2).map((token) => token[0]).join("").toUpperCase()
            : "—";

        return {
            key: product?.key ?? `product-${index}`,
            id: product?.id ? String(product.id) : "—",
            title: product?.title || "—",
            image: product?.image || null,
            color,
            displayValue: formatValue(valueRaw, props.valueSuffix),
            totalMarginDisplay,
            initials,
        };
    })
    .filter(Boolean));
</script>

<template>
    <div class="min-w-0 rounded-2xl border border-border bg-muted/40 p-3 sm:p-4">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-1 sm:mb-4">
            <h3 class="min-w-0 text-sm font-semibold">{{ title }}</h3>
            <span v-if="subtitle" class="text-[11px] text-muted-foreground">{{ subtitle }}</span>
        </div>

        <ul v-if="normalizedProducts.length" class="space-y-2 sm:space-y-3">
            <li
                v-for="product in normalizedProducts"
                :key="product.key"
                class="flex min-w-0 items-center gap-2 rounded-xl bg-background px-2.5 py-2 shadow-sm ring-1 ring-border sm:gap-3 sm:px-3"
            >
                <div class="flex h-9 w-9 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-xs font-semibold uppercase text-muted-foreground sm:h-10 sm:w-10">
                    <img v-if="product.image" :src="product.image" :alt="product.title" class="h-full w-full object-cover" />
                    <span v-else>{{ product.initials }}</span>
                </div>

                <div class="flex min-w-0 flex-1 flex-col justify-center">
                    <span class="truncate text-sm font-medium">{{ product.title }}</span>
                    <span v-if="product.id && product.id !== '—'" class="truncate text-xs text-muted-foreground">id {{ product.id }}</span>
                </div>

                <div class="flex shrink-0 flex-col items-end">
                    <span class="text-sm font-semibold" :style="{ color: product.color }">{{ product.displayValue }}</span>
                    <span v-if="product.totalMarginDisplay" class="text-xs text-muted-foreground">{{ product.totalMarginDisplay }}</span>
                </div>
            </li>
        </ul>

        <div v-else class="rounded-xl border border-dashed p-4 text-center text-sm text-muted-foreground sm:p-6">
            {{ emptyMessage }}
        </div>
    </div>
</template>