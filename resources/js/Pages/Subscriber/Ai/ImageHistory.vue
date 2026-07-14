<script setup>
import { onMounted, ref } from "vue";
import { Head, router } from "@inertiajs/vue3";
import { ArrowLeft, Plus } from "lucide-vue-next";
import AiGenerationDeleteDialog from "@/components/subscriber/ai/AiGenerationDeleteDialog.vue";
import AiImageGenerationsList from "@/components/subscriber/ai/AiImageGenerationsList.vue";
import AiLimitsBadge from "@/components/subscriber/ai/AiLimitsBadge.vue";
import ToolPageHeader from "@/components/subscriber/tools/ToolPageHeader.vue";
import Button from "@/components/ui/Button.vue";
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
    { label: "Изображения", href: "/panel/ai/image" },
    { label: "История" },
];

const deletingGenerationUuid = ref(null);
const deleteDialogOpen = ref(false);
const pendingDeleteUuid = ref(null);
const { showError, showSuccess } = useFlashToast();

const {
    limitsLoading,
    imageLimit,
    savedGenerations,
    generationsLoading,
    createGeneration,
    deleteGeneration,
    loadGenerations,
    refreshLimits,
} = useMarketplaceAi(props.limits, { limitsMode: "image" });

function handleOpenGeneration(generationUuid) {
    router.visit(`/panel/ai/image/${generationUuid}`);
}

async function handleCreateGeneration() {
    const result = await createGeneration();
    if (!result.ok) {
        showError(result.message);
        return;
    }

    showSuccess("Новая сессия");
    router.visit(`/panel/ai/image/${result.generation.uuid}`);
}

function handleDeleteGeneration(generationUuid) {
    pendingDeleteUuid.value = generationUuid;
    deleteDialogOpen.value = true;
}

function handleDeleteDialogOpen(value) {
    deleteDialogOpen.value = value;

    if (!value && !deletingGenerationUuid.value) {
        pendingDeleteUuid.value = null;
    }
}

async function confirmDeleteGeneration() {
    const generationUuid = pendingDeleteUuid.value;
    if (!generationUuid) {
        return;
    }

    deletingGenerationUuid.value = generationUuid;
    const result = await deleteGeneration(generationUuid);
    deletingGenerationUuid.value = null;

    if (!result.ok) {
        showError(result.message);
        return;
    }

    deleteDialogOpen.value = false;
    pendingDeleteUuid.value = null;
    showSuccess("Сессия удалена");
}

onMounted(async () => {
    await refreshLimits();
    await loadGenerations();
});
</script>

<template>
    <Head title="ИИ — История изображений" />

    <SubscriberLayout title="ИИ — История изображений" :breadcrumbs="breadcrumbs">
        <ToolPageHeader
            title="История генераций"
            description="Сохранённые сессии — откройте любую, чтобы продолжить редактирование в студии"
        >
            <template #actions>
                <Button href="/panel/ai/image" variant="outline" size="sm" class="h-8 gap-1.5 text-xs">
                    <ArrowLeft class="h-3.5 w-3.5" />
                    В студию
                </Button>
                <Button type="button" size="sm" class="h-8 gap-1.5 text-xs" @click="handleCreateGeneration">
                    <Plus class="h-3.5 w-3.5" />
                    Новая
                </Button>
                <AiLimitsBadge mode="image" :loading="limitsLoading" :image-limit="imageLimit" />
            </template>
        </ToolPageHeader>

        <div v-if="limitsLoading" class="mt-6 space-y-4">
            <Skeleton class="h-48 w-full rounded-2xl" />
        </div>

        <div v-else class="mt-5">
            <AiImageGenerationsList
                :items="savedGenerations"
                :loading="generationsLoading"
                :deleting-id="deletingGenerationUuid"
                @open="handleOpenGeneration"
                @create="handleCreateGeneration"
                @delete="handleDeleteGeneration"
            />
        </div>
        <AiGenerationDeleteDialog
            :open="deleteDialogOpen"
            media-label="изображения"
            :loading="Boolean(deletingGenerationUuid)"
            @update:open="handleDeleteDialogOpen"
            @confirm="confirmDeleteGeneration"
        />
    </SubscriberLayout>
</template>