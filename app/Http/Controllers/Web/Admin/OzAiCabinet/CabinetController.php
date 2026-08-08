<?php

namespace App\Http\Controllers\Web\Admin\OzAiCabinet;

use App\Http\Controllers\Controller;
use App\Services\Admin\AdminOzAiCabinetService;
use Inertia\Inertia;
use Inertia\Response;

class CabinetController extends Controller
{
    public function __construct(private readonly AdminOzAiCabinetService $aiCabinetService)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Admin/Services/OzAiCabinet/Cabinets/Index', [
            'cabinets' => $this->aiCabinetService->listCabinets(),
        ]);
    }
}
