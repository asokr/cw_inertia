<script setup>
import { computed } from "vue";

const props = defineProps({
    dates: { type: Array, default: () => [] },
    values: { type: Array, default: () => [] },
    latestDate: { type: String, default: null },
});

const columns = computed(() => {
    const dates = Array.isArray(props.dates) ? props.dates : [];
    const values = Array.isArray(props.values) ? props.values : [];

    return dates
        .map((date, index) => ({
            date,
            value: index < values.length ? values[index] : null,
        }))
        .reverse();
});

function label(value) {
    if (value === null || value === undefined) {
        return "—";
    }

    return String(value);
}

function valueClass(value) {
    if (value === null || value === undefined) {
        return "text-muted-foreground";
    }
    if (value === 0) {
        return "font-medium text-destructive";
    }

    return "font-medium";
}
</script>

<template>
    <td
        v-for="column in columns"
        :key="column.date"
        class="min-w-[3.25rem] border-l px-1.5 py-2 text-center tabular-nums"
        :class="column.date === latestDate ? 'bg-muted/50' : ''"
    >
        <span :class="valueClass(column.value)">{{ label(column.value) }}</span>
    </td>
</template>
