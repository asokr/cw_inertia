<script setup>
import axios from "axios";
import { ref, watch } from "vue";
import Input from "@/components/ui/Input.vue";
import Label from "@/components/ui/Label.vue";

const model = defineModel({ type: String, default: "" });

const status = ref(null);
const message = ref("");
let debounceTimer = null;

watch(model, (code) => {
    status.value = null;
    message.value = "";

    if (!code?.trim()) return;

    clearTimeout(debounceTimer);
    debounceTimer = setTimeout(async () => {
        try {
            const { data } = await axios.post("/check-coupon", { code: code.trim() });
            if (data.success) {
                status.value = "valid";
                message.value = data.message ?? "Купон действителен";
            } else {
                status.value = "invalid";
                message.value = data.message ?? "Купон недействителен";
            }
        } catch (error) {
            status.value = "invalid";
            message.value = error.response?.data?.message ?? "Купон недействителен";
        }
    }, 400);
});
</script>

<template>
    <div class="space-y-2">
        <Label for="coupon_code">Купон (необязательно)</Label>
        <Input id="coupon_code" v-model="model" />
        <p
            v-if="message"
            class="text-xs"
            :class="status === 'valid' ? 'text-emerald-600' : 'text-destructive'"
        >
            {{ message }}
        </p>
    </div>
</template>