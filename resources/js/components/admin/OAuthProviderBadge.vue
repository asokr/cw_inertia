<script setup>
defineProps({
    vkId: { type: [String, Number], default: null },
    yandexId: { type: [String, Number], default: null },
});

const vkLogo = "/images/logo-vk.svg";
const yandexLogo = "/images/logo-yandex.svg";

function hasValue(value) {
    return value !== null && value !== undefined && value !== "";
}
</script>

<template>
    <span
        v-if="hasValue(vkId) || hasValue(yandexId)"
        class="ml-1.5 inline-flex items-center gap-1 align-middle"
    >
        <img
            v-if="hasValue(vkId)"
            :src="vkLogo"
            alt="VK"
            title="Регистрация через ВКонтакте"
            class="h-4 w-4 rounded-sm"
        />
        <img
            v-if="hasValue(yandexId)"
            :src="yandexLogo"
            alt="Яндекс"
            title="Регистрация через Яндекс"
            class="h-4 w-4 rounded-sm"
        />
    </span>
</template>