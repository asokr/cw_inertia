<script setup>
import { reactive, ref } from "vue";
import Alert from "@/components/ui/Alert.vue";
import Button from "@/components/ui/Button.vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";
import Select from "@/components/ui/Select.vue";
import { usePromoCalculatorApi } from "@/composables/usePromoCalculatorApi";

const props = defineProps({
    selected: { type: Array, default: () => [] },
    repricerCabinets: { type: Array, default: () => [] },
    canUseRepricer: { type: Boolean, default: false },
});

const emit = defineEmits(["success", "error"]);

const { sendToRepricer } = usePromoCalculatorApi();
const repricerCabinetId = ref(null);
const submitting = ref(false);
const bigError = ref("");

const dates = reactive({
    start: "",
    end: "",
});

async function submit() {
    if (!dates.start || !dates.end) {
        emit("error", "Заполните обе даты акции");
        return;
    }

    if (!repricerCabinetId.value) {
        emit("error", "Выберите кабинет репрайсера");
        return;
    }

    if (!props.selected.length) {
        emit("error", "Выберите номенклатуры для отправки");
        return;
    }

    submitting.value = true;
    bigError.value = "";

    try {
        await sendToRepricer({
            data: props.selected.map((item) => ({
                nm_id: item.nm_id,
                plan_price: item.plan_price,
            })),
            dates: { ...dates },
            cabinetId: repricerCabinetId.value,
        });
        emit("success", "Номенклатуры переданы в репрайсер");
    } catch (err) {
        if (err?.type === "bigError") {
            bigError.value = err.message;
        }
        emit("error", err?.message ?? "Не удалось отправить в репрайсер");
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="max-w-3xl space-y-4 rounded-lg border p-4">
        <h3 class="text-lg font-semibold">Отправить номенклатуру в репрайсер</h3>

        <Alert v-if="!canUseRepricer">
            Для отправки в репрайсер нужен доступ к инструменту «Репрайсер Wildberries».
        </Alert>

        <Alert v-else-if="!repricerCabinets.length">
            Добавьте кабинет в <a href="/panel/wb/repricer" class="underline">Репрайсер</a>.
        </Alert>

        <template v-else>
            <div class="max-w-md space-y-1">
                <Label for="repricer-cabinet">Кабинет репрайсера</Label>
                <Select
                    id="repricer-cabinet"
                    :model-value="repricerCabinetId"
                    @update:model-value="repricerCabinetId = Number($event) || null"
                >
                    <option :value="null">Выберите</option>
                    <option v-for="cabinet in repricerCabinets" :key="cabinet.id" :value="cabinet.id">
                        {{ cabinet.name }}
                    </option>
                </Select>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="space-y-1">
                    <Label for="promo-start">Дата и время (МСК) с</Label>
                    <Input id="promo-start" v-model="dates.start" type="datetime-local" required />
                </div>
                <div class="space-y-1">
                    <Label for="promo-end">Дата и время (МСК) по</Label>
                    <Input id="promo-end" v-model="dates.end" type="datetime-local" required />
                </div>
            </div>

            <Alert v-if="bigError" variant="destructive">{{ bigError }}</Alert>

            <Button :disabled="submitting || !selected.length" @click="submit">
                {{ submitting ? "Отправка…" : "Отправить" }}
            </Button>
        </template>
    </div>
</template>