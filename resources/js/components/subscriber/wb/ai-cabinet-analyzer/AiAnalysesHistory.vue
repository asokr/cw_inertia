<script setup>
import { computed } from "vue";
import { router } from "@inertiajs/vue3";
import {
    Download,
    Eye,
    History,
    RefreshCw,
} from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import {
    analysisStatusLabel,
    analysisStatusVariant,
    canRegenerateAnalysis,
    formatAnalysisDate,
    formatAnalysisTime,
} from "@/utils/aiCabinetAnalysisDisplay";
import { formatCredits } from "@/utils/credits";

const props = defineProps({
    items: { type: Array, default: () => [] },
    meta: {
        type: Object,
        default: () => ({
            current_page: 1,
            per_page: 15,
            total: 0,
            last_page: 1,
        }),
    },
    polling: { type: Boolean, default: false },
    regeneratingId: { type: [Number, null], default: null },
    showUrl: { type: String, default: "" },
    reportId: { type: [Number, String, null], default: null },
});

const emit = defineEmits(["refresh", "open", "regenerate", "download"]);

const hasItems = computed(() => props.items.length > 0);
const showPagination = computed(() => (props.meta?.last_page || 1) > 1);
const currentPage = computed(() => props.meta?.current_page || 1);
const lastPage = computed(() => props.meta?.last_page || 1);
const hasProcessingItems = computed(() =>
    props.items.some((item) => item?.status === "processing"),
);
/** Badge only while real polling of in-progress analyses */
const showUpdatingBadge = computed(() => Boolean(props.polling) && hasProcessingItems.value);

function isRegenerating(analysisId) {
    return analysisId && Number(props.regeneratingId) === Number(analysisId);
}

function displayDate(item) {
    return formatAnalysisDate(item.finished_at || item.created_at);
}

function displayTime(item) {
    return formatAnalysisTime(item.finished_at || item.created_at);
}

function changePage(page) {
    if (!props.showUrl || !props.reportId) {
        emit("refresh");
        return;
    }
    if (page < 1 || page > lastPage.value) return;

    router.get(props.showUrl, {
        report_id: props.reportId,
        analyses_page: page,
    }, {
        only: ["analyses", "analysesMeta"],
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <section class="space-y-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex items-start gap-3">
                <div
                    class="flex h-9 w-9 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary"
                >
                    <History class="h-4 w-4" />
                </div>
                <div class="min-w-0 space-y-0.5">
                    <p class="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                        Шаг 4
                    </p>
                    <h2 class="text-xl font-semibold tracking-tight sm:text-2xl">
                        История анализов
                    </h2>
                    <p class="text-sm text-muted-foreground sm:text-base">
                        Результаты по текущим данным кабинета. При обновлении данных история сбрасывается.
                    </p>
                </div>
            </div>
            <div v-if="hasItems || showUpdatingBadge" class="flex items-center gap-2">
                <Badge v-if="showUpdatingBadge" variant="default">Идёт обновление</Badge>
                <Button variant="outline" size="sm" @click="emit('refresh')">
                    <RefreshCw
                        class="mr-1.5 h-3.5 w-3.5"
                        :class="{ 'animate-spin': showUpdatingBadge }"
                    />
                    Обновить
                </Button>
            </div>
        </div>

        <!-- Empty state -->
        <div
            v-if="!hasItems"
            class="rounded-2xl border border-dashed bg-muted/20 px-6 py-10 text-center"
        >
            <div
                class="mx-auto mb-3 flex h-12 w-12 items-center justify-center rounded-full bg-muted text-muted-foreground"
            >
                <History class="h-5 w-5" />
            </div>
            <p class="text-base font-medium">Пока нет запусков</p>
            <p class="mt-1 text-sm text-muted-foreground">
                Запустите первый ИИ-анализ выше — результат появится здесь.
            </p>
        </div>

        <!-- Cards -->
        <div v-else class="space-y-3">
            <Card
                v-for="item in items"
                :key="item.id"
                class="overflow-hidden transition-all duration-200 hover:border-primary/25 hover:shadow-sm"
            >
                <div
                    class="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between sm:p-5"
                >
                    <div class="min-w-0 flex-1 space-y-2.5">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="text-base font-semibold tracking-tight">
                                {{ item.template?.name || "Без названия" }}
                            </h3>
                            <Badge :variant="analysisStatusVariant(item.status)">
                                {{ analysisStatusLabel(item.status) }}
                            </Badge>
                        </div>

                        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span class="font-medium text-foreground/80">
                                {{ displayDate(item) }}
                            </span>
                            <span class="tabular-nums">{{ displayTime(item) }}</span>
                            <span
                                v-if="item.status === 'done' && item.credits_charged"
                                class="tabular-nums"
                            >
                                Списано {{ formatCredits(item.credits_charged) }}
                            </span>
                        </div>

                        <p
                            v-if="item.status === 'failed' && item.error_message"
                            class="text-sm text-destructive"
                        >
                            {{ item.error_message }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 sm:shrink-0 sm:justify-end">
                        <Button
                            variant="default"
                            size="sm"
                            :disabled="item.status === 'processing'"
                            @click="emit('open', item)"
                        >
                            <Eye class="mr-1.5 h-3.5 w-3.5" />
                            Просмотреть
                        </Button>
                        <Button
                            v-if="item.status === 'done'"
                            variant="outline"
                            size="sm"
                            @click="emit('download', item)"
                        >
                            <Download class="mr-1.5 h-3.5 w-3.5" />
                            PDF
                        </Button>
                        <Button
                            v-if="canRegenerateAnalysis(item)"
                            variant="ghost"
                            size="sm"
                            :disabled="isRegenerating(item.id)"
                            @click="emit('regenerate', item)"
                        >
                            <RefreshCw
                                class="mr-1.5 h-3.5 w-3.5"
                                :class="{ 'animate-spin': isRegenerating(item.id) }"
                            />
                            Перегенерировать
                        </Button>
                    </div>
                </div>
            </Card>
        </div>

        <!-- Pagination -->
        <div
            v-if="showPagination"
            class="flex flex-wrap items-center justify-between gap-3 text-sm text-muted-foreground"
        >
            <span>
                Страница {{ currentPage }} из {{ lastPage }}
                <template v-if="meta.total"> ({{ meta.total }})</template>
            </span>
            <div class="flex items-center gap-2">
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage <= 1"
                    @click="changePage(currentPage - 1)"
                >
                    Назад
                </Button>
                <Button
                    variant="outline"
                    size="sm"
                    :disabled="currentPage >= lastPage"
                    @click="changePage(currentPage + 1)"
                >
                    Далее
                </Button>
            </div>
        </div>
    </section>
</template>
