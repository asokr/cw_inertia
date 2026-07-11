<?php

namespace App\Support;

class SubscriberLimitLabels
{
    private const LABELS = [
        'adverts_clients' => 'Кабинеты рекламы',
        'feedbacks_clients' => 'Кабинеты отзывов WB',
        'oz_feedbacks_clients' => 'Кабинеты отзывов Ozon',
        'price_calc_clients' => 'Кабинеты ценообразования WB',
        'oz_price_calc_clients' => 'Кабинеты ценообразования Ozon',
        'feedbacks_gpt_query' => 'Запросы к ИИ для отзывов',
        'ai_text_query' => 'Текстовые запросы к ИИ',
        'ai_image_query' => 'Генерация изображений ИИ',
        'ai_video_query' => 'Генерация видео ИИ',
        'repricer_nmid' => 'Номенклатуры в репрайсере',
    ];

    public static function label(string $key): string
    {
        return self::LABELS[$key] ?? $key;
    }
}