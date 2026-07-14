<script setup>
import ToolGuideFaq from "@/components/subscriber/tools/ToolGuideFaq.vue";
</script>

<template>
    <ToolGuideFaq>
        <ol class="list-decimal space-y-2 pl-5">
            <li>Добавьте кабинет с API-ключом WB (доступы: Контент, Статистика, только чтение).</li>
            <li>Откройте кабинет, настройте параметры расчёта в диалоге «Настройки».</li>
            <li>Обновите список товаров, при необходимости импортируйте объёмы из отчёта WB.</li>
            <li>Экспортируйте Excel, заполните жёлтые ячейки и импортируйте файл обратно.</li>
            <li>Итоговые цены рассчитаются автоматически при импорте Excel.</li>
        </ol>
    </ToolGuideFaq>
</template>