<script setup>
import { ImageIcon, Loader2 } from "lucide-vue-next";

defineProps({
    items: { type: Array, default: () => [] },
    activeId: { type: String, default: null },
});

const emit = defineEmits(["select"]);
</script>

<template>
    <aside class="flex w-[72px] shrink-0 flex-col gap-2">
        <button
            v-for="item in items"
            :key="item.id"
            type="button"
            class="group relative aspect-square w-full overflow-hidden rounded-lg border-2 transition-all"
            :class="activeId === item.id
                ? 'border-primary shadow-sm ring-2 ring-primary/20'
                : 'border-transparent hover:border-border'"
            @click="emit('select', item)"
        >
            <img
                v-if="item.url && item.status !== 'pending'"
                :src="item.url"
                alt=""
                class="h-full w-full object-cover"
            />
            <div
                v-else
                class="flex h-full w-full items-center justify-center bg-muted"
            >
                <ImageIcon v-if="item.status !== 'pending'" class="h-4 w-4 text-muted-foreground" />
            </div>
            <div
                v-if="item.status === 'pending'"
                class="absolute inset-0 flex items-center justify-center bg-black/35"
            >
                <Loader2 class="h-5 w-5 animate-spin text-white" />
            </div>
        </button>
    </aside>
</template>