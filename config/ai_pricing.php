<?php

return [
    'gpt' => [
        'gpt-4o-mini' => [
            'input' => 0.00000015,
            'output' => 0.00000060,
        ],
        'gpt-4o' => [
            'input' => 0.00000250,
            'output' => 0.00001000,
        ],
        'gpt-4.1' => [
            'input' => 0.00000200,
            'output' => 0.00000800,
        ],
        'default' => [
            'input' => 0.00000015,
            'output' => 0.00000060,
        ],
    ],

    'gemini' => [
        'models' => [
            // Gemini 3.1 Pro Preview (<=200k prompt tokens).
            'gemini-3.1-pro-preview' => [
                'input' => 0.00000200,
                'output' => 0.00001200,
            ],
            // Gemini 3.1 Flash-Lite Preview.
            'gemini-3.1-flash-lite-preview' => [
                'input' => 0.00000025,
                'output' => 0.00000150,
            ],
            // Gemini 3.1 Flash Image Preview (text/image input + text/thinking output).
            'gemini-3.1-flash-image-preview' => [
                'input' => 0.00000050,
                'output' => 0.00000300,
            ],
        ],
        'default' => [
            'input' => 0.00000200,
            'output' => 0.00001200,
        ],
        'image' => [
            // Базовая стоимость изображения для quality=default (0.5K).
            'base_per_image' => 0.045,
            // Множители качества относительно base_per_image.
            'quality_multipliers' => [
                'default' => 1.0,
                '1k' => 1.488889,
                '2k' => 2.244444,
                '4k' => 3.355556,
            ],
            // Явные цены за изображение по качеству (приоритетнее множителей).
            'per_image' => [
                'default' => 0.045,
                '1k' => 0.067,
                '2k' => 0.101,
                '4k' => 0.151,
            ],
        ],
    ],

    'grok' => [
        'video_per_sec' => 0.05,
    ],
];
