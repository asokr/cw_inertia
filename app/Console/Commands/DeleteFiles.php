<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class DeleteFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'appfiles:delete';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Переодическая отчистка загруженых файлов';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $directory_path = [
            'wb/promocalculator/',
            'wb/pricecalculator/'
        ];

        foreach($directory_path as $path) {
            if (Storage::disk('public')->exists($path)) {
                Storage::disk('public')->deleteDirectory($path);
                Storage::disk('public')->makeDirectory($path);
            }
        }


    }
}
