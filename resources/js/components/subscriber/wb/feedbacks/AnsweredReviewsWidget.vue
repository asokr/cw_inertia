<script setup>
import { computed, ref } from "vue";
import Card from "@/components/ui/Card.vue";
import StarRating from "@/components/subscriber/wb/feedbacks/StarRating.vue";

const props = defineProps({
    items: { type: Array, default: () => [] },
    clientId: { type: [Number, String], required: true },
});

const limit = ref(5);
const withText = ref(false);
const withPhoto = ref(false);

const displayed = computed(() => props.items.slice(0, limit.value));

function wbUrl(productId) {
    return `https://www.wildberries.ru/catalog/${productId}/detail.aspx`;
}
</script>

<template>
    <Card class="p-4">
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h3 class="font-semibold">Обработанные отзывы</h3>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <label class="flex items-center gap-2">
                    <input v-model="limit" type="radio" :value="5" /> 5
                </label>
                <label class="flex items-center gap-2">
                    <input v-model="limit" type="radio" :value="10" /> 10
                </label>
            </div>
        </div>

        <p v-if="!displayed.length" class="text-sm text-muted-foreground">Нет отвеченных отзывов.</p>

        <div v-else class="divide-y">
            <div v-for="item in displayed" :key="item.id" class="flex gap-4 py-4 first:pt-0">
                <a :href="wbUrl(item.product_id)" target="_blank" rel="noopener" class="shrink-0">
                    <img
                        v-if="item.product_image"
                        :src="item.product_image"
                        alt=""
                        class="h-28 w-20 rounded object-cover"
                    />
                </a>
                <div class="min-w-0 flex-1 space-y-2 text-sm">
                    <a :href="wbUrl(item.product_id)" target="_blank" rel="noopener" class="font-medium hover:underline">
                        Товар #{{ item.product_id }}
                    </a>
                    <StarRating :value="item.rating" />
                    <p v-if="item.content" class="whitespace-pre-wrap text-muted-foreground">{{ item.content }}</p>
                    <div v-if="item.response" class="rounded-md bg-muted/40 p-2">
                        <p class="text-xs text-muted-foreground">
                            {{ item.response.is_ai ? "Ответ ИИ" : "Ответ" }}
                        </p>
                        <p class="mt-1 whitespace-pre-wrap">{{ item.response.text }}</p>
                    </div>
                </div>
            </div>
        </div>
    </Card>
</template>