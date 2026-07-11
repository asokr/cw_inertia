<?php

namespace App\Http\Controllers\Web\Subscriber\Ai;

use App\Http\Controllers\Api\Subscriber\Ai\AiMediaController as ApiAiMediaController;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaController extends Controller
{
    public function __construct(private readonly ApiAiMediaController $apiMediaController)
    {
    }

    public function show(Request $request, string $path): BinaryFileResponse|StreamedResponse
    {
        return $this->apiMediaController->show($request, $path);
    }
}