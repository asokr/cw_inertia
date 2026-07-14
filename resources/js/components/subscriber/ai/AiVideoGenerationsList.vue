<script setup>
import { Clapperboard, Loader2, Plus, Trash2 } from "lucide-vue-next";
import { computed } from "vue";
import Button from "@/components/ui/Button.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    items: { type: Array, default: () => [] },
    activeId: { type: [String, null], default: null },
    loading: { type: Boolean, default: false },
    deletingId: { type: [String, null], default: null },
});

const emit = defineEmits(["open", "create", "delete"]);

const hasItems = computed(() => props.items.length > 0);

function previewUrl(item) {
    return toAiMediaUrl(item?.preview_url || "");
}

function formatDate(value) {
    if (!value) {
        return "";
    }

    try {
        return new Intl.DateTimeFormat("ru-RU", {
            day: "2-digit",
            month: "short",
            hour: "2-digit",
            minute: "2-digit",
        }).format(new Date(value));
    } catch {
        return "";
    }
}

function isVideoPreview(url) {
    return /\.(mp4|webm|mov)(\?|$)/i.test(url) || url.includes("/generated-videos/");
}

function statusLabel(item) {
    if (item?.has_pending) {
        return "Генерируется";
    }

    return "Готово";
}

function canPlayPreview(item) {
    if (item?.has_pending) {
        return false;
    }

    const url = previewUrl(item);
    return Boolean(url && isVideoPreview(url));
}

function handlePreviewEnter(event, item) {
    if (!canPlayPreview(item)) {
        return;
    }

    const video = event.currentTarget.querySelector("video");
    if (!video) {
        return;
    }

    video.currentTime = 0;
    void video.play().catch(() => {});
}

function handlePreviewLeave(event) {
    const video = event.currentTarget.querySelector("video");
    if (!video) {
        return;
    }

    video.pause();
    video.currentTime = 0;
}
</script>

<template>
    <section class="rounded-2xl border bg-card p-4 shadow-sm sm:p-5">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">Мои генерации</h3>
                <p class="text-[11px] text-muted-foreground">Сохранённые группы запросов</p>
            </div>
            <Button type="button" variant="outline" size="sm" class="h-8 text-xs" @click="emit('create')">
                <Plus class="mr-1.5 h-3.5 w-3.5" />
                Новая
            </Button>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-10 text-xs text-muted-foreground">
            <Loader2 class="mr-2 h-4 w-4 animate-spin" />
            Загрузка...
        </div>

        <div
            v-else-if="!hasItems"
            class="rounded-xl border border-dashed px-4 py-10 text-center text-xs text-muted-foreground"
        >
            Генераций пока нет. Запустите первый запрос или создайте новую генерацию.
        </div>

        <div v-else class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-3 xl:grid-cols-4">
            <article
                v-for="item in items"
                :key="item.id"
                class="group relative overflow-hidden rounded-xl border bg-card shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-primary/35 hover:shadow-md"
                :class="activeId === item.uuid ? 'border-primary ring-2 ring-primary/15' : ''"
                @mouseenter="handlePreviewEnter($event, item)"
                @mouseleave="handlePreviewLeave"
            >
                <button
                    type="button"
                    class="block w-full text-left"
                    @click="emit('open', item.uuid)"
                >
                    <div class="relative aspect-[3/4] min-h-[180px] overflow-hidden bg-gradient-to-br from-muted via-muted/80 to-muted/40 sm:min-h-[220px]">
                        <video
                            v-if="previewUrl(item) && isVideoPreview(previewUrl(item))"
                            :key="item.id"
                            :src="previewUrl(item)"
                            muted
                            loop
                            playsinline
                            preload="metadata"
                            class="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.04]"
                        />
                        <img
                            v-else-if="previewUrl(item)"
                            :key="item.id"
                            :src="previewUrl(item)"
                            alt=""
                            class="h-full w-full object-cover transition-transform duration-300 ease-out group-hover:scale-[1.04]"
                        />
                        <div
                            v-else
                            class="flex h-full w-full flex-col items-center justify-center gap-2 text-muted-foreground/70"
                        >
                            <div class="rounded-full bg-background/60 p-3">
                                <Clapperboard class="h-6 w-6" />
                            </div>
                            <span class="text-[10px]">Нет превью</span>
                        </div>

                        <div
                            v-if="item.has_pending"
                            class="absolute inset-0 flex items-center justify-center bg-black/35"
                        >
                            <Loader2 class="h-6 w-6 animate-spin text-white" />
                        </div>

                        <div
                            class="pointer-events-none absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/50 via-black/15 to-transparent opacity-80 transition-opacity duration-200 group-hover:opacity-100"
                        />

                        <div class="absolute bottom-2 left-2 right-2 flex items-end justify-between gap-2">
                            <span class="rounded-full bg-background/85 px-2 py-0.5 text-[10px] font-medium text-foreground/90 backdrop-blur-sm">
                                {{ item.tasks_count }} запр.
                            </span>
                            <span
                                class="rounded-full px-2 py-0.5 text-[10px] font-semibold text-white backdrop-blur-sm"
                                :class="item.has_pending ? 'bg-amber-500/90' : 'bg-emerald-500/90'"
                            >
                                {{ statusLabel(item) }}
                            </span>
                        </div>
                    </div>

                    <div class="space-y-1 border-t bg-card/95 p-3">
                        <p class="line-clamp-2 text-sm font-medium leading-snug">{{ item.title }}</p>
                        <p class="text-[11px] text-muted-foreground">
                            {{ formatDate(item.updated_at || item.created_at) }}
                        </p>
                    </div>
                </button>

                <button
                    type="button"
                    class="absolute right-2 top-2 rounded-lg bg-background/80 p-1.5 text-destructive opacity-0 shadow-sm backdrop-blur-sm transition-all hover:bg-background group-hover:opacity-100"
                    :disabled="deletingId === item.uuid"
                    title="Удалить"
                    @click.stop="emit('delete', item.uuid)"
                >
                    <Loader2 v-if="deletingId === item.uuid" class="h-3.5 w-3.5 animate-spin" />
                    <Trash2 v-else class="h-3.5 w-3.5" />
                </button>
            </article>
        </div>
    </section>
</template>