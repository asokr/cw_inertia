<script setup>
import { Loader2, Video } from "lucide-vue-next";

defineProps({
    tasks: { type: Array, default: () => [] },
    activeTaskId: { type: String, default: null },
});

const emit = defineEmits(["select"]);
</script>

<template>
    <aside class="rounded-2xl border bg-card p-4">
        <h3 class="text-center text-sm font-semibold">История текущей сессии</h3>
        <p class="mb-4 text-center text-xs text-muted-foreground">История будет недоступна после перезагрузки страницы</p>

        <button
            v-for="task in tasks"
            :key="task.request_id"
            type="button"
            class="mb-3 w-full rounded-xl border p-3 text-left transition-colors"
            :class="activeTaskId === task.request_id ? 'border-primary bg-primary/5' : 'hover:bg-muted/30'"
            @click="emit('select', task.request_id)"
        >
            <div class="relative mb-2 flex h-24 items-center justify-center overflow-hidden rounded-lg bg-muted">
                <video
                    v-if="task.status === 'done' && task.video?.url"
                    :src="task.video.url"
                    muted
                    class="h-full w-full object-cover"
                />
                <img v-else-if="task.image" :src="task.image" alt="" class="h-full w-full object-contain" />
                <Video v-else class="h-6 w-6 text-muted-foreground" />
                <div
                    v-if="task.status === 'pending'"
                    class="absolute inset-0 flex items-center justify-center bg-black/40"
                >
                    <Loader2 class="h-5 w-5 animate-spin text-white" />
                </div>
            </div>
            <p class="truncate text-sm font-medium">{{ task.prompt }}</p>
            <p v-if="task.duration || task.resolution" class="mt-1 text-xs text-muted-foreground">
                <span v-if="task.duration">{{ task.duration }} сек</span>
                <span v-if="task.resolution" class="ml-2">{{ task.resolution }}</span>
            </p>
            <p
                class="mt-1 text-xs font-semibold"
                :class="{
                    'text-amber-600': task.status === 'pending',
                    'text-emerald-600': task.status === 'done',
                    'text-amber-700': task.status === 'filtered_by_moderation',
                    'text-destructive': task.status === 'error' || task.status === 'expired',
                }"
            >
                <span v-if="task.status === 'pending'">Генерируется...</span>
                <span v-else-if="task.status === 'done'">Готово</span>
                <span v-else-if="task.status === 'filtered_by_moderation'">Модерация</span>
                <span v-else>Ошибка</span>
            </p>
        </button>
    </aside>
</template>