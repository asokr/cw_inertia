<script setup>
import { computed } from "vue";

const props = defineProps({
    currentPage: { type: Number, required: true },
    lastPage: { type: Number, required: true },
});

const emit = defineEmits(["change"]);

const pages = computed(() => {
    const total = props.lastPage;
    const current = props.currentPage;

    if (total <= 7) {
        return Array.from({ length: total }, (_, i) => i + 1);
    }

    const result = [1];

    if (current > 3) {
        result.push("...");
    }

    const start = Math.max(2, current - 1);
    const end = Math.min(total - 1, current + 1);

    for (let i = start; i <= end; i++) {
        result.push(i);
    }

    if (current < total - 2) {
        result.push("...");
    }

    result.push(total);

    return result;
});

function goTo(page) {
    if (page < 1 || page > props.lastPage || page === props.currentPage) {
        return;
    }

    emit("change", page);
}
</script>

<template>
    <nav v-if="lastPage > 1" class="blog-pagination" aria-label="Навигация по страницам">
        <button
            type="button"
            class="blog-pagination__btn"
            :disabled="currentPage <= 1"
            @click="goTo(currentPage - 1)"
        >
            ← Назад
        </button>

        <template v-for="(page, index) in pages" :key="`${page}-${index}`">
            <span v-if="page === '...'" class="text-muted-foreground px-1">…</span>
            <button
                v-else
                type="button"
                class="blog-pagination__page"
                :class="{ 'blog-pagination__page--active': page === currentPage }"
                @click="goTo(page)"
            >
                {{ page }}
            </button>
        </template>

        <button
            type="button"
            class="blog-pagination__btn"
            :disabled="currentPage >= lastPage"
            @click="goTo(currentPage + 1)"
        >
            Вперёд →
        </button>
    </nav>
</template>