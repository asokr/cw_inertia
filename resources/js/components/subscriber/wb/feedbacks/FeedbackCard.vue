<script setup>
import { ref } from "vue";
import Card from "@/components/ui/Card.vue";
import Badge from "@/components/ui/Badge.vue";
import FeedbackAnswerForm from "@/components/subscriber/wb/feedbacks/FeedbackAnswerForm.vue";
import StarRating from "@/components/subscriber/wb/feedbacks/StarRating.vue";
import Dialog from "@/components/ui/Dialog.vue";

defineProps({
    feedback: { type: Object, required: true },
    clientId: { type: [Number, String], required: true },
    ratingType: { type: [String, Array], default: null },
    sendUrl: { type: String, required: true },
    generateUrl: { type: String, required: true },
});

const imageDialog = ref(false);
const selectedImage = ref("");
</script>

<template>
    <Card class="overflow-hidden">
        <div class="grid gap-4 p-4 md:grid-cols-[140px_1fr]">
            <img
                v-if="feedback.productDetails?.photo"
                :src="feedback.productDetails.photo"
                alt=""
                class="h-36 w-full rounded-md object-cover"
            />
            <div class="space-y-3">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <a
                            v-if="feedback.productDetails?.nmId"
                            :href="`https://www.wildberries.ru/catalog/${feedback.productDetails.nmId}/detail.aspx`"
                            target="_blank"
                            rel="noopener"
                            class="text-base font-semibold hover:text-primary"
                        >
                            {{ feedback.productDetails?.productName }}
                        </a>
                        <p v-if="feedback.productDetails?.supplierArticle" class="text-sm text-muted-foreground">
                            {{ feedback.productDetails.supplierArticle }}
                        </p>
                        <p v-if="feedback.productDetails?.nmId" class="text-xs text-muted-foreground">
                            nmID: {{ feedback.productDetails.nmId }}
                        </p>
                    </div>
                    <Badge v-if="!feedback.answer?.text" variant="warning">Без ответа</Badge>
                </div>

                <div class="text-sm text-muted-foreground">{{ feedback.createdDate }}</div>
                <StarRating :value="feedback.productValuation" />
                <p v-if="feedback.name" class="text-sm">Имя автора: {{ feedback.name }}</p>

                <div v-if="feedback.text" class="text-sm">
                    <p class="font-medium">Текст отзыва</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ feedback.text }}</p>
                </div>
                <div v-if="feedback.pros" class="text-sm">
                    <p class="font-medium">Достоинства</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ feedback.pros }}</p>
                </div>
                <div v-if="feedback.cons" class="text-sm">
                    <p class="font-medium">Недостатки</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ feedback.cons }}</p>
                </div>

                <div v-if="feedback.photoLinks?.length" class="flex flex-wrap gap-2">
                    <button
                        v-for="(img, idx) in feedback.photoLinks"
                        :key="idx"
                        type="button"
                        class="h-16 w-16 overflow-hidden rounded border"
                        @click="selectedImage = img.fullSize; imageDialog = true"
                    >
                        <img :src="img.miniSize" alt="" class="h-full w-full object-cover" />
                    </button>
                </div>

                <div v-if="feedback.answer?.text" class="rounded-md bg-muted/40 p-3 text-sm">
                    <p class="font-medium">Ответ</p>
                    <p class="mt-1 whitespace-pre-wrap">{{ feedback.answer.text }}</p>
                </div>
                <FeedbackAnswerForm
                    v-else
                    :client-id="clientId"
                    :feedback="feedback"
                    :rating-type="ratingType"
                    :send-url="sendUrl"
                    :generate-url="generateUrl"
                />
            </div>
        </div>

        <Dialog :open="imageDialog" title="Фото из отзыва" @update:open="imageDialog = $event">
            <img v-if="selectedImage" :src="selectedImage" alt="" class="max-h-[70vh] w-full object-contain" />
        </Dialog>
    </Card>
</template>