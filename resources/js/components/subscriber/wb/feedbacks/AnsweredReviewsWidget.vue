<script setup>
import { onMounted, ref, watch } from "vue";
import { Link } from "@inertiajs/vue3";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Checkbox from "@/components/ui/Checkbox.vue";
import Dialog from "@/components/ui/Dialog.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import StarRating from "@/components/subscriber/wb/feedbacks/StarRating.vue";

const props = defineProps({
    clientId: { type: [Number, String], required: true },
});

const limitOptions = [5, 10, 20, 50];
const limit = ref(5);
const withText = ref(false);
const withPhoto = ref(false);

const items = ref([]);
const meta = ref({ total: 0, limit: 5, offset: 0, has_more: false });
const pending = ref(false);
const loadingMore = ref(false);
const error = ref(null);

const preview = ref({ open: false, url: "" });

function wbUrl(productId) {
    return `https://www.wildberries.ru/catalog/${productId}/detail.aspx`;
}

function getPhotoSrc(ph, type = "mini") {
    if (!ph) return "";
    if (typeof ph === "string") return ph;
    if (type === "mini") return ph.miniSize || ph.fullSize || "";
    return ph.fullSize || ph.miniSize || "";
}

function formatDate(dt) {
    if (!dt) return "";
    try {
        const d = new Date(dt);
        if (Number.isNaN(d.getTime())) return dt;
        return d.toLocaleString("ru-RU");
    } catch {
        return dt;
    }
}

function openPreview(url) {
    if (!url) return;
    preview.value = { open: true, url };
}

function closePreview() {
    preview.value = { open: false, url: "" };
}

function buildQuery(offset = 0) {
    const params = new URLSearchParams();
    params.set("limit", String(limit.value));
    params.set("offset", String(offset));
    if (withText.value) params.set("has_text", "1");
    if (withPhoto.value) params.set("has_photo", "1");
    return params.toString();
}

async function load({ append = false } = {}) {
    if (!props.clientId) return;

    const offset = append ? items.value.length : 0;
    if (append) {
        loadingMore.value = true;
    } else {
        pending.value = true;
        error.value = null;
    }

    try {
        const response = await fetch(
            `/panel/wb/feedbacks/clients/${props.clientId}/answered?${buildQuery(offset)}`,
            {
                headers: {
                    Accept: "application/json",
                    "X-Requested-With": "XMLHttpRequest",
                },
                credentials: "same-origin",
            }
        );

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        const payload = await response.json();
        if (!payload?.success) {
            throw new Error(payload?.message || "Не удалось загрузить отзывы");
        }

        const nextItems = Array.isArray(payload.data) ? payload.data : [];
        items.value = append ? [...items.value, ...nextItems] : nextItems;
        meta.value = {
            total: payload.meta?.total ?? nextItems.length,
            limit: payload.meta?.limit ?? limit.value,
            offset: payload.meta?.offset ?? offset,
            has_more: Boolean(payload.meta?.has_more),
        };
    } catch (e) {
        console.error(e);
        error.value = e;
        if (!append) {
            items.value = [];
            meta.value = { total: 0, limit: limit.value, offset: 0, has_more: false };
        }
    } finally {
        pending.value = false;
        loadingMore.value = false;
    }
}

function loadMore() {
    if (loadingMore.value || !meta.value.has_more) return;
    load({ append: true });
}

watch(
    () => [props.clientId, limit.value, withText.value, withPhoto.value],
    () => load({ append: false }),
    { immediate: false }
);

onMounted(() => load({ append: false }));
</script>

<template>
    <Card class="overflow-hidden">
        <div class="border-b bg-muted/30 px-4 py-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <h3 class="font-semibold">Обработанные отзывы</h3>
                        <Badge variant="success">С ответом</Badge>
                        <span v-if="meta.total" class="text-xs text-muted-foreground">
                            всего {{ meta.total }}
                        </span>
                    </div>
                    <p class="text-xs text-muted-foreground">
                        Отзывы, на которые уже отправлен ответ (бот или вручную)
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-4 text-sm">
                    <div class="flex flex-wrap items-center gap-1.5">
                        <span class="text-xs text-muted-foreground">Показать</span>
                        <div class="inline-flex overflow-hidden rounded-md border">
                            <button
                                v-for="n in limitOptions"
                                :key="n"
                                type="button"
                                class="px-2.5 py-1 text-xs transition-colors"
                                :class="
                                    limit === n
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-background hover:bg-muted'
                                "
                                @click="limit = n"
                            >
                                {{ n }}
                            </button>
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-center gap-2 text-xs">
                        <Checkbox v-model="withText" />
                        с текстом
                    </label>
                    <label class="flex cursor-pointer items-center gap-2 text-xs">
                        <Checkbox v-model="withPhoto" />
                        с фото
                    </label>
                </div>
            </div>
        </div>

        <div class="p-4">
            <div v-if="pending" class="space-y-3">
                <Skeleton v-for="i in 3" :key="i" class="h-24 w-full" />
            </div>

            <p v-else-if="error" class="text-sm text-destructive">
                Не удалось загрузить обработанные отзывы.
            </p>

            <p v-else-if="!items.length" class="text-sm text-muted-foreground">
                Нет отвеченных отзывов.
            </p>

            <div v-else class="divide-y">
                <div
                    v-for="item in items"
                    :key="item.id"
                    class="flex flex-col gap-4 py-4 first:pt-0 sm:flex-row"
                >
                    <a
                        :href="wbUrl(item.product_id)"
                        target="_blank"
                        rel="noopener"
                        class="shrink-0"
                        :title="`Открыть товар #${item.product_id}`"
                    >
                        <img
                            v-if="item.product_image"
                            :src="item.product_image"
                            :alt="`Товар #${item.product_id}`"
                            class="h-36 w-24 rounded object-cover ring-1 ring-border"
                        />
                        <div
                            v-else
                            class="flex h-36 w-24 items-center justify-center rounded bg-muted text-xs text-muted-foreground ring-1 ring-border"
                        >
                            нет фото
                        </div>
                    </a>

                    <div class="min-w-0 flex-1 space-y-2 text-sm">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <a
                                :href="wbUrl(item.product_id)"
                                target="_blank"
                                rel="noopener"
                                class="font-medium hover:underline"
                            >
                                Товар #{{ item.product_id }}
                            </a>
                            <StarRating :value="item.rating" />
                        </div>

                        <p class="whitespace-pre-wrap text-foreground">
                            {{ item.content || "Без текста…" }}
                        </p>

                        <div
                            v-if="item.pros || item.cons"
                            class="space-y-0.5 text-xs text-muted-foreground"
                        >
                            <p v-if="item.pros">Плюсы: {{ item.pros }}</p>
                            <p v-if="item.cons">Минусы: {{ item.cons }}</p>
                        </div>

                        <div
                            v-if="Array.isArray(item.photo_links) && item.photo_links.length"
                            class="space-y-1"
                        >
                            <p class="text-[11px] uppercase tracking-wide text-muted-foreground">
                                Фото из отзыва
                            </p>
                            <div class="flex flex-wrap gap-2">
                                <button
                                    v-for="(ph, pIdx) in item.photo_links"
                                    :key="pIdx"
                                    type="button"
                                    class="h-20 w-16 overflow-hidden rounded border hover:ring-2 hover:ring-primary/40"
                                    @click="openPreview(getPhotoSrc(ph, 'full'))"
                                >
                                    <img
                                        :src="getPhotoSrc(ph, 'mini')"
                                        :alt="`Фото ${pIdx + 1}`"
                                        class="h-full w-full object-cover"
                                    />
                                </button>
                            </div>
                        </div>

                        <div class="rounded-md border bg-muted/40 p-2">
                            <p class="text-[11px] uppercase tracking-wide text-muted-foreground">
                                {{ item.response?.is_ai ? "Ответ ИИ" : "Ответ по шаблону" }}
                            </p>
                            <p
                                v-if="item.response?.text"
                                class="mt-1 whitespace-pre-wrap text-sm"
                            >
                                {{ item.response.text }}
                            </p>
                            <p v-else class="mt-1 text-xs text-muted-foreground">
                                Ответ отсутствует
                            </p>
                            <p
                                v-if="item.response?.created_at"
                                class="mt-1 text-[11px] text-muted-foreground"
                            >
                                {{ formatDate(item.response.created_at) }}
                            </p>
                        </div>

                        <Link
                            :href="`/panel/wb/feedbacks/clients/${clientId}/products/${item.product_id}`"
                            class="inline-block text-xs text-primary hover:underline"
                        >
                            Посмотреть статистику товара
                        </Link>
                    </div>
                </div>
            </div>

            <div v-if="!pending && meta.has_more" class="mt-4 flex justify-center">
                <Button
                    variant="outline"
                    :disabled="loadingMore"
                    @click="loadMore"
                >
                    {{ loadingMore ? "Загрузка…" : "Загрузить ещё" }}
                </Button>
            </div>
        </div>

        <Dialog
            :open="preview.open"
            title="Фото из отзыва"
            @update:open="(v) => (!v ? closePreview() : null)"
        >
            <img
                v-if="preview.url"
                :src="preview.url"
                alt="Фото из отзыва"
                class="max-h-[70vh] w-full object-contain"
            />
        </Dialog>
    </Card>
</template>
