<script setup>
import { computed } from "vue";
import { Download, Eye, RefreshCw } from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Badge from "@/components/ui/Badge.vue";
import Button from "@/components/ui/Button.vue";
import {
    analysisStatusLabel,
    analysisStatusVariant,
    canRegenerateAnalysis,
    formatAnalysisDateTime,
} from "@/utils/aiCabinetAnalysisDisplay";

const props = defineProps({
    items: { type: Array, default: () => [] },
    polling: { type: Boolean, default: false },
    regeneratingId: { type: [Number, null], default: null },
});

defineEmits(["refresh", "open", "regenerate", "download"]);

const hasItems = computed(() => props.items.length > 0);
const showFirstRunHint = computed(() => !hasItems.value);
const showActions = computed(() => hasItems.value || props.polling);

function isRegenerating(analysisId) {
    return analysisId && Number(props.regeneratingId) === Number(analysisId);
}
</script>

<template>
    <div class="space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-lg font-semibold">ИИ анализы текущих данных</h3>
                <p class="text-sm text-muted-foreground">
                    Проводится ИИ анализ данных, собранных для кабинета за указанный период.
                    При обновлении данных кабинета ИИ-отчёты удаляются.
                </p>
            </div>
            <div v-if="showActions" class="flex items-center gap-2">
                <Badge v-if="polling" variant="default">Идёт обновление</Badge>
                <Button variant="outline" size="sm" @click="$emit('refresh')">Обновить</Button>
            </div>
        </div>

        <Alert v-if="showFirstRunHint">
            Проведите первый ИИ-анализ, чтобы увидеть историю запусков.
        </Alert>

        <div v-else class="overflow-auto rounded-md border">
            <table class="w-full text-sm">
                <thead class="border-b bg-muted/40">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Шаблон</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Статус</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Завершён</th>
                        <th class="px-3 py-2 text-left font-medium text-muted-foreground">Ошибка</th>
                        <th class="px-3 py-2 text-center font-medium text-muted-foreground">Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="item in items"
                        :key="item.id"
                        class="border-b last:border-0 hover:bg-muted/30"
                    >
                        <td class="px-3 py-2 font-medium">{{ item.template?.name || "—" }}</td>
                        <td class="px-3 py-2">
                            <Badge :variant="analysisStatusVariant(item.status)">
                                {{ analysisStatusLabel(item.status) }}
                            </Badge>
                        </td>
                        <td class="px-3 py-2 text-muted-foreground">{{ formatAnalysisDateTime(item.finished_at) }}</td>
                        <td class="px-3 py-2">
                            <span v-if="item.status === 'failed'" class="text-xs text-destructive">
                                {{ item.error_message || "Ошибка выполнения" }}
                            </span>
                            <span v-else class="text-muted-foreground">—</span>
                        </td>
                        <td class="px-3 py-2">
                            <div class="flex items-center justify-center gap-1">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="item.status === 'processing'"
                                    @click="$emit('open', item)"
                                >
                                    <Eye class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="item.status === 'done'"
                                    variant="ghost"
                                    size="sm"
                                    @click="$emit('download', item)"
                                >
                                    <Download class="h-4 w-4" />
                                </Button>
                                <Button
                                    v-if="canRegenerateAnalysis(item)"
                                    variant="ghost"
                                    size="sm"
                                    :disabled="isRegenerating(item.id)"
                                    @click="$emit('regenerate', item)"
                                >
                                    <RefreshCw class="h-4 w-4" :class="{ 'animate-spin': isRegenerating(item.id) }" />
                                </Button>
                            </div>
                        </td>
                    </tr>
                    <tr v-if="!items.length">
                        <td colspan="5" class="px-3 py-6 text-center text-muted-foreground">
                            ИИ-анализы по этому отчёту пока не запускались
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</template>