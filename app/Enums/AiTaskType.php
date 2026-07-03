<?php

namespace App\Enums;

enum AiTaskType: string
{
    case GENERATE_DESCRIPTION = 'generate_description';
    case REWRITE_TEXT = 'rewrite_text';
    case REWRITE_OZON = 'rewrite_ozon';
    case REWRITE_WB = 'rewrite_wb';
    case ADAPT_WB = 'adapt_wb';
    case ADAPT_OZON = 'adapt_ozon';
    case GENERATE_OZON_RICH = 'generate_ozon_rich';
    case RICH_DESCRIPTION = 'rich_description';
    case GENERATE_IMAGE = 'generate_image';
    case EDIT_IMAGE = 'edit_image';
    case GENERATE_VIDEO = 'generate_video';
    case GENERATE_VIDEO_FROM_IMAGE = 'generate_video_from_image';
    case WB_FEEDBACK_ANSWER_AI = 'wb_feedback_answer_ai';
    case WB_FEEDBACK_ANSWER_TEMPLATE = 'wb_feedback_answer_template';
    case OZON_FEEDBACK_ANSWER_AI = 'ozon_feedback_answer_ai';
    case WB_AI_CABINET_ANALYZER_AI = 'wb_ai_cabinet_analyzer_ai';

    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
