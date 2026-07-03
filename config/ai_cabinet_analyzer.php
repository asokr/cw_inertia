<?php

return [
    /*
    |--------------------------------------------------------------------------
    | AiCabinet Analyzer - Справочник полей
    |--------------------------------------------------------------------------
    |
    | Здесь хранятся человекопонятные названия полей для использования в
    | ИИ-анализе. Эти названия должны использоваться в ответах ИИ вместо
    | технических названий колонок из базы данных / JSON.
    |
    | Ключ — техническое имя поля (как приходит в dataset).
    | Значение — понятное человеку название на русском языке.
    |
    */

    'funnel_field_labels' => [
        // Основные метрики воронки (selected)
        'open_count'                  => 'Количество открытий карточки товара',
        'cart_count'                  => 'Количество добавлений в корзину',
        'order_count'                 => 'Количество заказов',
        'order_sum'                   => 'Сумма заказов',
        'buyout_count'                => 'Количество выкупов',
        'buyout_sum'                  => 'Сумма выкупов',
        'cancel_count'                => 'Количество отмен',
        'cancel_sum'                  => 'Сумма отмен',
        'avg_price'                   => 'Средняя цена товара',
        'share_order_percent'         => 'Доля заказов от открытий',
        'add_to_wishlist'             => 'Добавлено в избранное',
        'localization_percent'        => 'Процент локализации',

        // Конверсии
        'add_to_cart_percent'         => 'Конверсия из открытия в корзину',
        'cart_to_order_percent'       => 'Конверсия из корзины в заказ',
        'buyout_percent'              => 'Конверсия выкупа',

        // Реклама
        'clicks'                      => 'Клики по рекламе',
        'views'                       => 'Показы рекламы',
        'spend'                       => 'Расход на рекламу',
        'orders'                      => 'Заказы из рекламы',

        // Сравнение рекламы и воронки
        'orders_gap'                  => 'Разница заказов (реклама минус воронка)',
        'orders_ratio_ads_to_funnel'  => 'Соотношение заказов рекламы к воронке',

        // Дополнительные (если используются)
        'avg_orders_count_per_day'    => 'Среднее количество заказов в день',
    ],

    'reviews_field_labels' => [
        'pros'                => 'Достоинства товара',
        'cons'                => 'Недостатки товара',
        'bables'              => 'Список тегов покупателя',
        'rating_distribution' => 'Распределение рейтинга',
        'average_rating'      => 'Средний рейтинг',
        'with_photos'         => 'Отзывы с фото',
        'without_photos'      => 'Отзывы без фото',
    ],

    'feedbacks_field_labels' => [
        'id'                            => 'ID отзыва',
        'text'                          => 'Текст отзыва',
        'pros'                          => 'Достоинства товара',
        'cons'                          => 'Недостатки товара',
        'productValuation'              => 'Оценка товара',
        'createdDate'                   => 'Дата и время создания отзыва',
        'answer'                        => 'Ответ продавца',
        'state'                         => 'Статус отзыва',
        'productDetails'                => 'Информация о товаре',
        'photoLinks'                    => 'Фотографии отзыва',
        'video'                         => 'Видео отзыва',
        'wasViewed'                     => 'Просмотрен ли отзыв',
        'userName'                      => 'Имя автора отзыва',
        'orderStatus'                   => 'Статус заказа',
        'matchingSize'                  => 'Соответствие размера',
        'isAbleSupplierFeedbackValuation' => 'Доступна ли жалоба на отзыв',
        'supplierFeedbackValuation'     => 'Ключ причины жалобы на отзыв',
        'isAbleSupplierProductValuation' => 'Доступна ли жалоба на товар',
        'supplierProductValuation'      => 'Ключ проблемы с товаром',
        'isAbleReturnProductOrders'     => 'Доступна ли опция возврата',
        'returnProductOrdersDate'       => 'Дата ответа на запрос возврата',
        'bables'                        => 'Теги покупателя',
        'lastOrderShkId'                => 'Штрихкод единицы товара',
        'lastOrderCreatedAt'            => 'Дата покупки',
        'color'                         => 'Цвет товара',
        'subjectId'                     => 'ID предмета',
        'subjectName'                   => 'Название предмета',
    ],

    'feedbacks_api_field_reference' => <<<'TEXT'
СПРАВОЧНИК ПОЛЕЙ WB API (data.feedbacks[]):
- id (string): ID отзыва
- text (string): Текст отзыва
- pros (string): Достоинства товара
- cons (string): Недостатки товара
- productValuation (integer): Оценка товара (1–5)
- createdDate (date-time): Дата и время создания отзыва
- answer (object|null): Структура ответа продавца на отзыв
- state (string): Статус отзыва — none (не обработан, новый), wbRu (обработан)
- productDetails (object): Информация о товаре (nmId, productName, supplierArticle, brandName и др.)
- photoLinks (array|null): Массив фотографий отзыва
- video (object|null): Структура видео отзыва
- wasViewed (boolean): Просмотрен ли отзыв продавцом
- userName (string): Имя автора отзыва
- orderStatus (string): Статус заказа — buyout (выкуплен), rejected (отказ), returned (возврат), notSpecified (не присвоен)
- matchingSize (string): Соответствие заявленного размера реальному — пусто (безразмерный), ok (соответствует), smaller (маломер), bigger (большемер)
- isAbleSupplierFeedbackValuation (boolean): Доступна ли продавцу жалоба на отзыв (true/false)
- supplierFeedbackValuation (integer): Ключ причины жалобы на отзыв
- isAbleSupplierProductValuation (boolean): Доступна ли продавцу жалоба на товар (true/false)
- supplierProductValuation (integer): Ключ проблемы с товаром
- isAbleReturnProductOrders (boolean): Доступна ли опция возврата товара (true/false)
- returnProductOrdersDate (string|null): Дата и время ответа на запрос возврата со статус-кодом 200
- bables (array|null): Список тегов покупателя
- lastOrderShkId (integer): Штрихкод единицы товара
- lastOrderCreatedAt (string): Дата покупки
- color (string): Цвет товара
- subjectId (integer): ID предмета
- subjectName (string): Название предмета
TEXT,

    'feedbacks_field_instructions' => 'При анализе блока feedbacks учитывай оценку (productValuation), текст, достоинства/недостатки, теги покупателя (bables), наличие ответа продавца (answer), статус заказа (orderStatus) и соответствие размера (matchingSize). Используй человекопонятные русские названия полей из справочника.',

    /*
     * Дополнительный текст для промпта ИИ (опционально).
     * Можно использовать, если нужно передать больше контекста.
     */
    'field_instructions' => 'Используй только приведённые выше человекопонятные названия полей. Никогда не пиши технические имена колонок (типа open_count, cart_count, average_rating, bables и т.д.).',
];
