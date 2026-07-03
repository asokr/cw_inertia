<script setup>
import Card from "@/components/ui/Card.vue";
</script>

<template>
    <Card class="p-4 text-sm">
        <h4 class="mb-3 font-semibold">Как работать с инструментом</h4>
        <ol class="list-decimal space-y-2 pl-5 text-muted-foreground">
            <li>Добавьте кабинет с API-ключом WB (доступы: Контент, Статистика, только чтение).</li>
            <li>Откройте кабинет, настройте параметры расчёта в диалоге «Настройки».</li>
            <li>Обновите список товаров, при необходимости импортируйте объёмы из отчёта WB.</li>
            <li>Экспортируйте Excel, заполните жёлтые ячейки и импортируйте файл обратно.</li>
            <li>Итоговые цены рассчитаются автоматически при импорте Excel.</li>
        </ol>
    </Card>
</template>