<?php

namespace App\Enums\Credits;

enum CreditServiceCode: string
{
    case GenerateText = 'generate_text';
    case FeedbackAnswer = 'feedback_answer';
    case GenerateImage = 'generate_image';
    case EditImage = 'edit_image';
    case GenerateVideo = 'generate_video';
    case WbAiCabinetAnalyzer = 'wb_ai_cabinet_analyzer';
    case OzAiCabinetAnalyzer = 'oz_ai_cabinet_analyzer';

    public function label(): string
    {
        return match ($this) {
            self::GenerateText => 'Генерация текста',
            self::FeedbackAnswer => 'Ответ на отзыв',
            self::GenerateImage => 'Генерация изображения',
            self::EditImage => 'Обработка изображения',
            self::GenerateVideo => 'Генерация видео',
            self::WbAiCabinetAnalyzer => 'ИИ-анализ кабинета WB',
            self::OzAiCabinetAnalyzer => 'ИИ-анализ кабинета Ozon',
        };
    }
}
