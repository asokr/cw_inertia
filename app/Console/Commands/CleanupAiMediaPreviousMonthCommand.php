<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

class CleanupAiMediaPreviousMonthCommand extends Command
{
    protected $signature = 'ai-media:cleanup-previous-month {--dry-run : Показать, что будет удалено, без удаления}';
    protected $description = 'Удаляет из AI-хранилища директории за предыдущий год';

    public function handle(): int
    {
        $diskName = (string) config('services.ai_media.disk', 'private');
        $imagePrefix = trim((string) config('services.ai_media.image_prefix', 'ai/source-images'), '/');
        $videoPrefix = trim((string) config('services.ai_media.video_prefix', 'ai/generated-videos'), '/');

        $targetDate = now()->subYearNoOverflow();
        $targetYear = $targetDate->format('Y');

        $this->info('Целевой период для удаления: ' . $targetYear);
        $this->info('Диск: ' . $diskName);

        try {
            $disk = Storage::disk($diskName);

            $imageStats = $this->cleanupPrefix($disk, $imagePrefix, $targetYear);
            $videoStats = $this->cleanupPrefix($disk, $videoPrefix, $targetYear);

            $totalDirs = $imageStats['directories'] + $videoStats['directories'];
            $totalFiles = $imageStats['files'] + $videoStats['files'];

            $modeLabel = $this->option('dry-run') ? 'Найдено (dry-run)' : 'Удалено';
            $this->info($modeLabel . ' директорий: ' . $totalDirs . ', файлов: ' . $totalFiles);

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error('Ошибка очистки AI-хранилища: ' . $exception->getMessage());
            return self::FAILURE;
        }
    }

    /**
     * @return array{directories:int,files:int}
     */
    private function cleanupPrefix(FilesystemAdapter $disk, string $prefix, string $year): array
    {
        if ($prefix === '') {
            return ['directories' => 0, 'files' => 0];
        }

        $allDirectories = $disk->allDirectories($prefix);
        $targetSuffix = '/' . $year;

        $targetDirectories = array_values(array_filter(
            $allDirectories,
            static fn(string $directory): bool => str_ends_with($directory, $targetSuffix)
        ));

        if (empty($targetDirectories)) {
            $this->line('Для префикса ' . $prefix . ' директории за период не найдены');
            return ['directories' => 0, 'files' => 0];
        }

        $deletedDirectories = 0;
        $deletedFiles = 0;

        foreach ($targetDirectories as $directory) {
            $filesCount = count($disk->allFiles($directory));

            if ($this->option('dry-run')) {
                $this->line('[dry-run] ' . $directory . ' (файлов: ' . $filesCount . ')');
                $deletedDirectories++;
                $deletedFiles += $filesCount;
                continue;
            }

            $deleted = $disk->deleteDirectory($directory);
            if (! $deleted) {
                $this->warn('Не удалось удалить директорию: ' . $directory);
                continue;
            }

            $this->line('Удалена директория: ' . $directory . ' (файлов: ' . $filesCount . ')');
            $deletedDirectories++;
            $deletedFiles += $filesCount;
        }

        return [
            'directories' => $deletedDirectories,
            'files' => $deletedFiles,
        ];
    }
}
