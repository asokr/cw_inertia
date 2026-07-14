<?php

use App\Http\Controllers\Web\Auth\CouponController;
use App\Http\Controllers\Web\Auth\ForgotPasswordController;
use App\Http\Controllers\Web\Auth\LoginController;
use App\Http\Controllers\Web\Auth\LogoutController;
use App\Http\Controllers\Web\Auth\RegisterController;
use App\Http\Controllers\Web\Auth\ResendVerificationController;
use App\Http\Controllers\Web\Auth\ResetPasswordController;
use App\Http\Controllers\Web\Auth\VerificationController;
use App\Http\Controllers\Web\Auth\VkOAuthController;
use App\Http\Controllers\Web\Auth\YandexOAuthController;
use App\Http\Controllers\Web\ContactMessageController;
use App\Http\Controllers\Web\HomeController;
use App\Http\Controllers\Web\PublicOfferController;
use App\Http\Controllers\Web\SupportMessageController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'index'])->name('home');
Route::get('/public-offer', [PublicOfferController::class, 'show'])->name('public-offer');
Route::get('/public-offer/', [PublicOfferController::class, 'show']);

require __DIR__.'/blog.php';

Route::redirect('/auth/login', '/login');
Route::redirect('/auth/register', '/register');
Route::redirect('/auth/forgot-password', '/forgot-password');
Route::get('/auth/reset-password/{token}', function (Request $request, string $token) {
    return redirect()->route('password.reset', array_filter([
        'token' => $token,
        'email' => $request->query('email'),
    ]));
});

Route::get('/email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
    ->middleware(['signed', 'throttle:6,1'])
    ->name('verification.verify');

Route::post('/check-coupon', [CouponController::class, 'check'])->name('coupon.check');
Route::post('/send-message', [ContactMessageController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.send');
Route::post('/support-message', [SupportMessageController::class, 'store'])
    ->middleware('throttle:5,1')
    ->name('support.message');
Route::post('/email/resend', [ResendVerificationController::class, 'store'])
    ->middleware('throttle:6,1')
    ->name('verification.resend');

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
    Route::get('/register', [RegisterController::class, 'create'])->name('register');
    Route::post('/register', [RegisterController::class, 'store']);

    Route::get('/forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');
    Route::get('/reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [ResetPasswordController::class, 'store'])->name('password.update');

    Route::get('/auth/vk/redirect', [VkOAuthController::class, 'redirect'])->name('auth.vk.redirect');
    Route::get('/auth/callback/vk', [VkOAuthController::class, 'callback'])->name('auth.vk.callback');
    Route::get('/auth/yandex/redirect', [YandexOAuthController::class, 'redirect'])->name('auth.yandex.redirect');
    Route::get('/auth/callback/yandex', [YandexOAuthController::class, 'callback'])->name('auth.yandex.callback');
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', LogoutController::class)->name('logout');
    Route::get('/email/verify', [VerificationController::class, 'notice'])->name('verification.notice');
});

Route::middleware(['auth', 'verified', 'panel.access'])
    ->prefix('panel')
    ->group(base_path('routes/subscriber.php'));

Route::middleware(['auth', 'verified', 'panel.access'])
    ->prefix('panel')
    ->group(base_path('routes/subscriber-tools.php'));

require __DIR__.'/admin.php';