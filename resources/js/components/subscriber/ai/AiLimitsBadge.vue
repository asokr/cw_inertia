<script setup>
import { computed } from "vue";
import { Image, Sparkles, Video } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Skeleton from "@/components/ui/Skeleton.vue";

const props = defineProps({
    loading: { type: Boolean, default: false },
    textLimit: { type: [Number, String], default: 0 },
    imageLimit: { type: [Number, String], default: 0 },
    videoLimit: { type: [Number, String], default: 0 },
});

const items = computed(() => [
    { key: "text", label: "Текст", value: props.textLimit, icon: Sparkles },
    { key: "image", label: "Изображения", value: props.imageLimit, icon: Image },
    { key: "video", label: "Видео", value: props.videoLimit, icon: Video },
]);
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <template v-if="loading">
            <Skeleton class="h-7 w-24" />
            <Skeleton class="h-7 w-28" />
            <Skeleton class="h-7 w-20" />
        </template>
        <template v-else>
            <Badge
                v-for="item in items"
                :key="item.key"
                variant="secondary"
                class="gap-1.5 px-2.5 py-1 text-xs font-medium"
            >
                <component :is="item.icon" class="h-3.5 w-3.5 opacity-70" />
                <span class="text-muted-foreground">{{ item.label }}:</span>
                <span class="tabular-nums">{{ item.value ?? 0 }}</span>
            </Badge>
        </template>
    </div>
</template>