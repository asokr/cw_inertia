<script setup>
import { AlertCircle, Clapperboard, Loader2, ShieldAlert } from "lucide-vue-next";
import { normalizeVideoItem, toAiMediaUrl } from "@/composables/useAiMediaUrl";

defineProps({
    items: { type: Array, default: () => [] },
    activeId: { type: String, default: null },
});

const emit = defineEmits(["select"]);

function posterUrl(item) {
    if (!item) {
        return "";
    }

    if (item.url) {
        return toAiMediaUrl(item.url, { allowDataUrl: true }) || "";
    }

    if (item.videoUrl) {
        return "";
    }

    return "";
}

function videoPreviewUrl(item) {
    if (!item?.videoUrl) {
        return "";
    }

    return normalizeVideoItem({ url: item.videoUrl })?.url || item.videoUrl;
}
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
            <video
                v-if="videoPreviewUrl(item) && item.status === 'done'"
                :src="videoPreviewUrl(item)"
                muted
                playsinline
                preload="metadata"
                class="h-full w-full object-cover"
            />
            <img
                v-else-if="posterUrl(item)"
                :src="posterUrl(item)"
                alt=""
                class="h-full w-full object-cover"
            />
            <div
                v-else
                class="flex h-full w-full items-center justify-center bg-muted"
            >
                <Clapperboard class="h-4 w-4 text-muted-foreground" />
            </div>

            <div
                v-if="item.status === 'pending'"
                class="absolute inset-0 flex items-center justify-center bg-black/35"
            >
                <Loader2 class="h-5 w-5 animate-spin text-white" />
            </div>

            <div
                v-else-if="item.status === 'filtered_by_moderation'"
                class="absolute inset-0 flex items-center justify-center bg-amber-500/25"
            >
                <ShieldAlert class="h-4 w-4 text-amber-700" />
            </div>

            <div
                v-else-if="item.status === 'error' || item.status === 'expired'"
                class="absolute inset-0 flex items-center justify-center bg-destructive/20"
            >
                <AlertCircle class="h-4 w-4 text-destructive" />
            </div>
        </button>
    </aside>
</template>