<script setup>
import { ImageOff, Star } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";

const props = defineProps({
    product: {
        type: Object,
        required: true,
    },
});

function formatPrice(value) {
    if (value == null || value === "") {
        return "—";
    }

    const number = Number(value);
    if (Number.isNaN(number)) {
        return "—";
    }

    return `${new Intl.NumberFormat("ru-RU", {
        maximumFractionDigits: 0,
    }).format(number)} ₽`;
}

function formatRating(value) {
    if (value == null || value === "") {
        return "—";
    }

    const number = Number(value);
    if (Number.isNaN(number)) {
        return "—";
    }

    return number.toFixed(1);
}
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
                <div class="space-y-1">
                    <h4 class="text-base font-semibold leading-snug text-foreground line-clamp-2">
                        {{ product.title || "Без названия" }}
                    </h4>
                    <p v-if="product.brand" class="text-sm text-muted-foreground">
                        {{ product.brand }}
                        <template v-if="product.subject_name">
                            · {{ product.subject_name }}
                        </template>
                    </p>
                    <p v-else-if="product.subject_name" class="text-sm text-muted-foreground">
                        {{ product.subject_name }}
                    </p>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-4">
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Артикул WB</dt>
                        <dd class="truncate font-medium tabular-nums">{{ product.nm_id }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Артикул продавца</dt>
                        <dd class="truncate font-medium">{{ product.vendor_code || "—" }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Цена</dt>
                        <dd class="font-medium tabular-nums">{{ formatPrice(product.price) }}</dd>
                    </div>
                    <div class="min-w-0">
                        <dt class="text-xs text-muted-foreground">Рейтинг</dt>
                        <dd class="flex items-center gap-1 font-medium tabular-nums">
                            <Star
                                v-if="product.rating != null && product.rating !== ''"
                                class="h-3.5 w-3.5 fill-amber-400 text-amber-400"
                            />
                            {{ formatRating(product.rating) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>
    </Card>
</template>
