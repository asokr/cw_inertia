<?php

namespace App\Http\Controllers\Web\Subscriber\Wb\Cabinets;

use App\Http\Controllers\Web\Subscriber\SubscriberToolController;
use App\Models\Subscribers\Wb\WbCabinet;
use App\Services\Subscriber\Wb\WbCabinetService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CabinetsController extends SubscriberToolController
{
    public function __construct(
        private readonly WbCabinetService $cabinets,
    ) {
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'apikey' => ['required', 'string'],
        ], [
            'name.required' => 'Укажите название кабинета',
            'apikey.required' => 'Укажите API-ключ',
        ]);

        try {
            $result = $this->cabinets->create($request->user(), $data);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        $redirect = redirect()
            ->back()
            ->with('success', 'Кабинет Wildberries добавлен');

        if ($result['permission_warnings'] !== []) {
            $redirect->with('success_details', implode(' ', $result['permission_warnings']));
        }

        return $redirect;
    }

    public function update(Request $request, WbCabinet $cabinet): RedirectResponse
    {
        if ((int) $cabinet->user_id !== (int) $request->user()->id) {
            abort(404);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'apikey' => ['nullable', 'string'],
        ], [
            'name.required' => 'Укажите название кабинета',
        ]);

        try {
            $result = $this->cabinets->update($request->user(), $cabinet, $data);
        } catch (ValidationException $e) {
            return back()->withInput()->withErrors($e->errors());
        }

        $redirect = redirect()
            ->back()
            ->with('success', 'Кабинет обновлён');

        if ($result['permission_warnings'] !== []) {
            $redirect->with('success_details', implode(' ', $result['permission_warnings']));
        }

        return $redirect;
    }

    public function destroy(Request $request, WbCabinet $cabinet): RedirectResponse
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
            ->with('success', 'Активный кабинет изменён');
    }
}
