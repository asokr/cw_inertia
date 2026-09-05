<script setup>
import { computed, ref } from "vue";
import { ChevronDown, ChevronRight, Loader2 } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import StockHistoryQtyCells from "./StockHistoryQtyCells.vue";

const props = defineProps({
    product: { type: Object, required: true },
    dates: { type: Array, default: () => [] },
    expanded: { type: Boolean, default: false },
    detail: { type: Object, default: null },
    loading: { type: Boolean, default: false },
    latestDate: { type: String, default: null },
});

const emit = defineEmits(["toggle"]);

const openClusters = ref({});

const displayName = computed(() => props.product.name || props.product.offer_id || "Товар");
const dateCount = computed(() => (Array.isArray(props.dates) ? props.dates.length : 0));

function toggleCluster(name) {
    openClusters.value = {
        ...openClusters.value,
        [name]: !openClusters.value[name],
    };
}

function isClusterOpen(name) {
    return Boolean(openClusters.value[name]);
}
</script>

<template>
    <tr
        class="group cursor-pointer border-b hover:bg-accent/40"
        @click="emit('toggle')"
    >
        <td class="sticky left-0 z-10 min-w-[16rem] max-w-[20rem] bg-card px-3 py-2 group-hover:bg-muted">
            <div class="flex items-start gap-2">
                <div class="mt-1 text-muted-foreground">
                    <ChevronDown v-if="expanded" class="h-4 w-4" />
                    <ChevronRight v-else class="h-4 w-4" />
                </div>
                <div class="h-10 w-10 shrink-0 overflow-hidden rounded-md bg-muted">
                    <img
                        v-if="product.image_url"
                        :src="product.image_url"
                        :alt="displayName"
                        class="h-full w-full object-cover"
                    />
                </div>
                <div class="min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="truncate font-medium">{{ displayName }}</p>
                        <Badge v-if="product.stockout" variant="warning">закончился на складе</Badge>
                    </div>
                    <p v-if="product.offer_id" class="truncate text-xs text-muted-foreground">
                        Артикул {{ product.offer_id }}
                    </p>
                </div>
            </div>
        </td>
        <StockHistoryQtyCells
            :dates="dates"
            :values="product.series || []"
            :latest-date="latestDate"
        />
    </tr>

    <tr v-if="expanded && loading">
        <td class="sticky left-0 z-10 bg-card px-3 py-2 text-sm text-muted-foreground" />
        <td :colspan="Math.max(dateCount, 1)" class="px-3 py-2 text-sm text-muted-foreground">
            <span class="inline-flex items-center gap-2">
                <Loader2 class="h-4 w-4 animate-spin" />
                Загружаем склады…
            </span>
        </td>
    </tr>

    <tr v-else-if="expanded && !detail?.clusters?.length">
        <td class="sticky left-0 z-10 bg-card px-3 py-2" />
        <td :colspan="Math.max(dateCount, 1)" class="px-3 py-2 text-sm text-muted-foreground">
            Нет складов с остатками за эти дни.
        </td>
    </tr>

    <template v-else-if="expanded">
        <template v-for="cluster in detail.clusters" :key="cluster.name">
            <tr
                class="group cursor-pointer border-b bg-background/60 hover:bg-accent/30"
                @click.stop="toggleCluster(cluster.name)"
            >
                <td class="sticky left-0 z-10 bg-card px-3 py-2 pl-10 group-hover:bg-muted">
                    <div class="flex items-center gap-2">
                        <ChevronDown v-if="isClusterOpen(cluster.name)" class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <ChevronRight v-else class="h-3.5 w-3.5 shrink-0 text-muted-foreground" />
                        <p class="truncate text-sm font-medium">{{ cluster.name }}</p>
                    </div>
                </td>
                <StockHistoryQtyCells
                    :dates="dates"
                    :values="cluster.series || []"
                    :latest-date="latestDate"
                />
            </tr>
            <tr
                v-for="warehouse in (isClusterOpen(cluster.name) ? cluster.warehouses : [])"
                :key="warehouse.warehouse_key"
                class="border-b"
            >
                <td class="sticky left-0 z-10 bg-card px-3 py-2 pl-16">
                    <p class="truncate text-sm">{{ warehouse.warehouse_name }}</p>
                    <p v-if="warehouse.empty_since_label" class="text-xs text-muted-foreground">
                        нет в наличии с {{ warehouse.empty_since_label }}
                    </p>
                </td>
                <StockHistoryQtyCells
                    :dates="dates"
                    :values="warehouse.series || []"
                    :latest-date="latestDate"
                />
            </tr>
        </template>
    </template>
</template>
