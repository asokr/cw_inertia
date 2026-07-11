<script setup>
import { computed, onMounted } from "vue";
import { Head } from "@inertiajs/vue3";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import AiResult from "@/components/subscriber/ai/AiResult.vue";
import AiTextForm from "@/components/subscriber/ai/AiTextForm.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Skeleton from "@/components/ui/Skeleton.vue";
import SubscriberLayout from "@/Layouts/SubscriberLayout.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { useMarketplaceAi } from "@/composables/useMarketplaceAi";

const props = defineProps({
    limits: {
        type: Object,
        default: () => ({ text: 0, image: 0, video: 0 }),
    },
});

const breadcrumbs = [
    { label: "Главная", href: "/panel" },
    { label: "ИИ Инструменты", href: "/panel/ai/text" },
    { label: "Текст" },
];

const { showError } = useFlashToast();

const {
    loading,
    limitsLoading,
    textLimit,
    textResult,
    richDescriptionResult,
    hasTextLimit,
    refreshLimits,
    runTextTask,
} = useMarketplaceAi(props.limits, { limitsMode: "text" });

const hasResult = computed(() => Boolean(textResult.value || richDescriptionResult.value));

async function handleTextSubmit(payload) {
    if (!hasTextLimit.value) {
        showError("Недостаточно лимитов AI_TEXT_QUERY");
        return;
    }

    const result = await runTextTask(payload);
    if (!result.ok) {
        showError(result.message);
    }
}

onMounted(refreshLimits);
</script>

<template>
    <Head title="ИИ — Текст" />

    <SubscriberLayout title="ИИ — Текст" :breadcrumbs="breadcrumbs">
        <ToolPageHeader title="Генерация текста" description="Описания, адаптации и rich-контент для карточек товаров">
            <template #actions>
                <AiLimitsBadge mode="text" :loading="limitsLoading" :text-limit="textLimit" />
            </template>
        </ToolPageHeader>

        <div v-if="limitsLoading" class="mt-6 space-y-4">
            <Skeleton class="h-64 w-full rounded-2xl" />
        </div>

        <template v-else>
            <div class="mt-5 rounded-[20px] border bg-card p-6 shadow-sm">
                <AiTextForm
                    :loading="loading"
                    :disabled="!hasTextLimit"
                    @submit="handleTextSubmit"
                    @error="showError"
                />
            </div>

            <div v-if="hasResult || loading" class="mt-6">
                <AiResult
                    mode="text"
                    :loading="loading"
                    :text-result="textResult"
                    :rich-description-result="richDescriptionResult"
                    :images="[]"
                />
            </div>
        </template>
    </SubscriberLayout>
</template>