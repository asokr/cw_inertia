<script setup>
import Alert from "@/components/ui/Alert.vue";
</script>

<template>
    <Alert variant="default" class="text-sm">
        <p class="mb-2 font-medium">Порядок работы с инструментом:</p>
        <ol class="list-decimal space-y-1 pl-5">
            <li>Выберите режим <strong>FBO</strong> или <strong>FBS</strong> — у каждого своя таблица и файл Excel.</li>
            <li><strong>Шаг 1:</strong> Нажмите «Обновить номенклатуру», чтобы загрузить товары из Ozon.</li>
            <li>
                <strong>Шаг 2:</strong> Нажмите «Экспорт XLSX», заполните ячейки, выделенные
                <span class="rounded bg-[#FFF2CC] px-1 font-medium text-[#856404]">жёлтым</span>.
            </li>
            <li>
                <strong>Шаг 3:</strong> Нажмите «Импорт XLSX» — расчёт цен запустится автоматически.
            </li>
            <li>При необходимости нажмите «Рассчитать» для повторного пересчёта без импорта.</li>
        </ol>
    </Alert>
</template>