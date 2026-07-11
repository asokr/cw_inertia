<script setup>
import Card from "@/components/ui/Card.vue";
</script>

<template>
    <Card class="p-4 text-sm">
        <h4 class="mb-3 font-semibold">Как работать с инструментом</h4>
        <ol class="list-decimal space-y-2 pl-5 text-muted-foreground">
            <li>Добавьте кабинет с Client ID и API-ключом Ozon (доступ: товары, цены, аналитика).</li>
            <li>Откройте кабинет и выберите схему продаж — FBO или FBS.</li>
            <li>Обновите номенклатуру из Ozon, затем экспортируйте Excel-шаблон.</li>
            <li>Заполните жёлтые ячейки в Excel и импортируйте файл обратно.</li>
            <li>Итоговые цены рассчитаются автоматически при импорте.</li>
        </ol>
    </Card>
</template>