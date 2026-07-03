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
    <div class="rounded-2xl border border-border bg-muted/40 p-4">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-sm font-semibold">{{ title }}</h3>
            <span v-if="subtitle" class="text-[11px] text-muted-foreground">{{ subtitle }}</span>
        </div>

        <ul v-if="normalizedProducts.length" class="space-y-3">
            <li
                v-for="product in normalizedProducts"
                :key="product.key"
                class="flex items-center gap-3 rounded-xl bg-background px-3 py-2 shadow-sm ring-1 ring-border"
            >
                <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center overflow-hidden rounded-lg bg-muted text-xs font-semibold uppercase text-muted-foreground">
                    <img v-if="product.image" :src="product.image" :alt="product.title" class="h-full w-full object-cover" />
                    <span v-else>{{ product.initials }}</span>
                </div>

                <div class="flex flex-1 flex-col justify-center">
                    <span class="truncate text-sm font-medium">{{ product.title }}</span>
                    <span v-if="product.id && product.id !== '—'" class="text-xs text-muted-foreground">id {{ product.id }}</span>
                </div>

                <div class="flex flex-col items-end">
                    <span class="text-sm font-semibold" :style="{ color: product.color }">{{ product.displayValue }}</span>
                    <span v-if="product.totalMarginDisplay" class="text-xs text-muted-foreground">{{ product.totalMarginDisplay }}</span>
                </div>
            </li>
        </ul>

        <div v-else class="rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground">
            {{ emptyMessage }}
        </div>
    </div>
</template>