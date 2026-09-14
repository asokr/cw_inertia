<script setup>
import { computed } from "vue";
import VueApexCharts from "vue3-apexcharts";
import { useAppColorMode } from "@/composables/useAppColorMode";
import { formatHistoryChartDate } from "@/utils/wbStockHistoryRows";

const MAX_ITEM_SERIES = 12;

const props = defineProps({
    dates: { type: Array, default: () => [] },
    items: { type: Array, default: () => [] },
    metric: { type: String, default: "stocks" },
});

const isDark = useAppColorMode();

const title = computed(() => (
    props.metric === "orders" ? "Заказы по дням" : "Остатки по дням"
));

const emptyHint = computed(() => (
    props.metric === "orders"
        ? "Отметьте строки в таблице, чтобы увидеть график заказов."
        : "Отметьте строки в таблице, чтобы увидеть график остатков."
));

const yTitle = computed(() => (
    props.metric === "orders" ? "Заказы, шт." : "Остаток, шт."
));

const chartItems = computed(() => {
    const items = Array.isArray(props.items) ? props.items : [];
    if (items.length <= MAX_ITEM_SERIES) {
        return items;
    }

    return items.slice(0, MAX_ITEM_SERIES);
});

const truncated = computed(() => props.items.length > MAX_ITEM_SERIES);

const series = computed(() => {
    const dates = Array.isArray(props.dates) ? props.dates : [];
    const lines = chartItems.value.map((item) => ({
        name: item.label,
        data: dates.map((_, index) => toPoint(item.series?.[index])),
    }));

    if (props.items.length > 1) {
        lines.unshift({
            name: "Всего",
            data: dates.map((_, index) => sumAt(props.items, index)),
        });
    }

    return lines;
});

const datesCount = computed(() => (Array.isArray(props.dates) ? props.dates.length : 0));

const colors = computed(() => {
    const palette = [
        "hsl(var(--primary))",
        "hsl(172 66% 38%)",
        "hsl(32 95% 48%)",
        "hsl(350 75% 50%)",
        "hsl(199 89% 42%)",
        "hsl(84 60% 38%)",
        "hsl(262 48% 52%)",
        "hsl(14 88% 52%)",
        "hsl(210 70% 46%)",
        "hsl(48 92% 46%)",
        "hsl(330 70% 48%)",
        "hsl(152 55% 36%)",
        "hsl(222 40% 42%)",
    ];
    const count = series.value.length;
    if (count <= palette.length) {
        return palette.slice(0, count);
    }

    return Array.from({ length: count }, (_, index) => palette[index % palette.length]);
});

const options = computed(() => ({
    chart: {
        type: "line",
        fontFamily: "inherit",
        background: "transparent",
        toolbar: { show: false },
        zoom: { enabled: false },
        animations: { enabled: props.items.length <= 8 },
    },
    colors: colors.value,
    stroke: {
        width: series.value.map((line) => (line.name === "Всего" ? 3 : 2)),
        curve: "smooth",
        connectNulls: false,
    },
    markers: {
        size: datesCount.value <= 16 ? 3 : 0,
        strokeWidth: 0,
        hover: { size: 5 },
    },
    dataLabels: { enabled: false },
    grid: {
        borderColor: "hsl(var(--border))",
        strokeDashArray: 4,
        padding: { left: 8, right: 12 },
    },
    legend: {
        position: "bottom",
        fontSize: "12px",
        labels: { colors: "hsl(var(--muted-foreground))" },
        itemMargin: { horizontal: 8, vertical: 4 },
    },
    tooltip: {
        shared: true,
        intersect: false,
        theme: isDark.value ? "dark" : "light",
        x: { formatter: (_, { dataPointIndex }) => tooltipDate(dataPointIndex) },
    },
    xaxis: {
        categories: (props.dates || []).map(formatHistoryChartDate),
        labels: {
            rotate: datesCount.value > 20 ? -45 : 0,
            style: { colors: "hsl(var(--muted-foreground))", fontSize: "11px" },
        },
        axisBorder: { color: "hsl(var(--border))" },
        axisTicks: { color: "hsl(var(--border))" },
        tooltip: { enabled: false },
    },
    yaxis: {
        min: 0,
        decimalsInFloat: 0,
        title: {
            text: yTitle.value,
            style: { color: "hsl(var(--muted-foreground))", fontSize: "12px", fontWeight: 500 },
        },
        labels: {
            style: { colors: "hsl(var(--muted-foreground))", fontSize: "11px" },
            formatter: (value) => (Number.isFinite(value) ? String(Math.round(value)) : ""),
        },
    },
    noData: {
        text: emptyHint.value,
        style: { color: "hsl(var(--muted-foreground))", fontSize: "14px" },
    },
}));

function toPoint(value) {
    if (value === null || value === undefined) {
        return null;
    }
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function sumAt(items, index) {
    let total = 0;
    let hasValue = false;
    for (const item of items) {
        const point = toPoint(item.series?.[index]);
        if (point === null) {
            continue;
        }
        total += point;
        hasValue = true;
    }

    return hasValue ? total : null;
}

function tooltipDate(index) {
    const date = props.dates?.[index];
    if (!date) {
        return "";
    }
    const parts = String(date).split("-");
    if (parts.length !== 3) {
        return date;
    }

    return `${parts[2]}.${parts[1]}.${parts[0]}`;
}
</script>

<template>
    <div class="space-y-3">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <div>
                <h3 class="font-medium">{{ title }}</h3>
                <p class="text-sm text-muted-foreground">
                    <template v-if="items.length === 0">{{ emptyHint }}</template>
                    <template v-else-if="items.length === 1">По выбранной строке</template>
                    <template v-else>По {{ items.length }} выбранным строкам</template>
                </p>
            </div>
            <p v-if="truncated" class="text-xs text-muted-foreground">
                На графике первые {{ MAX_ITEM_SERIES }} строк и сумма всех выбранных.
            </p>
        </div>

        <div v-if="items.length === 0" class="flex h-56 items-center justify-center rounded-md border border-dashed bg-muted/30 px-4 text-center text-sm text-muted-foreground">
            {{ emptyHint }}
        </div>
        <VueApexCharts
            v-else
            :key="`${metric}-${items.length}-${(dates || []).join(',')}`"
            type="line"
            height="320"
            :options="options"
            :series="series"
        />
    </div>
</template>
