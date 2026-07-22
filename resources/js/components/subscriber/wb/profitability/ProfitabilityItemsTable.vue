<script setup>
import { computed, onMounted, ref, watch } from "vue";
import EditableDataTable from "@/components/subscriber/tools/EditableDataTable.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";

const props = defineProps({
    title: { type: String, required: true },
    items: { type: Array, default: () => [] },
    columns: { type: Array, required: true },
    maxHeight: { type: String, default: "24rem" },
    /** Ленивая подгрузка с сервера по pages */
    lazy: { type: Boolean, default: false },
    /** Базовый URL items endpoint (без query), group добавляется отдельно */
    itemsUrl: { type: String, default: "" },
    /** group=sales|returns|logistics|other */
    group: { type: String, default: "" },
    perPage: { type: Number, default: 100 },
});

const search = ref("");
const rows = ref([]);
const page = ref(1);
const hasMore = ref(false);
const total = ref(0);
const loading = ref(false);
const loadingMore = ref(false);
const error = ref("");

let searchTimer = null;

const displayItems = computed(() => {
    if (props.lazy) {
        return rows.value;
    }

    const query = search.value.trim().toLowerCase();
    if (!query) {
        return props.items;
    }

    return props.items.filter((row) =>
        Object.values(row).some((value) => String(value ?? "").toLowerCase().includes(query)),
    );
});

function buildUrl(pageNum) {
    const url = new URL(props.itemsUrl, window.location.origin);
    url.searchParams.set("group", props.group);
    url.searchParams.set("page", String(pageNum));
    url.searchParams.set("per_page", String(props.perPage));
    const q = search.value.trim();
    if (q) {
        url.searchParams.set("search", q);
    }
    return url.toString();
}

async function fetchPage(pageNum, { append = false } = {}) {
    if (!props.lazy || !props.itemsUrl || !props.group) {
        return;
    }

    if (append) {
        loadingMore.value = true;
    } else {
        loading.value = true;
    }
    error.value = "";

    try {
        const response = await fetch(buildUrl(pageNum), {
            headers: {
                Accept: "application/json",
                "X-Requested-With": "XMLHttpRequest",
            },
            credentials: "same-origin",
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        const data = Array.isArray(payload.data) ? payload.data : [];
        const meta = payload.meta ?? {};

        rows.value = append ? [...rows.value, ...data] : data;
        page.value = Number(meta.page ?? pageNum);
        hasMore.value = Boolean(meta.has_more);
        total.value = Number(meta.total ?? rows.value.length);
    } catch (e) {
        error.value = "Не удалось загрузить данные таблицы";
        if (!append) {
            rows.value = [];
            hasMore.value = false;
            total.value = 0;
        }
    } finally {
        loading.value = false;
        loadingMore.value = false;
    }
}

function onSearchInput() {
    if (!props.lazy) {
        return;
    }

    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        page.value = 1;
        fetchPage(1, { append: false });
    }, 350);
}

function loadMore() {
    if (!hasMore.value || loadingMore.value || loading.value) {
        return;
    }
    fetchPage(page.value + 1, { append: true });
}

onMounted(() => {
    if (props.lazy) {
        fetchPage(1);
    }
});

watch(
    () => [props.itemsUrl, props.group, props.lazy],
    () => {
        if (props.lazy) {
            page.value = 1;
            fetchPage(1);
        }
    },
);
</script>

<template>
    <div class="space-y-3 rounded-lg border p-3 md:p-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="flex items-baseline gap-2">
                <h3 class="font-semibold">{{ title }}</h3>
                <span v-if="lazy && total > 0" class="text-xs text-muted-foreground">{{ total }}</span>
            </div>
            <Input
                v-model="search"
                placeholder="Найти"
                class="max-w-xs"
                @update:model-value="onSearchInput"
            />
        </div>

        <p v-if="error" class="text-sm text-destructive">{{ error }}</p>
        <p v-if="lazy && loading" class="text-sm text-muted-foreground">Загрузка…</p>

        <EditableDataTable
            :columns="columns"
            :data="displayItems"
            :max-height="maxHeight"
            empty-text="Нет данных"
        />

        <div v-if="lazy && hasMore" class="flex justify-center pt-1">
            <Button variant="outline" size="sm" :disabled="loadingMore || loading" @click="loadMore">
                {{ loadingMore ? "Загрузка…" : "Показать ещё" }}
            </Button>
        </div>
    </div>
</template>
