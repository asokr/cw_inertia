<script setup>
import { computed } from "vue";
import { Image, Sparkles, Video } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Skeleton from "@/components/ui/Skeleton.vue";

const props = defineProps({
    mode: {
        type: String,
        default: "all",
        validator: (value) => ["all", "text", "image", "video"].includes(value),
    },
    loading: { type: Boolean, default: false },
    textLimit: { type: [Number, String], default: 0 },
    imageLimit: { type: [Number, String], default: 0 },
    videoLimit: { type: [Number, String], default: 0 },
});

const allItems = [
    { key: "text", label: "Текст", valueKey: "textLimit", icon: Sparkles },
    { key: "image", label: "Изображения", valueKey: "imageLimit", icon: Image },
    { key: "video", label: "Видео", valueKey: "videoLimit", icon: Video },
];

const items = computed(() => {
    const filtered = props.mode === "all"
        ? allItems
        : allItems.filter((item) => item.key === props.mode);

    return filtered.map((item) => ({
        key: item.key,
        label: item.label,
        value: props[item.valueKey],
        icon: item.icon,
    }));
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <template v-if="loading">
            <Skeleton
                v-for="item in items"
                :key="item.key"
                class="h-7 w-24"
            />
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