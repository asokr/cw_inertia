<script setup>
import { Loader2, Plus, Trash2, Video } from "lucide-vue-next";
import { computed } from "vue";
import Button from "@/components/ui/Button.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    items: { type: Array, default: () => [] },
    activeId: { type: [Number, null], default: null },
    loading: { type: Boolean, default: false },
    deletingId: { type: [Number, null], default: null },
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

function statusLabel(item) {
    if (item?.has_pending) {
        return "Генерируется";
    }

    return "Готово";
}

function statusClass(item) {
    if (item?.has_pending) {
        return "text-amber-600";
    }

    return "text-emerald-600";
}
</script>

<template>
    <section class="rounded-2xl border bg-card p-4 shadow-sm">
        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
            <div>
                <h3 class="text-sm font-semibold">Мои генерации</h3>
            </div>
            <Button type="button" variant="outline" size="sm" class="h-8 text-xs" @click="emit('create')">
                <Plus class="mr-1.5 h-3.5 w-3.5" />
                Новая
            </Button>
        </div>

        <div v-if="loading" class="flex items-center justify-center py-6 text-xs text-muted-foreground">
            <Loader2 class="mr-2 h-3.5 w-3.5 animate-spin" />
            Загрузка...
        </div>

        <div v-else-if="!hasItems"
            class="rounded-xl border border-dashed px-3 py-6 text-center text-xs text-muted-foreground">
            Генераций пока нет. Запустите первый запрос или создайте новую генерацию.
        </div>

        <div v-else class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 xl:grid-cols-5">
            <article v-for="item in items" :key="item.id"
                class="group relative overflow-hidden rounded-lg border transition-colors"
                :class="activeId === item.id ? 'border-primary bg-primary/5' : 'hover:border-primary/40'">
                <button type="button" class="block w-full text-left" @click="emit('open', item.id)">
                    <div class="relative flex h-20 items-center justify-center overflow-hidden bg-muted">
                        <video v-if="previewUrl(item)" :key="item.id" :src="previewUrl(item)" muted playsinline
                            preload="metadata" class="h-full w-full object-cover" />
                        <Video v-else class="h-5 w-5 text-muted-foreground" />
                        <div v-if="item.has_pending"
                            class="absolute inset-0 flex items-center justify-center bg-black/35">
                            <Loader2 class="h-4 w-4 animate-spin text-white" />
                        </div>
                    </div>

                    <div class="space-y-1 p-2">
                        <p class="line-clamp-2 text-xs font-medium leading-tight">{{ item.title }}</p>
                        <div class="flex items-center justify-between gap-1 text-[10px] text-muted-foreground">
                            <span class="truncate">{{ formatDate(item.updated_at || item.created_at) }}</span>
                            <span class="shrink-0">{{ item.tasks_count }} запр.</span>
                        </div>
                        <p class="text-[10px] font-semibold" :class="statusClass(item)">
                            {{ statusLabel(item) }}
                        </p>
                    </div>
                </button>

                <button type="button"
                    class="absolute right-1 top-1 rounded-md bg-background/80 p-1 text-destructive opacity-0 transition-opacity hover:bg-background group-hover:opacity-100"
                    :disabled="deletingId === item.id" title="Удалить" @click.stop="emit('delete', item.id)">
                    <Loader2 v-if="deletingId === item.id" class="h-3.5 w-3.5 animate-spin" />
                    <Trash2 v-else class="h-3.5 w-3.5" />
                </button>
            </article>
        </div>
    </section>
</template>