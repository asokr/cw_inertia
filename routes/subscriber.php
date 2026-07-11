<?php

use App\Http\Controllers\Web\Subscriber\ExtraLimitController;
use App\Http\Controllers\Web\Subscriber\ManagerController;
use App\Http\Controllers\Web\Subscriber\PanelController;
use App\Http\Controllers\Web\Subscriber\PlansController;
use App\Http\Controllers\Web\Subscriber\PaymentController;
use App\Http\Controllers\Web\Subscriber\ProfileController;
use App\Http\Controllers\Web\Subscriber\SubscriptionController;
use Illuminate\Support\Facades\Route;

Route::get('/', [PanelController::class, 'index'])->name('subscriber.panel');

Route::get('/manager', [ManagerController::class, 'index'])->name('subscriber.manager');

Route::get('/plans', [PlansController::class, 'index'])->name('subscriber.plans');

Route::get('/user/profile', [ProfileController::class, 'show'])->name('subscriber.profile');
Route::put('/user/profile', [ProfileController::class, 'update'])->name('subscriber.profile.update');
Route::post('/user/tour-seen', [ProfileController::class, 'tourSeen'])->name('subscriber.tour-seen');

Route::post('/user/change-plan', [SubscriptionController::class, 'changePlan'])->name('subscriber.change-plan');
Route::post('/user/cancel-downgrade', [SubscriptionController::class, 'cancelDowngrade'])->name('subscriber.cancel-downgrade');
Route::post('/user/unsubscribe', [SubscriptionController::class, 'unsubscribe'])->name('subscriber.unsubscribe');
Route::post('/user/resubscribe', [SubscriptionController::class, 'resubscribe'])->name('subscriber.resubscribe');

Route::post('/user/extra-limits', [ExtraLimitController::class, 'purchase'])->name('subscriber.extra-limits');

Route::get('/user/history', [PaymentController::class, 'index'])->name('subscriber.payments.history');
Route::post('/payments/deposit', [PaymentController::class, 'create'])->name('subscriber.payments.deposit');