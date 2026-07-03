<?php

use App\Http\Controllers\Web\Blog\BlogPostController;
use App\Http\Controllers\Web\Blog\MediaController;
use App\Http\Controllers\Web\Blog\SitemapController;
use Illuminate\Support\Facades\Route;

Route::get('/blog', [BlogPostController::class, 'index'])->name('blog.index');
Route::get('/blog/sitemap.xml', [SitemapController::class, 'index'])->name('blog.sitemap');
Route::get('/blog/{slug}', [BlogPostController::class, 'show'])->name('blog.show');
Route::post('/blog/{slug}/view', [BlogPostController::class, 'incrementView'])
    ->middleware('throttle:5,1')
    ->name('blog.view');

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->name('blog.media');