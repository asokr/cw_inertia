<?php

declare(strict_types=1);

$outDir = __DIR__ . '/../public/images/home/previews';

if (! is_dir($outDir)) {
    mkdir($outDir, 0755, true);
}

$placeholders = [
    ['file' => 'hero.png', 'width' => 1200, 'height' => 675, 'title' => 'Hero', 'subtitle' => 'Панель CW Platform'],
    ['file' => 'feedbacks.png', 'width' => 1200, 'height' => 675, 'title' => 'Отзывы', 'subtitle' => 'Автоответы WB и Ozon'],
    ['file' => 'pricing.png', 'width' => 1200, 'height' => 675, 'title' => 'Ценообразование', 'subtitle' => 'Расчёт цены с маржой'],
    ['file' => 'profitability.png', 'width' => 1200, 'height' => 675, 'title' => 'Рентабельность', 'subtitle' => 'Прибыль по SKU'],
    ['file' => 'ai-text.png', 'width' => 400, 'height' => 300, 'title' => 'AI-описания', 'subtitle' => 'Генерация текстов'],
    ['file' => 'ai-image.png', 'width' => 400, 'height' => 300, 'title' => 'AI-фото', 'subtitle' => 'Генерация изображений'],
    ['file' => 'ai-video.png', 'width' => 400, 'height' => 300, 'title' => 'AI-видео', 'subtitle' => 'Генерация видео'],
];

foreach ($placeholders as $item) {
    $pngPath = $outDir . '/' . $item['file'];
    $webpPath = preg_replace('/\.png$/', '.webp', $pngPath);

    $image = buildPlaceholder(
        $item['width'],
        $item['height'],
        $item['title'],
        $item['subtitle'],
    );

    imagepng($image, $pngPath);
    imagewebp($image, $webpPath, 85);
    imagedestroy($image);

    echo "  {$pngPath} ({$item['width']}x{$item['height']})\n";
    echo "  {$webpPath}\n";
}

echo "Created " . count($placeholders) . " placeholders in {$outDir}\n";

function buildPlaceholder(
    int $width,
    int $height,
    string $title,
    string $subtitle,
) {
    $image = imagecreatetruecolor($width, $height);
    imagesavealpha($image, true);

    $bg = imagecolorallocate($image, 241, 245, 249);
    $border = imagecolorallocate($image, 203, 213, 225);
    $accent = imagecolorallocate($image, 99, 102, 241);
    $text = imagecolorallocate($image, 51, 65, 85);
    $muted = imagecolorallocate($image, 100, 116, 139);

    imagefill($image, 0, 0, $bg);
    imagerectangle($image, 0, 0, $width - 1, $height - 1, $border);

    $padding = (int) max(12, min($width, $height) * 0.04);
    imagefilledrectangle(
        $image,
        $padding,
        $padding,
        $width - $padding,
        $padding + 3,
        $accent,
    );

    $sizeLabel = "{$width} × {$height} px";
    $titleSize = (int) max(10, min($width, $height) * 0.08);
    $subtitleSize = (int) max(8, $titleSize * 0.7);
    $sizeFont = (int) max(8, $titleSize * 0.6);

    $centerY = (int) ($height / 2);
    $lineGap = (int) ($titleSize * 1.5);

    drawCenteredString($image, $titleSize, $title, $centerY - $lineGap, $text);
    drawCenteredString($image, $subtitleSize, $subtitle, $centerY, $muted);
    drawCenteredString($image, $sizeFont, $sizeLabel, $centerY + $lineGap, $accent);
    drawCenteredString($image, max(7, $sizeFont - 1), 'Замените этот файл скриншотом', $centerY + ($lineGap * 2), $muted);

    return $image;
}

function drawCenteredString($image, int $font, string $text, int $y, int $color): void
{
    $fontFile = 'C:/Windows/Fonts/arial.ttf';

    if (! file_exists($fontFile)) {
        $textWidth = imagefontwidth(5) * strlen($text);
        $x = (int) ((imagesx($image) - $textWidth) / 2);
        imagestring($image, 5, $x, $y - 8, $text, $color);

        return;
    }

    $bbox = imagettfbbox($font, 0, $fontFile, $text);
    $textWidth = abs($bbox[2] - $bbox[0]);
    $x = (int) ((imagesx($image) - $textWidth) / 2);
    imagettftext($image, $font, 0, $x, $y, $color, $fontFile, $text);
}