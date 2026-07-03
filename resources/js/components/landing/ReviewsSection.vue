<script setup>
import { Quote, User } from "lucide-vue-next";
import Card from "@/components/ui/Card.vue";
import { reviews } from "@/config/homeContent";

const avatarClass = {
    muted: "bg-muted text-muted-foreground",
    warm: "bg-amber-500/10 text-amber-700 dark:text-amber-400",
};
</script>

<template>
    <section id="reviews" class="mx-auto max-w-6xl px-4 py-16 sm:px-6 lg:px-8">
        <div class="mb-10 text-center">
            <h2 class="text-2xl font-semibold tracking-tight sm:text-3xl">
                Отзывы пользователей
            </h2>
            <p class="mx-auto mt-3 max-w-2xl text-muted-foreground">
                Селлеры и менеджеры WB и Ozon — о том, как платформа экономит время и помогает не терять на акциях.
            </p>
        </div>

        <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
            <Card
                v-for="review in reviews"
                :key="review.name"
                class="flex flex-col border-border/70 bg-card/90 p-6 backdrop-blur"
            >
                <Quote class="mb-4 h-5 w-5 text-primary/40" />

                <p class="mb-6 flex-1 text-sm leading-relaxed text-muted-foreground sm:text-base">
                    {{ review.text }}
                </p>

                <div class="flex items-center gap-3 border-t pt-4">
                    <div
                        class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full"
                        :class="avatarClass[review.tone]"
                    >
                        <User class="h-5 w-5" />
                    </div>
                    <div>
                        <p class="font-semibold leading-tight">{{ review.name }}</p>
                        <p class="text-xs text-muted-foreground sm:text-sm">{{ review.position }}</p>
                    </div>
                </div>
            </Card>
        </div>
    </section>
</template>