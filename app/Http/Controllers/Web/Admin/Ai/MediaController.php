<?php

namespace App\Http\Controllers\Web\Admin\Ai;

use App\Http\Controllers\Api\Admin\services\ai\AdminAiMediaController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly AdminAiMediaController $apiMediaController)
    {
    }

    public function show(string $path): Response|StreamedResponse
    {
        return $this->apiMediaController->show($path);
    }
}