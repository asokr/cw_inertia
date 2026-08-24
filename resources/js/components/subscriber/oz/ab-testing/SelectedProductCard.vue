<script setup>
import { ImageOff } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";

defineProps({
    product: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <Card class="overflow-hidden p-3.5 sm:p-4">
        <div class="flex flex-col gap-3.5 sm:flex-row sm:items-start sm:gap-4">
            <div class="shrink-0">
                <img
                    v-if="product.photo_url"
                    :src="product.photo_url"
                    alt=""
                    loading="lazy"
                    referrerpolicy="no-referrer"
                    class="h-20 w-20 rounded-lg border border-border/60 bg-muted object-cover sm:h-24 sm:w-24"
                />
                <div
                    v-else
                    class="flex h-20 w-20 items-center justify-center rounded-lg border border-dashed border-border bg-muted/50 text-muted-foreground sm:h-24 sm:w-24"
                >
                    <ImageOff class="h-6 w-6" />
                </div>
            </div>

            <div class="min-w-0 flex-1 space-y-2.5">
                <h4 class="text-base font-semibold leading-snug text-foreground line-clamp-2">
                    {{ product.title || "Без названия" }}
                </h4>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">SKU</dt>
                        <dd class="truncate font-medium tabular-nums">{{ product.sku || product.nm_id || "—" }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Артикул продавца</dt>
                        <dd class="truncate font-medium">{{ product.offer_id || product.vendor_code || "—" }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </Card>
</template>
