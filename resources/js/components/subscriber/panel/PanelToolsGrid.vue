<script setup>
import { Link } from "@inertiajs/vue3";
import { computed } from "vue";
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import { getSubscriberTools } from "@/config/subscriberNav";
import { usePermissions } from "@/composables/usePermissions";

const props = defineProps({
    cabinetsByTool: { type: Object, default: () => ({}) },
});

const { can, isAdmin, isSuperAdmin } = usePermissions();

const tools = computed(() => getSubscriberTools({
    can,
    isAdmin: isAdmin.value || isSuperAdmin.value,
}));

function cabinetCount(key) {
    return props.cabinetsByTool?.[key] ?? 0;
}
</script>

<template>
    <Card class="subscriber-card--static border-border/70 bg-card/80 p-6 backdrop-blur dark:bg-card/95 dark:backdrop-blur-none">
        <h2 class="mb-4 text-base font-semibold tracking-tight">Инструменты</h2>
        <div v-if="tools.length" class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
            <Link
                v-for="tool in tools"
                :key="tool.key"
                :href="tool.href"
                class="group rounded-xl border border-border/70 bg-background/50 p-4 transition hover:border-primary/30 hover:bg-accent/30 hover:shadow-md hover:shadow-primary/5"
            >
                <div class="mb-3 flex items-start justify-between gap-2">
                    <div
                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary transition group-hover:bg-primary/15"
                    >
                        <component :is="tool.icon" class="h-5 w-5" />
                    </div>
                    <Badge v-if="tool.hasCabinets" variant="secondary" class="tabular-nums">
                        {{ cabinetCount(tool.key) }}
                    </Badge>
                </div>
                <p class="text-xs text-muted-foreground">{{ tool.group }}</p>
                <p class="mt-0.5 font-medium transition group-hover:text-primary">{{ tool.label }}</p>
                <p class="mt-1 text-sm leading-relaxed text-muted-foreground">{{ tool.description }}</p>
            </Link>
        </div>
        <p v-else class="text-sm text-muted-foreground">
            Нет доступных инструментов. Обратитесь в поддержку, если считаете это ошибкой.
        </p>
    </Card>
</template>