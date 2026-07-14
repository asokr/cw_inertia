<script setup>
import { ImageIcon, Loader2, Trash2 } from "lucide-vue-next";
import { computed } from "vue";
import Button from "@/components/ui/Button.vue";
import { toAiMediaUrl } from "@/composables/useAiMediaUrl";

const props = defineProps({
    items: { type: Array, default: () => [] },
    activeId: { type: [String, null], default: null },
    loading: { type: Boolean, default: false },
    deletingId: { type: [String, null], default: null },
    isDraft: { type: Boolean, default: false },
});

const emit = defineEmits(["open", "delete"]);

const hasItems = computed(() => props.items.length > 0);

function previewUrl(item) {
    return toAiMediaUrl(item?.preview_url || "");
}
</script>

<template>
    <div v-if="hasItems" class="flex items-end gap-2">
        <div
            class="flex min-w-0 flex-1 items-end gap-2.5 overflow-x-auto p-1 [-ms-overflow-style:none] [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
        >
            <article
                v-for="item in items"
                :key="item.id"
                class="group relative shrink-0 rounded-xl transition-all duration-200"
                :class="activeId === item.uuid && !isDraft
                    ? 'ring-2 ring-primary ring-offset-2 ring-offset-background'
                    : ''"
            >
                <button
                    type="button"
                    class="block overflow-hidden rounded-xl transition-opacity duration-200 hover:opacity-90"
                    @click="emit('open', item.uuid)"
                >
                    <div class="h-[108px] w-[80px] overflow-hidden rounded-xl bg-muted/50 sm:w-[88px]">
                        <img
                            v-if="previewUrl(item)"
                            :src="previewUrl(item)"
                            alt=""
                            class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-[1.03]"
                        />
                        <div
                            v-else
                            class="flex h-full w-full items-center justify-center text-muted-foreground/60"
                        >
                            <ImageIcon class="h-5 w-5" />
                        </div>
                    </div>
                </button>

                <button
                    type="button"
                    class="absolute -right-1 -top-1 rounded-md bg-background/90 p-1 text-destructive opacity-0 shadow-sm backdrop-blur-sm transition-all hover:bg-background group-hover:opacity-100"
                    :disabled="deletingId === item.uuid"
                    title="Удалить"
                    @click.stop="emit('delete', item.uuid)"
                >
                    <Loader2 v-if="deletingId === item.uuid" class="h-3 w-3 animate-spin" />
                    <Trash2 v-else class="h-3 w-3" />
                </button>
            </article>
        </div>

        <Button
            href="/panel/ai/image/history"
            variant="ghost"
            size="sm"
            class="h-7 shrink-0 self-end px-2 pb-1 text-[11px] text-muted-foreground"
        >
            История
        </Button>
    </div>
</template>