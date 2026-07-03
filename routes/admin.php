<?php

use App\Http\Controllers\Web\Admin\AdminController;
use App\Http\Controllers\Web\Admin\Ai\CostArchiveController as AiCostArchiveController;
use App\Http\Controllers\Web\Admin\Ai\MarketplaceLogController as AiMarketplaceLogController;
use App\Http\Controllers\Web\Admin\Ai\MediaController as AiMediaController;
use App\Http\Controllers\Web\Admin\AiCabinet\CabinetController as AiCabinetCabinetController;
use App\Http\Controllers\Web\Admin\AiCabinet\PromptController as AiCabinetPromptController;
use App\Http\Controllers\Api\Admin\Blog\BlogMediaController;
use App\Http\Controllers\Api\Admin\PaymentsController as ApiPaymentsController;
use App\Http\Controllers\Api\Admin\WidgetsController as ApiWidgetsController;
use App\Http\Controllers\Api\Admin\wb\WbApiUsageStatsController;
use App\Http\Controllers\Web\Admin\Blog\CategoryController as BlogCategoryController;
use App\Http\Controllers\Web\Admin\Blog\PostController as BlogPostController;
use App\Http\Controllers\Web\Admin\Blog\TagController as BlogTagController;
use App\Http\Controllers\Web\Admin\CouponController;
use App\Http\Controllers\Web\Admin\ExtraLimitController;
use App\Http\Controllers\Web\Admin\Feedbacks\AiAnswerController as FeedbacksAiAnswerController;
use App\Http\Controllers\Web\Admin\Feedbacks\CabinetController as FeedbacksCabinetController;
use App\Http\Controllers\Web\Admin\PaymentController;
use App\Http\Controllers\Web\Admin\PlanController;
use App\Http\Controllers\Web\Admin\Repricer\CabinetController as RepricerCabinetController;
use App\Http\Controllers\Web\Admin\Repricer\NmidController as RepricerNmidController;
use App\Http\Controllers\Web\Admin\RoleController;
use App\Http\Controllers\Web\Admin\SentEmailController;
use App\Http\Controllers\Web\Admin\SubscriberController;
use App\Http\Controllers\Web\Admin\UserController;
use App\Http\Controllers\Web\Admin\WidgetController;
use App\Http\Controllers\Web\Admin\Wb\ApiUsageController as WbApiUsageController;
use App\Http\Controllers\Web\Admin\Wb\ApiUsageLogController as WbApiUsageLogController;
use Illuminate\Support\Facades\Route;

Route::middleware(['admin.access', 'verified'])
    ->prefix('cw-page')
    ->name('admin.')
    ->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');

        Route::middleware(['role:Супер-Админ|super-admin'])->prefix('widgets')->name('widgets.')->group(function () {
            Route::post('last-registered', [ApiWidgetsController::class, 'lastRegistered'])->name('last-registered');
            Route::post('last-subscriptions', [ApiWidgetsController::class, 'lastSubscriptions'])->name('last-subscriptions');
            Route::post('last-payments', [ApiPaymentsController::class, 'payments'])->name('last-payments');
            Route::get('wb-api-usage', [WbApiUsageStatsController::class, 'index'])->name('wb-api-usage');
        });

        Route::middleware(['permission:blog.view'])->prefix('blog')->name('blog.')->group(function () {
            Route::get('posts', [BlogPostController::class, 'index'])->name('posts.index');
            Route::middleware(['permission:blog.create'])->group(function () {
                Route::get('posts/create', [BlogPostController::class, 'create'])->name('posts.create');
                Route::post('posts', [BlogPostController::class, 'store'])->name('posts.store');
                Route::post('upload-image', [BlogMediaController::class, 'uploadImage'])->name('upload-image');
            });
            Route::middleware(['permission:blog.update'])->group(function () {
                Route::get('posts/{post}/edit', [BlogPostController::class, 'edit'])->name('posts.edit');
                Route::put('posts/{post}', [BlogPostController::class, 'update'])->name('posts.update');
            });
            Route::middleware(['permission:blog.delete'])->group(function () {
                Route::delete('posts/{post}', [BlogPostController::class, 'destroy'])->name('posts.destroy');
            });

            Route::get('categories', [BlogCategoryController::class, 'index'])->name('categories.index');
            Route::middleware(['permission:blog.create'])->post('categories', [BlogCategoryController::class, 'store'])->name('categories.store');
            Route::middleware(['permission:blog.update'])->put('categories/{category}', [BlogCategoryController::class, 'update'])->name('categories.update');
            Route::middleware(['permission:blog.delete'])->delete('categories/{category}', [BlogCategoryController::class, 'destroy'])->name('categories.destroy');

            Route::get('tags', [BlogTagController::class, 'index'])->name('tags.index');
            Route::middleware(['permission:blog.create'])->post('tags', [BlogTagController::class, 'store'])->name('tags.store');
            Route::middleware(['permission:blog.update'])->put('tags/{tag}', [BlogTagController::class, 'update'])->name('tags.update');
            Route::middleware(['permission:blog.delete'])->delete('tags/{tag}', [BlogTagController::class, 'destroy'])->name('tags.destroy');

            Route::get('widgets/last-posts', [WidgetController::class, 'lastBlogPosts'])->name('widgets.last-posts');
        });

        Route::middleware(['role:Супер-Админ|super-admin'])->group(function () {
            Route::get('subscribers', [SubscriberController::class, 'index'])->name('subscribers.index');
            Route::get('subscribers/search', [SubscriberController::class, 'search'])->name('subscribers.search');
            Route::get('subscribers/{subscriber}', [SubscriberController::class, 'edit'])->name('subscribers.edit');
            Route::put('subscribers/{subscriber}', [SubscriberController::class, 'update'])->name('subscribers.update');
            Route::post('subscribers/{subscriber}/deposit', [SubscriberController::class, 'deposit'])->name('subscribers.deposit');
            Route::post('subscribers/{subscriber}/withdraw', [SubscriberController::class, 'withdraw'])->name('subscribers.withdraw');
            Route::post('subscribers/{subscriber}/transactions/{transaction}/reverse', [SubscriberController::class, 'reverseTransaction'])->name('subscribers.transactions.reverse');

            Route::get('plans', [PlanController::class, 'index'])->name('plans.index');
            Route::get('plans/create', [PlanController::class, 'create'])->name('plans.create');
            Route::post('plans', [PlanController::class, 'store'])->name('plans.store');
            Route::get('plans/{plan}/edit', [PlanController::class, 'edit'])->name('plans.edit');
            Route::put('plans/{plan}', [PlanController::class, 'update'])->name('plans.update');
            Route::patch('plans/{plan}/status', [PlanController::class, 'toggleStatus'])->name('plans.status');

            Route::get('extra-limits', [ExtraLimitController::class, 'index'])->name('extra-limits.index');
            Route::post('extra-limits', [ExtraLimitController::class, 'store'])->name('extra-limits.store');
            Route::put('extra-limits/{extraLimit}', [ExtraLimitController::class, 'update'])->name('extra-limits.update');
            Route::delete('extra-limits/{extraLimit}', [ExtraLimitController::class, 'destroy'])->name('extra-limits.destroy');

            Route::get('payments', [PaymentController::class, 'index'])->name('payments.index');

            Route::get('coupons', [CouponController::class, 'index'])->name('coupons.index');
            Route::post('coupons', [CouponController::class, 'store'])->name('coupons.store');
            Route::put('coupons/{coupon}', [CouponController::class, 'update'])->name('coupons.update');
            Route::delete('coupons/{coupon}', [CouponController::class, 'destroy'])->name('coupons.destroy');

            Route::get('sent-emails', [SentEmailController::class, 'index'])->name('sent-emails.index');
            Route::get('sent-emails/{sentEmail}', [SentEmailController::class, 'show'])->name('sent-emails.show');

            Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
            Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
            Route::put('roles/users/{user}/access', [RoleController::class, 'updateUserAccess'])->name('roles.users.access');
            Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
            Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

            Route::get('users', [UserController::class, 'index'])->name('users.index');
            Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
            Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');

            Route::prefix('services')->name('services.')->group(function () {
                Route::prefix('feedbacks')->name('feedbacks.')->group(function () {
                    Route::get('cabinets', [FeedbacksCabinetController::class, 'index'])->name('cabinets.index');
                    Route::get('cabinets/{cabinet}/stats', [FeedbacksCabinetController::class, 'stats'])->name('cabinets.stats');
                    Route::post('cabinets/{cabinet}/recalculate', [FeedbacksCabinetController::class, 'recalculate'])->name('cabinets.recalculate');
                    Route::get('ai-answers', [FeedbacksAiAnswerController::class, 'index'])->name('ai-answers.index');
                });

                Route::prefix('repricer')->name('repricer.')->group(function () {
                    Route::get('cabinets', [RepricerCabinetController::class, 'index'])->name('cabinets.index');
                    Route::get('nmids', [RepricerNmidController::class, 'index'])->name('nmids.index');
                });

                Route::prefix('ai-cabinet')->name('ai-cabinet.')->group(function () {
                    Route::get('cabinets', [AiCabinetCabinetController::class, 'index'])->name('cabinets.index');
                    Route::get('prompts', [AiCabinetPromptController::class, 'index'])->name('prompts.index');
                    Route::post('prompts', [AiCabinetPromptController::class, 'store'])->name('prompts.store');
                    Route::put('prompts/{template}', [AiCabinetPromptController::class, 'update'])->name('prompts.update');
                    Route::delete('prompts/{template}', [AiCabinetPromptController::class, 'destroy'])->name('prompts.destroy');
                });

                Route::prefix('ai')->name('ai.')->group(function () {
                    Route::get('marketplace-logs', [AiMarketplaceLogController::class, 'index'])->name('marketplace-logs.index');
                    Route::get('costs-archive', [AiCostArchiveController::class, 'index'])->name('costs-archive.index');
                    Route::get('media/{path}', [AiMediaController::class, 'show'])->where('path', '.*')->name('media.show');
                });
            });

            Route::prefix('wb')->name('wb.')->group(function () {
                Route::get('api-usage', [WbApiUsageController::class, 'index'])->name('api-usage.index');
                Route::get('api-usage/{sellerId}/logs', [WbApiUsageLogController::class, 'show'])->name('api-usage.logs');
            });
        });
    });