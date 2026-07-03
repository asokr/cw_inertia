<script setup>
import { Link } from "@inertiajs/vue3";
import { Eye, ImageIcon } from "lucide-vue-next";
import { ref } from "vue";
import { formatBlogDate } from "@/utils/renderBlogMarkdown";
import { getPostCoverUrl } from "@/utils/blogMedia";

defineProps({
    post: {
        type: Object,
        required: true,
    },
});

const imageError = ref(false);
</script>

<template>
    <Link :href="`/blog/${post.slug}`" class="blog-card">
        <div class="blog-card__image-wrap">
            <img
                v-if="getPostCoverUrl(post) && !imageError"
                :src="getPostCoverUrl(post)"
                :alt="post.title"
                class="blog-card__image"
                loading="lazy"
                @error="imageError = true"
            >
            <div v-else class="blog-card__placeholder">
                <ImageIcon class="h-12 w-12 opacity-40" />
            </div>
            <div v-if="post.categories?.length" class="blog-card__categories">
                <span
                    v-for="cat in post.categories"
                    :key="cat.id"
                    class="blog-card__category"
                >
                    {{ cat.name }}
                </span>
            </div>
        </div>
        <div class="blog-card__body">
            <h2 class="blog-card__title">{{ post.title }}</h2>
            <p v-if="post.excerpt" class="blog-card__excerpt">{{ post.excerpt }}</p>
            <div class="blog-card__meta">
                <time :datetime="post.published_at">{{ formatBlogDate(post.published_at) }}</time>
                <span class="flex items-center gap-1">
                    <Eye class="h-3.5 w-3.5" />
                    {{ post.views_count ?? 0 }}
                </span>
            </div>
        </div>
    </Link>
</template>