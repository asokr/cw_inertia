<script setup>
import Alert from "@/components/ui/Alert.vue";
</script>

<template>
    <Alert variant="default" class="text-sm">
        <p class="mb-2 font-medium">Порядок работы с инструментом:</p>
        <ol class="list-decimal space-y-1 pl-5">
            <li>В <strong>Настройках</strong> укажите параметры (источники данных, скрытие размеров и др.).</li>
            <li><strong>Шаг 1:</strong> Нажмите «Обновить список товаров», чтобы подтянуть карточки с Wildberries.</li>
            <li>
                <strong>Шаг 2:</strong> Скачайте «Отчёт с перечнем номенклатур» в кабинете WB
                (Аналитика → Отчёты) и загрузите через «Импорт объёма».
            </li>
            <li>
                <strong>Шаг 3:</strong> Нажмите «Экспорт Excel», заполните ячейки, выделенные
                <span class="rounded bg-[#FFF2CC] px-1 font-medium text-[#856404]">жёлтым</span>.
            </li>
            <li>
                <strong>Шаг 4:</strong> Нажмите «Импорт Excel» — расчёт итоговых цен произойдёт автоматически.
            </li>
        </ol>
    </Alert>
</template>