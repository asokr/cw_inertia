<script setup>
import { Link } from "@inertiajs/vue3";
import { onMounted, reactive } from "vue";
import Card from "@/components/ui/Card.vue";
import { useAdminWidgetApi } from "@/composables/useAdminWidgetApi";

const props = defineProps({
    rows: { type: Number, default: 5 },
});

const { getWidget } = useAdminWidgetApi();

const state = reactive({
    posts: [],
    loading: false,
});

function formatDate(dateStr) {
    if (!dateStr) return "—";
    const date = new Date(dateStr);
    if (Number.isNaN(date.getTime())) return dateStr;
    return date.toLocaleDateString("ru-RU", { day: "2-digit", month: "2-digit", year: "numeric" });
}

async function fetchData() {
    state.loading = true;
    try {
        const result = await getWidget("/cw-page/blog/widgets/last-posts", { rows: props.rows });
        state.posts = result?.data ?? [];
    } finally {
        state.loading = false;
    }
}

onMounted(fetchData);
</script>

<template>
    <Card class="p-4">
        <div class="mb-4 flex items-center justify-between">
            <h3 class="text-lg font-semibold">Последние посты блога</h3>
            <Link href="/cw-page/blog/posts" class="text-sm text-primary hover:underline">Все посты →</Link>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead>
                    <tr class="border-b text-left text-muted-foreground">
                        <th class="px-3 py-2">Дата</th>
                        <th class="px-3 py-2">Заголовок</th>
                        <th class="px-3 py-2">Категории</th>
                        <th class="px-3 py-2">Просмотры</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="post in state.posts" :key="post.id" class="border-b">
                        <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(post.published_at) }}</td>
                        <td class="px-3 py-2">
                            <Link :href="`/cw-page/blog/posts/${post.id}/edit`" class="text-primary hover:underline">
                                {{ post.title }}
                            </Link>
                        </td>
                        <td class="px-3 py-2">
                            <span v-if="post.categories?.length">
                                {{ post.categories.map((c) => c.name).join(", ") }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">{{ post.views_count ?? 0 }}</td>
                    </tr>
                    <tr v-if="!state.posts.length">
                        <td colspan="4" class="px-3 py-6 text-center text-muted-foreground">
                            {{ state.loading ? "Загрузка…" : "Нет данных" }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </Card>
</template>