<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Dashboard\chatGPT\GptLogsModel;

class SubscriberWbFeedbackLogClear extends Command
{
        /**
         * The name and signature of the console command.
         *
         * @var string
         */
        protected $signature = 'subscriber:wb-feedbacks-log-clear';

        /**
         * The console command description.
         *
         * @var string
         */
        protected $description = 'Удаляем логи старше месяца';

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
            GptLogsModel::where('created_at', '<', Carbon::now()->subDays(30))->delete();
        }
}

