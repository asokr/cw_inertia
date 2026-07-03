<script setup>
import { Head, router } from "@inertiajs/vue3";
import { PenLine, Search, X } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref, watch } from "vue";
import BlogCard from "@/components/blog/BlogCard.vue";
import BlogCardSkeleton from "@/components/blog/BlogCardSkeleton.vue";
import BlogPagination from "@/components/blog/BlogPagination.vue";
import BlogLayout from "@/Layouts/BlogLayout.vue";

const props = defineProps({
    posts: { type: Array, default: () => [] },
    pagination: {
        type: Object,
        default: () => ({ current_page: 1, last_page: 1, per_page: 12, total: 0 }),
    },
    categories: { type: Array, default: () => [] },
    filters: {
        type: Object,
        default: () => ({ search: "", category_id: null, tag_id: null }),
    },
});

const searchInput = ref(props.filters.search || "");
const isNavigating = ref(false);
let searchTimeout = null;
let removeStartListener;
let removeFinishListener;

function buildParams(overrides = {}) {
    const params = {
        page: overrides.page ?? props.pagination.current_page,
    };

    const search = overrides.search ?? searchInput.value;
    const categoryId = overrides.category_id ?? props.filters.category_id;

    if (search && search.length >= 3) {
        params.search = search;
    }

    if (categoryId) {
        params.category_id = categoryId;
    }

    if (params.page <= 1) {
        delete params.page;
    }

    return params;
}

function visitBlog(overrides = {}) {
    router.get("/blog", buildParams(overrides), {
        preserveState: true,
        preserveScroll: false,
        only: ["posts", "pagination", "filters"],
        onStart: () => {
            isNavigating.value = true;
        },
        onFinish: () => {
            isNavigating.value = false;
        },
    });
}

function onSearchInput() {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        if (searchInput.value.length >= 3 || searchInput.value.length === 0) {
            visitBlog({ search: searchInput.value, page: 1 });
        }
    }, 400);
}

function clearSearch() {
    searchInput.value = "";
    visitBlog({ search: "", page: 1 });
}

function selectCategory(categoryId) {
    const next = props.filters.category_id === categoryId ? null : categoryId;
    visitBlog({ category_id: next, page: 1 });
}

function onPageChange(page) {
    visitBlog({ page });
    window.scrollTo({ top: 0, behavior: "smooth" });
}

const showSkeleton = computed(() => isNavigating.value);
const isEmpty = computed(() => !showSkeleton.value && props.posts.length === 0);

onMounted(() => {
    removeStartListener = router.on("start", () => {
        isNavigating.value = true;
    });
    removeFinishListener = router.on("finish", () => {
        isNavigating.value = false;
    });
});

onUnmounted(() => {
    clearTimeout(searchTimeout);
    removeStartListener?.();
    removeFinishListener?.();
});

watch(() => props.filters.search, (value) => {
    if (value !== searchInput.value) {
        searchInput.value = value || "";
    }
});
</script>

<template>
    <Head>
        <title>Блог</title>
        <meta
            head-key="description"
            name="description"
            content="Полезные статьи о продажах на Wildberries и Ozon. Советы, инструкции и лайфхаки для продавцов маркетплейсов."
        />
        <meta property="og:title" content="Блог — CW Platform" />
        <meta
            property="og:description"
            content="Полезные статьи о продажах на Wildberries и Ozon. Советы, инструкции и лайфхаки для продавцов маркетплейсов."
        />
    </Head>

    <BlogLayout>
        <section class="blog-section landing-page__fade landing-page__fade--1 mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="blog-wrapper">
                <h1 class="blog-title">Блог</h1>
                <p class="blog-subtitle">Полезные материалы о продажах на маркетплейсах</p>

                <div class="blog-search">
                    <div class="blog-search__wrap">
                        <Search class="blog-search__icon h-5 w-5" />
                        <input
                            v-model="searchInput"
                            type="text"
                            class="blog-search__input"
                            placeholder="Поиск по статьям..."
                            @input="onSearchInput"
                        >
                        <button
                            v-if="searchInput"
                            type="button"
                            class="blog-search__clear"
                            aria-label="Очистить поиск"
                            @click="clearSearch"
                        >
                            <X class="h-4 w-4" />
                        </button>
                    </div>
                </div>

                <div v-if="categories.length" class="blog-categories">
                    <button
                        type="button"
                        class="blog-category-chip"
                        :class="{ 'blog-category-chip--active': !filters.category_id }"
                        @click="selectCategory(null)"
                    >
                        Все
                    </button>
                    <button
                        v-for="category in categories"
                        :key="category.id"
                        type="button"
                        class="blog-category-chip"
                        :class="{ 'blog-category-chip--active': filters.category_id === category.id }"
                        @click="selectCategory(category.id)"
                    >
                        {{ category.name }}
                    </button>
                </div>

                <div v-if="showSkeleton" class="blog-grid">
                    <BlogCardSkeleton v-for="n in 6" :key="n" />
                </div>

                <template v-else-if="posts.length">
                    <div class="blog-grid">
                        <BlogCard v-for="post in posts" :key="post.id" :post="post" />
                    </div>

                    <BlogPagination
                        :current-page="pagination.current_page"
                        :last-page="pagination.last_page"
                        @change="onPageChange"
                    />
                </template>

                <div v-else-if="isEmpty" class="blog-empty">
                    <PenLine class="mx-auto mb-5 h-16 w-16 opacity-40" />
                    <p>Статей пока нет</p>
                    <p v-if="searchInput" class="text-sm opacity-70">Попробуйте изменить поисковый запрос</p>
                </div>
            </div>
        </section>
    </BlogLayout>
</template>