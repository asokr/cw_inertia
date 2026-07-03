<?php

namespace App\Http\Controllers\Web\Admin\AiCabinet;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminAiCabinetService;
use Inertia\Inertia;
use Inertia\Response;

class CabinetController extends Controller
{
    public function __construct(private readonly AdminAiCabinetService $aiCabinetService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Services/AiCabinet/Cabinets/Index', [
            'cabinets' => $this->aiCabinetService->listCabinets(),
        ]);
    }
}