<script setup>
import Badge from "@/components/ui/Badge.vue";
import Card from "@/components/ui/Card.vue";
import FeedbackAnswerForm from "@/components/subscriber/oz/feedbacks/FeedbackAnswerForm.vue";
import StarRating from "@/components/subscriber/wb/feedbacks/StarRating.vue";

defineProps({
    review: { type: Object, required: true },
    signature: { type: String, default: "" },
    sendUrl: { type: String, required: true },
    generateUrl: { type: String, required: true },
});
</script>

<template>
    <Card class="overflow-hidden">
        <div class="grid gap-4 p-4 md:grid-cols-[140px_1fr]">
            <img
                v-if="review.primary_image"
                :src="review.primary_image"
                alt=""
                class="h-36 w-full rounded-md object-cover"
            />
            <div class="space-y-3">
                <div>
                    <p class="text-base font-semibold">{{ review.product_name }}</p>
                    <p v-if="review.offer_id" class="text-sm text-muted-foreground">{{ review.offer_id }}</p>
                </div>

                <Badge :variant="review.order_status === 'CANCELLED' ? 'destructive' : 'default'">
                    {{ review.order_status === "CANCELLED" ? "Отменён" : "Получен" }}
                </Badge>

                <p class="text-sm text-muted-foreground">{{ review.published_at }}</p>
                <StarRating :value="review.rating" />

                <div v-if="review.text" class="text-sm">
                    <p class="font-medium">Текст отзыва</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ review.text }}</p>
                </div>

                <FeedbackAnswerForm
                    :feedback="review"
                    :signature="signature"
                    :send-url="sendUrl"
                    :generate-url="generateUrl"
                />
            </div>
        </div>
    </Card>
</template>