<script setup>
import { computed, reactive, ref } from "vue";
import { Send } from "lucide-vue-next";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Card from "@/components/ui/Card.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const props = defineProps({
    selected: { type: Array, default: () => [] },
    cabinet: { type: Object, required: true },
    canUseRepricer: { type: Boolean, default: false },
});

const emit = defineEmits(["success", "error"]);

const { sendToRepricer } = usePromoCalculatorApi();
const submitting = ref(false);

const dates = reactive({
    start: "",
    end: "",
});

const selectedCount = computed(() => props.selected.length);

async function submit() {
    if (!dates.start || !dates.end) {
        emit("error", "Заполните обе даты акции");
        return;
    }

    if (!props.selected.length) {
        emit("error", "Выберите номенклатуры для отправки");
        return;
    }

    submitting.value = true;

    try {
        await sendToRepricer({
            data: props.selected.map((item) => ({
                nm_id: item.nm_id,
                plan_price: item.plan_price,
            })),
            dates: { ...dates },
        });
        emit("success", "Номенклатуры переданы в репрайсер");
    } catch (err) {
        emit("error", err?.message ?? "Не удалось отправить в репрайсер");
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Card class="overflow-hidden">
        <div class="border-b border-border/60 px-5 py-4">
            <h3 class="text-base font-semibold">Отправить в репрайсер</h3>
            <p class="mt-1 text-sm text-muted-foreground">
                Создаст (или обновит) стратегию по времени для выбранных номенклатур
                в кабинете
                <span class="font-medium text-foreground">«{{ cabinet.name }}»</span>.
                Период акции сохранится с датой и временем (разовый интервал, не каждый день).
            </p>
        </div>

        <div class="space-y-4 p-5">
            <Alert v-if="!canUseRepricer">
                Для отправки нужен доступ к инструменту
                <a href="/panel/wb/repricer" class="font-medium underline underline-offset-2">Репрайсер</a>.
            </Alert>

            <template v-else>
                <div class="grid gap-3 sm:grid-cols-2">
                    <div class="space-y-1.5">
                        <Label for="promo-start">Начало акции (МСК)</Label>
                        <Input id="promo-start" v-model="dates.start" type="datetime-local" required />
                    </div>
                    <div class="space-y-1.5">
                        <Label for="promo-end">Окончание акции (МСК)</Label>
                        <Input id="promo-end" v-model="dates.end" type="datetime-local" required />
                    </div>
                </div>

                <div class="flex flex-wrap items-center justify-between gap-3">
                    <p class="text-sm text-muted-foreground">
                        Выбрано:
                        <span class="font-medium text-foreground">{{ selectedCount }}</span>
                        номенклатур
                    </p>
                    <Button :disabled="submitting || !selected.length" @click="submit">
                        <Send class="mr-2 h-4 w-4" />
                        {{ submitting ? "Отправка…" : "Отправить в репрайсер" }}
                    </Button>
                </div>
            </template>
        </div>
    </Card>
</template>
