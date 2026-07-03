<script setup>
import { Head, Link } from "@inertiajs/vue3";
import { ArrowLeft, Eye } from "lucide-vue-next";
import { computed, onMounted, onUnmounted, ref } from "vue";
import BlogLayout from "@/Layouts/BlogLayout.vue";
import { getPostCoverUrl } from "@/utils/blogMedia";
import { formatBlogDate, renderBlogMarkdown } from "@/utils/renderBlogMarkdown";

const props = defineProps({
    post: { type: Object, required: true },
});

const progress = ref(0);

const renderedContent = computed(() => renderBlogMarkdown(props.post.content));

const heroStyle = computed(() => {
    const url = getPostCoverUrl(props.post);

    return url ? { backgroundImage: `url(${url})` } : {};
});

const seoTitle = computed(() => props.post.seo_title || props.post.title || "Пост блога");
const seoDescription = computed(() => props.post.seo_description || props.post.excerpt || "");
const seoImage = computed(() => getPostCoverUrl(props.post) || "/android-chrome-512x512.png");
const seoKeywords = computed(() => (props.post.seo_keywords || []).join(", "));

function updateProgress() {
    const scrollTop = window.scrollY;
    const docHeight = document.documentElement.scrollHeight - window.innerHeight;

    progress.value = docHeight > 0 ? Math.min(100, (scrollTop / docHeight) * 100) : 0;
}

async function incrementView() {
    const tokenMatch = document.cookie.match(/XSRF-TOKEN=([^;]+)/);
    const token = tokenMatch ? decodeURIComponent(tokenMatch[1]) : "";

    try {
        await fetch(`/blog/${encodeURIComponent(props.post.slug)}/view`, {
            method: "POST",
            headers: {
                "X-XSRF-TOKEN": token,
                Accept: "application/json",
            },
            credentials: "same-origin",
        });
    } catch {
        // best effort
    }
}

onMounted(() => {
    updateProgress();
    window.addEventListener("scroll", updateProgress, { passive: true });
    incrementView();
});

onUnmounted(() => {
    window.removeEventListener("scroll", updateProgress);
});
</script>

<template>
    <Head>
        <title>{{ seoTitle }}</title>
        <meta head-key="description" name="description" :content="seoDescription" />
        <meta v-if="seoKeywords" head-key="keywords" name="keywords" :content="seoKeywords" />
        <meta property="og:title" :content="seoTitle" />
        <meta property="og:description" :content="seoDescription" />
        <meta property="og:image" :content="seoImage" />
    </Head>

    <BlogLayout>
        <div class="blog-reading-progress" :style="{ width: `${progress}%` }" />

        <article class="landing-page__fade landing-page__fade--1 mx-auto max-w-6xl px-4 sm:px-6 lg:px-8">
            <div class="post-hero" :style="heroStyle">
                <div class="post-hero__overlay" />
                <div class="blog-wrapper post-hero__content">
                    <nav class="post-breadcrumbs" aria-label="Хлебные крошки">
                        <Link href="/">Главная</Link>
                        <span class="post-breadcrumbs__sep">/</span>
                        <Link href="/blog">Блог</Link>
                        <span class="post-breadcrumbs__sep">/</span>
                        <span class="post-breadcrumbs__current">{{ post.title }}</span>
                    </nav>
                    <h1 class="post-hero__title">{{ post.title }}</h1>
                    <div class="post-hero__meta">
                        <time v-if="post.published_at" :datetime="post.published_at">
                            {{ formatBlogDate(post.published_at) }}
                        </time>
                        <span v-if="post.views_count != null" class="post-hero__views">
                            <Eye class="h-4 w-4" />
                            {{ post.views_count }}
                        </span>
                    </div>
                </div>
            </div>

            <div class="blog-wrapper">
                <div class="post-layout">
                    <div v-if="post.categories?.length || post.tags?.length" class="post-tags">
                        <span
                            v-for="cat in post.categories"
                            :key="`cat-${cat.id}`"
                            class="post-tag post-tag--category"
                        >
                            {{ cat.name }}
                        </span>
                        <span
                            v-for="tag in post.tags"
                            :key="`tag-${tag.id}`"
                            class="post-tag post-tag--tag"
                        >
                            #{{ tag.name }}
                        </span>
                    </div>

                    <div class="post-content" v-html="renderedContent" />

                    <div class="post-footer">
                        <Link href="/blog" class="post-back">
                            <ArrowLeft class="h-5 w-5" />
                            Все статьи
                        </Link>
                    </div>
                </div>
            </div>
        </article>
    </BlogLayout>
</template>