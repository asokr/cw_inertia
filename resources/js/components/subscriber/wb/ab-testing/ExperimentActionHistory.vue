<script setup>
import { computed } from "vue";
import { ImageOff } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    rows: {
        type: Array,
        default: () => [],
    },
});

const items = computed(() => (Array.isArray(props.rows) ? props.rows : []));

function formatInstalledAt(iso) {
    if (!iso) {
        return "—";
    }
    try {
        return new Date(iso).toLocaleString("ru-RU", {
            timeZone: "Europe/Moscow",
            day: "2-digit",
            month: "2-digit",
            year: "numeric",
            hour: "2-digit",
            minute: "2-digit",
        }).replace(",", "") + " МСК";
    } catch {
        return "—";
    }
}

function formatInt(value) {
    if (value == null || value === "") {
        return "—";
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return "—";
    }
    return Math.round(n).toLocaleString("ru-RU");
}

function formatCtr(value) {
    if (value == null || value === "") {
        return "—";
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return "—";
    }
    // Match competitor UI: number without % sign (e.g. 7.14, 15, 0)
    if (Number.isInteger(n)) {
        return String(n);
    }
    return String(Math.round(n * 100) / 100);
}
function formatDuration(row) {
    if (row?.in_progress) {
        return "В процессе";
    }
    if (row?.duration_label != null && row.duration_label !== "") {
        return String(row.duration_label);
    }
    if (row?.duration_minutes != null) {
        return String(row.duration_minutes);
    }
    return "—";
}
</script>

<template>
    <Card class="overflow-hidden p-0">
        <div class="flex items-center justify-between gap-2 border-b border-border/60 px-4 py-3 sm:px-5">
            <p class="text-sm font-semibold text-foreground">История действий</p>
        </div>

        <div v-if="!items.length" class="px-4 py-8 text-center text-sm text-muted-foreground sm:px-5">
            Пока нет кругов — появятся после запуска эксперимента.
        </div>

        <div v-else class="max-h-[28rem] overflow-auto">
            <table class="w-full min-w-[640px] border-collapse text-left text-xs">
                <thead class="sticky top-0 z-[1] bg-muted/80 backdrop-blur">
                    <tr class="border-b border-border/60 text-[11px] font-medium text-muted-foreground">
                        <th class="whitespace-nowrap px-3 py-2.5 font-medium sm:px-4">
                            Время установки ↓
                        </th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium">Контент</th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium">Вариант</th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium tabular-nums">Клики</th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium tabular-nums">Показы</th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium tabular-nums">CTR</th>
                        <th class="whitespace-nowrap px-2 py-2.5 font-medium tabular-nums">Круг</th>
                        <th class="whitespace-nowrap px-3 py-2.5 font-medium sm:px-4">
                            Время круга (мин)
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in items"
                        :key="row.id"
                        class="border-b border-border/40 last:border-0 even:bg-muted/20"
                    >
                        <td class="whitespace-nowrap px-3 py-2 tabular-nums text-foreground sm:px-4">
                            {{ formatInstalledAt(row.installed_at) }}
                        </td>
                        <td class="px-2 py-2">
                            <div
                                class="flex h-9 w-9 items-center justify-center overflow-hidden rounded border border-border/60 bg-muted/40"
                            >
                                <img
                                    v-if="row.preview_url"
                                    :src="row.preview_url"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    loading="lazy"
                                />
                                <ImageOff v-else class="h-4 w-4 text-muted-foreground" />
                            </div>
                        </td>
                        <td class="px-2 py-2 tabular-nums text-foreground">
                            {{ row.variant ?? "—" }}
                        </td>
                        <td class="px-2 py-2 tabular-nums text-foreground">
                            {{ formatInt(row.clicks) }}
                        </td>
                        <td class="px-2 py-2 tabular-nums text-foreground">
                            {{ formatInt(row.views ?? row.impressions) }}
                        </td>
                        <td class="px-2 py-2 tabular-nums text-foreground">
                            {{ formatCtr(row.ctr) }}
                        </td>
                        <td class="px-2 py-2 tabular-nums text-foreground">
                            {{ row.round ?? "—" }}
                        </td>
                        <td
                            class="whitespace-nowrap px-3 py-2 tabular-nums sm:px-4"
                            :class="row.in_progress ? 'text-muted-foreground' : 'text-foreground'"
                        >
                            {{ formatDuration(row) }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>
