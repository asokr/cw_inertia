<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Api\Subscriber\Ai\AiMediaController as ApiAiMediaController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly ApiAiMediaController $apiMediaController)
    {
    }

    public function show(string $path): Response|StreamedResponse
    {
        return $this->apiMediaController->show($path);
    }
}