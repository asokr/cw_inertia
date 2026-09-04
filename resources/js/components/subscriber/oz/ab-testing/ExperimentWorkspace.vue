<script setup>
import { computed } from "vue";
import BoundCampaignPanel from "./BoundCampaignPanel.vue";
import CampaignSelectStep from "./CampaignSelectStep.vue";
import ExperimentNameEditor from "./ExperimentNameEditor.vue";
import ExperimentSettingsPanel from "./ExperimentSettingsPanel.vue";
import LaunchStep from "./LaunchStep.vue";
import PhotosStep from "./PhotosStep.vue";
import SelectedProductCard from "./SelectedProductCard.vue";
import Button from "@/components/ui/Button.vue";
import Badge from "@/components/ui/Badge.vue";
import { resolveAbTestStatus } from "./abTestStatus";

const props = defineProps({
    product: {
        type: Object,
        default: null,
    },
    experiment: {
        type: Object,
        default: null,
    },
    baseUrl: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["experiment-updated", "campaign-deleted", "back"]);

const statusMeta = computed(() => resolveAbTestStatus(props.experiment?.status));
const hasCampaign = computed(() => !!props.experiment?.wb_advert_id);
const canEdit = computed(() => !!props.experiment?.can_edit);
const updateUrl = computed(() => {
    if (!props.experiment?.id) {
        return "";
    }
    return `${props.baseUrl}/experiments/${props.experiment.id}`;
});

function onUpdated(exp) {
    emit("experiment-updated", exp);
}

function onCampaignDeleted(exp) {
    emit("campaign-deleted", exp);
    emit("experiment-updated", exp);
}
</script>

<template>
    <div v-if="experiment" class="space-y-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="min-w-0 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h3 class="text-lg font-semibold">Эксперимент</h3>
                    <Badge :variant="statusMeta.variant">
                        {{ experiment.status_label || statusMeta.label }}
                    </Badge>
                </div>
                <ExperimentNameEditor
                    :experiment="experiment"
                    :update-url="updateUrl"
                    @updated="onUpdated"
                />
                <p
                    v-if="experiment.progress_label"
                    class="text-xs text-muted-foreground"
                >
                    {{ experiment.progress_label }}
                </p>
            </div>
            <Button variant="outline" size="sm" @click="emit('back')">
                К списку экспериментов
            </Button>
        </div>

        <SelectedProductCard v-if="product" :product="product" />

        <!-- Campaign -->
        <section class="space-y-3">
            <BoundCampaignPanel
                v-if="hasCampaign"
                :experiment="experiment"
                :base-url="baseUrl"
                @experiment-updated="onUpdated"
                @campaign-deleted="onCampaignDeleted"
            />
            <CampaignSelectStep
                v-if="canEdit && !hasCampaign"
                :product="product"
                :experiment="experiment"
                :base-url="baseUrl"
                :embedded="true"
                @experiment-updated="onUpdated"
            />
            <div
                v-else-if="!hasCampaign && !canEdit"
                class="rounded-lg border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground"
            >
                Рекламная кампания не привязана. Редактирование недоступно в текущем статусе.
            </div>
            <div v-if="hasCampaign && canEdit" class="flex justify-end">
                <details class="w-full overflow-visible rounded-lg border border-border/60 bg-muted/10">
                    <summary class="cursor-pointer px-3.5 py-2.5 text-sm font-medium">
                        Сменить / создать другую кампанию
                    </summary>
                    <div class="border-t border-border/50 p-3">
                        <CampaignSelectStep
                            :product="product"
                            :experiment="experiment"
                            :base-url="baseUrl"
                            :embedded="true"
                            @experiment-updated="onUpdated"
                        />
                    </div>
                </details>
            </div>
        </section>

        <!-- Settings + photos -->
        <ExperimentSettingsPanel
            :experiment="experiment"
            :base-url="baseUrl"
            :default-open="canEdit && !experiment.settings_ready"
            @experiment-updated="onUpdated"
        />

        <PhotosStep
            :product="null"
            :experiment="experiment"
            :base-url="baseUrl"
            :compact="true"
            @experiment-updated="onUpdated"
        />

        <!-- Run / stats / journal -->
        <LaunchStep
            :product="null"
            :experiment="experiment"
            :base-url="baseUrl"
            :embedded="true"
            @experiment-updated="onUpdated"
        />
    </div>
</template>
