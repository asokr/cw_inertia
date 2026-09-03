<script setup>
import { computed, ref } from "vue";
import axios from "axios";
import { Megaphone, Pause, Trash2 } from "lucide-vue-next";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import Dialog from "@/components/ui/Dialog.vue";
import { useFlashToast } from "@/composables/useFlashToast";
import { resolveCampaignStatus } from "./campaignStatus";

const props = defineProps({
    experiment: {
        type: Object,
        required: true,
    },
    baseUrl: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["experiment-updated", "campaign-deleted"]);

const { showError, showSuccess } = useFlashToast();

const busy = ref(false);
const deleteOpen = ref(false);

const liveStatus = ref(null);
const liveStatusLabel = ref("");
const liveCanPause = ref(true);

const advertId = computed(() => props.experiment?.wb_advert_id ?? null);
const advertName = computed(
    () => props.experiment?.wb_advert_name || (advertId.value ? `Кампания #${advertId.value}` : "—"),
);

const experimentStatus = computed(() => props.experiment?.status ?? "draft");
const isRunning = computed(() => experimentStatus.value === "running");
const canEdit = computed(() => !!props.experiment?.can_edit);

const canPause = computed(
    () => !!advertId.value && !isRunning.value && canEdit.value && liveCanPause.value,
);

const canDelete = computed(
    () =>
        !!advertId.value &&
        !isRunning.value &&
        canEdit.value &&
        !!props.experiment?.campaign_created_by_tool,
);

const statusBadge = computed(() => {
    if (liveStatus.value != null) {
        const resolved = resolveCampaignStatus(liveStatus.value);
        return {
            label: liveStatusLabel.value || resolved.label,
            variant: resolved.variant,
        };
    }
    return null;
});

async function pauseCampaign() {
    if (!advertId.value || !canPause.value || busy.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.post(
            `${props.baseUrl}/campaigns/${advertId.value}/pause`,
            { experiment_id: props.experiment?.id },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось поставить кампанию на паузу");
            return;
        }

        showSuccess(data?.messages?.[0] || "Кампания остановлена");
        liveStatus.value = data.campaign?.status ?? "CAMPAIGN_STATE_INACTIVE";
        liveStatusLabel.value = data.campaign?.status_label || "Остановлена";
        liveCanPause.value = !!data.campaign?.can_pause;
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось поставить кампанию на паузу",
        );
    } finally {
        busy.value = false;
    }
}

function openDelete() {
    deleteOpen.value = true;
}

async function confirmDelete() {
    if (!advertId.value || busy.value) {
        return;
    }

    busy.value = true;
    try {
        const { data } = await axios.delete(
            `${props.baseUrl}/campaigns/${advertId.value}`,
            {
                params: { experiment_id: props.experiment?.id },
            },
        );

        if (!data?.success) {
            showError(data?.messages?.[0] || "Не удалось удалить кампанию");
            return;
        }

        showSuccess(data?.messages?.[0] || "Кампания удалена");
        deleteOpen.value = false;

        if (data.experiment) {
            emit("campaign-deleted", data.experiment);
            emit("experiment-updated", data.experiment);
        } else {
            emit("campaign-deleted", {
                ...(props.experiment ?? {}),
                wb_advert_id: null,
                wb_advert_name: null,
            });
        }
    } catch (error) {
        showError(
            error?.response?.data?.messages?.[0] ||
                "Не удалось удалить кампанию",
        );
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        v-if="advertId"
        class="rounded-xl border border-border/70 bg-card/60 px-3.5 py-3 sm:px-4"
    >
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 items-start gap-3">
                <div
                    class="mt-0.5 flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary"
                >
                    <Megaphone class="h-4 w-4" />
                </div>
                <div class="min-w-0">
                    <p class="text-sm font-semibold text-foreground">
                        Рекламная кампания эксперимента
                    </p>
                    <p class="mt-0.5 text-xs text-muted-foreground sm:text-sm">
                        <span class="font-medium text-foreground">{{ advertName }}</span>
                        <span class="mx-1.5 text-muted-foreground">·</span>
                        <span class="tabular-nums">ID {{ advertId }}</span>
                    </p>
                    <div v-if="statusBadge || isRunning" class="mt-1.5 flex flex-wrap gap-1">
                        <Badge v-if="statusBadge" :variant="statusBadge.variant" class="text-[11px]">
                            {{ statusBadge.label }}
                        </Badge>
                        <Badge v-if="isRunning" variant="warning" class="text-[11px]">
                            В A/B-тесте
                        </Badge>
                    </div>
                    <p
                        v-if="isRunning"
                        class="mt-1 text-[11px] text-muted-foreground"
                    >
                        Пока эксперимент запущен, пауза и удаление кампании недоступны — сначала
                        остановите эксперимент.
                    </p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-1.5">
                <Button
                    size="sm"
                    variant="outline"
                    class="h-8"
                    :disabled="!canPause || busy"
                    @click="pauseCampaign"
                >
                    <Pause class="mr-1.5 h-3.5 w-3.5" />
                    Пауза
                </Button>
                <Button
                    v-if="experiment?.campaign_created_by_tool"
                    size="sm"
                    variant="ghost"
                    class="h-8 text-destructive hover:text-destructive"
                    :disabled="!canDelete || busy"
                    @click="openDelete"
                >
                    <Trash2 class="mr-1.5 h-3.5 w-3.5" />
                    Удалить
                </Button>
            </div>
        </div>

        <Dialog
            :open="deleteOpen"
            title="Удалить кампанию?"
            description="Кампания будет удалена в Ozon и отвязана от эксперимента. Потребуется заново выбрать или создать кампанию."
            @update:open="(v) => { if (!v) deleteOpen = false }"
        >
            <p class="text-sm text-muted-foreground">
                Кампания:
                <span class="font-medium text-foreground">{{ advertName }}</span>
                (ID {{ advertId }})
            </p>
            <template #footer>
                <Button variant="outline" :disabled="busy" @click="deleteOpen = false">
                    Отмена
                </Button>
                <Button variant="destructive" :disabled="busy" @click="confirmDelete">
                    {{ busy ? "Удаление…" : "Удалить кампанию" }}
                </Button>
            </template>
        </Dialog>
    </div>
</template>
