<script setup>
import { ref, watch } from "vue";
import { ChevronRight, ImageOff } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import { resolveAbTestStatus } from "./abTestStatus";

const props = defineProps({
    items: {
        type: Array,
        default: () => [],
    },
    search: {
        type: String,
        default: "",
    },
});

const emit = defineEmits(["select"]);

const expanded = ref(new Set());

watch(
    () => [props.items, props.search],
    () => {
        if (String(props.search || "").trim() !== "") {
            expanded.value = new Set(props.items.map((item) => item.group_key));
            return;
        }
        expanded.value = new Set();
    },
    { immediate: true },
);

function sizesLabel(count) {
    const n = Number(count) || 0;
    const mod10 = n % 10;
    const mod100 = n % 100;
    if (mod10 === 1 && mod100 !== 11) {
        return `${n} размер`;
    }
    if (mod10 >= 2 && mod10 <= 4 && (mod100 < 12 || mod100 > 14)) {
        return `${n} размера`;
    }

    return `${n} размеров`;
}

function isOpen(group) {
    return expanded.value.has(group.group_key);
}

function toggle(group) {
    const next = new Set(expanded.value);
    if (next.has(group.group_key)) {
        next.delete(group.group_key);
    } else {
        next.add(group.group_key);
    }
    expanded.value = next;
}

function statusOf(value) {
    return resolveAbTestStatus(value);
}

function onSelectSku(sku) {
    emit("select", sku);
}
</script>

<template>
    <div class="overflow-auto rounded-md border" style="max-height: calc(100dvh - 22rem)">
        <div v-if="items.length === 0" class="px-4 py-8 text-center text-sm text-muted-foreground">
            Список товаров пуст. Нажмите «Обновить список товаров».
        </div>

        <div v-else class="divide-y">
            <div v-for="group in items" :key="group.group_key">
                <button
                    type="button"
                    class="flex w-full items-center gap-3 px-4 py-3 text-left transition-colors hover:bg-muted/50"
                    :aria-expanded="isOpen(group)"
                    @click="toggle(group)"
                >
                    <ChevronRight
                        class="h-4 w-4 shrink-0 text-muted-foreground transition-transform"
                        :class="isOpen(group) ? 'rotate-90' : ''"
                    />
                    <img
                        v-if="group.photo_url"
                        :src="group.photo_url"
                        alt=""
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        class="h-14 w-11 shrink-0 rounded-md border border-border/60 bg-muted object-contain"
                    />
                    <div
                        v-else
                        class="flex h-14 w-11 shrink-0 items-center justify-center rounded-md border border-dashed border-border bg-muted/50 text-muted-foreground"
                    >
                        <ImageOff class="h-4 w-4" />
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="line-clamp-2 text-sm font-medium leading-snug" :title="group.title">
                            {{ group.title || "Без названия" }}
                        </p>
                        <p class="mt-0.5 text-xs text-muted-foreground">
                            {{ sizesLabel(group.sku_count) }}
                        </p>
                    </div>
                    <Badge
                        v-if="group.test_status && group.test_status !== 'not_created'"
                        :variant="statusOf(group.test_status).variant"
                        class="shrink-0"
                    >
                        {{ statusOf(group.test_status).label }}
                    </Badge>
                </button>

                <div v-if="isOpen(group)" class="border-t bg-muted/20 px-4 py-2 pb-3">
                    <div class="overflow-hidden rounded-md border bg-background sm:ml-7">
                        <div
                            class="hidden grid-cols-[minmax(0,1fr)_7rem_8rem] gap-2 border-b bg-muted/40 px-3 py-1.5 text-xs font-medium text-muted-foreground sm:grid"
                        >
                            <span>Артикул продавца</span>
                            <span>SKU</span>
                            <span>Статус теста</span>
                        </div>
                        <button
                            v-for="sku in group.skus"
                            :key="sku.id"
                            type="button"
                            class="flex w-full flex-col gap-1 border-b px-3 py-2.5 text-left text-sm last:border-b-0 transition-colors hover:bg-muted/50 sm:grid sm:grid-cols-[minmax(0,1fr)_7rem_8rem] sm:items-center sm:gap-2"
                            @click="onSelectSku(sku)"
                        >
                            <span class="truncate font-medium">{{ sku.offer_id || sku.vendor_code || "—" }}</span>
                            <span class="tabular-nums text-muted-foreground sm:text-foreground">
                                <span class="sm:hidden">SKU </span>{{ sku.sku || "—" }}
                            </span>
                            <span>
                                <Badge :variant="statusOf(sku.test_status).variant">
                                    {{ statusOf(sku.test_status).label }}
                                </Badge>
                            </span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
