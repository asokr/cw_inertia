<script setup>
import { ref } from "vue";
import { ChevronDown } from "lucide-vue-next";

const open = ref(false);
</script>

<template>
    <div class="rounded-lg border">
        <button
            type="button"
            class="flex w-full items-center justify-between px-4 py-3 text-left text-sm font-medium"
            @click="open = !open"
        >
            Как работать с инструментом
            <ChevronDown class="h-4 w-4 transition-transform" :class="open && 'rotate-180'" />
        </button>
        <div v-if="open" class="space-y-3 border-t px-4 py-3 text-sm text-muted-foreground">
            <p>Добавьте кабинет Wildberries с API-ключом, у которого есть доступ к разделу «Вопросы и отзывы».</p>
            <p>Откройте кабинет, чтобы увидеть неотвеченные отзывы и отправить ответ вручную или с помощью ИИ.</p>
            <p>Настройте шаблоны и автоответчик, чтобы бот отвечал на отзывы автоматически по выбранным оценкам.</p>
        </div>
    </div>
</template>