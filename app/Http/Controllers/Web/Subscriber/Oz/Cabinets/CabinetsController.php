<?php

namespace App\Http\Controllers\Web\Subscriber\Oz\Cabinets;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Oz\OzCabinet;
use App\Services\Subscriber\Oz\OzCabinetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CabinetsController extends SubscriberToolController
{
    public function __construct(
        private readonly OzCabinetService $cabinets,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'apikey' => ['required', 'string'],
            'performance_client_id' => ['nullable', 'string', 'max:255'],
            'performance_client_secret' => ['nullable', 'string'],
        ], [
            'name.required' => 'Укажите название кабинета',
            'client_id.required' => 'Укажите Client ID',
            'apikey.required' => 'Укажите API-ключ',
        ]);

        try {
            $this->cabinets->create($request->user(), $data);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->back()
            ->with('success', 'Кабинет Ozon добавлен');
    }

    public function update(Request $request, OzCabinet $cabinet): RedirectResponse
    {
        if ((int) $cabinet->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'client_id' => ['required', 'string', 'max:255'],
            'apikey' => ['nullable', 'string'],
            'performance_client_id' => ['nullable', 'string', 'max:255'],
            'performance_client_secret' => ['nullable', 'string'],
        ], [
            'name.required' => 'Укажите название кабинета',
            'client_id.required' => 'Укажите Client ID',
        ]);

        try {
            $this->cabinets->update($request->user(), $cabinet, $data);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        return redirect()
            ->back()
            ->with('success', 'Кабинет обновлён');
    }

    public function destroy(Request $request, OzCabinet $cabinet): RedirectResponse
    {
        if ((int) $cabinet->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $this->cabinets->delete($request->user(), $cabinet);

        return redirect()
            ->back()
            ->with('success', 'Кабинет удалён');
    }

    public function select(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'cabinet_id' => ['required', 'integer'],
        ]);

        try {
            $this->cabinets->select($request->user(), (int) $data['cabinet_id']);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()
            ->route('subscriber.panel')
            ->with('success', 'Активный кабинет Ozon изменён');
    }
}
