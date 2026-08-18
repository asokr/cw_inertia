<script setup>
import { Coins } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import { formatCredits } from "@/utils/credits";

defineProps({
    loading: { type: Boolean, default: false },
    available: { type: [Number, String], default: 0 },
});
</script>

<template>
    <div class="flex flex-wrap items-center gap-2">
        <Skeleton v-if="loading" class="h-7 w-28" />
        <Badge
            v-else
            variant="secondary"
            class="gap-1.5 px-2.5 py-1 text-xs font-medium"
        >
            <Coins class="h-3.5 w-3.5 opacity-70" />
            <span class="text-muted-foreground">Кредиты:</span>
            <span class="tabular-nums">{{ formatCredits(available) }}</span>
        </Badge>
    </div>
</template>
