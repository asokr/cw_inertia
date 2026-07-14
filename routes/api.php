<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Web\Webhook\YooKassaWebhookController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Legacy external webhooks only. All application auth and CRUD live on web
| routes (Inertia session auth).
|
*/

Route::post('/payments/yoo/callback', YooKassaWebhookController::class)->name('payment.callback');