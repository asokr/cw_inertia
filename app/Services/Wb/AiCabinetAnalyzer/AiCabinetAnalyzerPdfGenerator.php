<?php

namespace App\Services\Wb\AiCabinetAnalyzer;

use App\Models\Subscribers\Wb\AiCabinetAnalyzer\AiCabinetAnalyzerAiAnalysis;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;

class AiCabinetAnalyzerPdfGenerator
{
    public function generate(AiCabinetAnalyzerAiAnalysis $analysis): string
    {
        $analysis->loadMissing(['template', 'report.cabinet']);

        $bodyHtml = $this->buildBodyHtml($analysis);
        $html = $this->wrapHtmlDocument($bodyHtml, $this->buildDocumentTitle($analysis));

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 16,
            'margin_bottom' => 16,
            'margin_header' => 9,
            'margin_footer' => 9,
            'default_font' => 'dejavusans',
            'tempDir' => sys_get_temp_dir(),
        ]);

        $mpdf->SetHTMLFooter(
            '<div style="text-align: center; font-size: 8pt; color: #666;">Страница {PAGENO} из {nbpg}</div>'
        );
        $mpdf->WriteHTML($html);

        $tempPath = tempnam(sys_get_temp_dir(), 'ai_report_') . '.pdf';
        $mpdf->Output($tempPath, Destination::FILE);

        if (!file_exists($tempPath)) {
            throw new RuntimeException('Не удалось сгенерировать PDF-файл.');
        }

        return $tempPath;
    }

    private function buildBodyHtml(AiCabinetAnalyzerAiAnalysis $analysis): string
    {
        $format = $analysis->template?->response_format ?? 'json';

        if ($format === 'markdown' && !empty($analysis->analysis_markdown)) {
            return $this->convertMarkdownToHtml((string) $analysis->analysis_markdown);
        }

        $data = $analysis->analysis_json ?? [];
        if ($data === [] && !empty($analysis->analysis_text)) {
            $decoded = json_decode((string) $analysis->analysis_text, true);
            $data = is_array($decoded) ? $decoded : [];
        }

        return $this->buildJsonHtml($data);
    }

    private function buildDocumentTitle(AiCabinetAnalyzerAiAnalysis $analysis): string
    {
        $templateName = trim((string) ($analysis->template?->name ?? ''));
        if ($templateName !== '') {
            return $templateName;
        }

        return 'ИИ-анализ кабинета';
    }

    private function convertMarkdownToHtml(string $markdown): string
    {
        $converter = new GithubFlavoredMarkdownConverter([
            'html_input' => 'allow',
        ]);

        return $converter->convert($markdown)->getContent();
    }

    private function buildJsonHtml(array $data): string
    {
        $parts = [];

        $parts[] = '<h2>Общая оценка</h2>';
        $parts[] = '<p>' . $this->escape((string) ($data['summary'] ?? 'Нет данных.')) . '</p>';

        foreach ([
            'insights' => 'Основные insights',
            'risks' => 'Риски',
            'actions' => 'Рекомендации',
        ] as $key => $title) {
            $items = (array) ($data[$key] ?? []);
            if ($items === []) {
                continue;
            }

            $parts[] = '<h2>' . $this->escape($title) . '</h2>';
            $parts[] = '<table><thead><tr>'
                . '<th>Название</th><th>Описание</th><th>Приоритет</th>'
                . '</tr></thead><tbody>';

            foreach ($items as $item) {
                if (!is_array($item)) {
                    continue;
                }

                $priority = strtolower((string) ($item['priority'] ?? 'medium'));
                $priorityColor = match ($priority) {
                    'high' => '#cc0000',
                    'medium' => '#cc8800',
                    default => '#008800',
                };

                $parts[] = '<tr>'
                    . '<td>' . $this->escape((string) ($item['title'] ?? '')) . '</td>'
                    . '<td>' . $this->escape((string) ($item['description'] ?? '')) . '</td>'
                    . '<td><strong style="color:' . $priorityColor . ';">'
                    . $this->escape(strtoupper($priority))
                    . '</strong></td>'
                    . '</tr>';
            }

            $parts[] = '</tbody></table>';
        }

        $metrics = (array) ($data['metrics'] ?? []);
        if ($metrics !== []) {
            $parts[] = '<h2>Метрики</h2>';
            $parts[] = '<table><thead><tr><th>Название</th><th>Значение</th></tr></thead><tbody>';

            foreach ($metrics as $metric) {
                if (!is_array($metric)) {
                    continue;
                }

                $value = $metric['value'] ?? '';
                $valueText = is_scalar($value)
                    ? (string) $value
                    : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '');

                $parts[] = '<tr>'
                    . '<td>' . $this->escape((string) ($metric['label'] ?? $metric['key'] ?? '')) . '</td>'
                    . '<td>' . $this->escape($valueText) . '</td>'
                    . '</tr>';
            }

            $parts[] = '</tbody></table>';
        }

        return implode("\n", $parts);
    }

    private function wrapHtmlDocument(string $bodyHtml, string $title): string
    {
        $escapedTitle = $this->escape($title);

        return <<<HTML
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>{$escapedTitle}</title>
    <style>
        body {
            font-family: dejavusans, sans-serif;
            font-size: 11pt;
            line-height: 1.45;
            color: #222;
        }
        h1, h2, h3, h4 {
            color: #111;
            margin-top: 1.2em;
            margin-bottom: 0.5em;
        }
        h1 { font-size: 18pt; }
        h2 { font-size: 14pt; }
        h3 { font-size: 12pt; }
        p { margin: 0.4em 0 0.8em; }
        ul, ol { margin: 0.4em 0 0.8em 1.2em; }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 0.8em 0 1.2em;
            page-break-inside: auto;
        }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td {
            border: 1px solid #aaaaaa;
            padding: 6px 8px;
            vertical-align: top;
            text-align: left;
        }
        th {
            background: #f3f3f3;
            font-weight: bold;
        }
        code, pre {
            font-family: dejavusansmono, monospace;
            font-size: 9pt;
        }
        pre {
            background: #f7f7f7;
            padding: 8px;
            white-space: pre-wrap;
        }
        hr {
            border: none;
            border-top: 1px solid #cccccc;
            margin: 1em 0;
        }
    </style>
</head>
<body>
    <h1>{$escapedTitle}</h1>
    {$bodyHtml}
</body>
</html>
HTML;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}