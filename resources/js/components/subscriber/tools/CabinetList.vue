<script setup>
import { Link } from "@inertiajs/vue3";
import { Plus } from "lucide-vue-next";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";

defineProps({
    cabinets: {
        type: Array,
        default: () => [],
    },
    emptyText: {
        type: String,
        default: "Кабинеты не добавлены",
    },
    addLabel: {
        type: String,
        default: "Добавить кабинет",
    },
    hrefKey: {
        type: String,
        default: "href",
    },
    titleKey: {
        type: String,
        default: "name",
    },
});

defineEmits(["add"]);
</script>

<template>
    <div class="space-y-4">
        <div class="flex justify-end">
            <Button @click="$emit('add')">
                <Plus class="mr-2 h-4 w-4" />
                {{ addLabel }}
            </Button>
        </div>

        <div v-if="cabinets.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="cabinet in cabinets"
                :key="cabinet.id"
                class="p-4 transition-colors hover:bg-muted/20"
            >
                <Link
                    v-if="cabinet[hrefKey]"
                    :href="cabinet[hrefKey]"
                    class="block space-y-1"
                >
                    <div class="font-medium">{{ cabinet[titleKey] }}</div>
                    <div v-if="cabinet.subtitle" class="text-sm text-muted-foreground">
                        {{ cabinet.subtitle }}
                    </div>
                </Link>
                <div v-else class="space-y-1">
                    <div class="font-medium">{{ cabinet[titleKey] }}</div>
                    <div v-if="cabinet.subtitle" class="text-sm text-muted-foreground">
                        {{ cabinet.subtitle }}
                    </div>
                </div>
                <div v-if="$slots.actions" class="mt-3 flex gap-2">
                    <slot name="actions" :cabinet="cabinet" />
                </div>
            </Card>
        </div>

        <Card v-else class="flex min-h-32 items-center justify-center p-6 text-sm text-muted-foreground">
            {{ emptyText }}
        </Card>
    </div>
</template>