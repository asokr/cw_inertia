<?php

namespace App\Http\Controllers\Web\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $isSuperAdmin = $user?->hasRole(['Супер-Админ', 'super-admin']) || $user?->can('super admin');
        $canViewBlog = (bool) $user?->can('blog.view');

        if (! $isSuperAdmin && ! $canViewBlog) {
            abort(404);
        }

        return Inertia::render('Admin/Dashboard/Index', [
            'isSuperAdmin' => $isSuperAdmin,
            'canViewBlog' => $canViewBlog,
        ]);
    }
}