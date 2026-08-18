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
import { formatCredits } from "@/utils/credits";

const props = defineProps({
    pricing: {
        type: Object,
        default: () => ({ text: { amount: 0 } }),
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
    creditsAvailable,
    textResult,
    richDescriptionResult,
    hasTextLimit,
    refreshLimits,
    runTextTask,
} = useMarketplaceAi({}, { limitsMode: "text" });

const textCost = computed(() => Number(props.pricing?.text?.amount ?? 0));
const hasResult = computed(() => Boolean(textResult.value || richDescriptionResult.value));

async function handleTextSubmit(payload) {
    const cost = textCost.value;
    const available = Number(creditsAvailable.value ?? 0);

    if (!hasTextLimit.value || available < cost) {
        showError(`Недостаточно кредитов. Нужно ${formatCredits(cost)}, доступно ${formatCredits(available)}.`);
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
                <AiLimitsBadge :loading="limitsLoading" :available="creditsAvailable" />
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
