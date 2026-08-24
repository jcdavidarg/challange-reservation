<?php

namespace App\Http\Controllers;

use App\Http\Requests\ListReservationsRequest;
use App\Http\Requests\StoreReservationRequest;
use App\Services\ReservationQueryService;
use App\Services\ReservationService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

class ReservationController extends Controller
{
    public function store(StoreReservationRequest $request, ReservationService $service): JsonResponse
    {
        try {
            $startsAt = CarbonImmutable::parse("{$request->input('date')} {$request->input('time')}:00");
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'date' => __('Fecha u hora invalida.'),
            ]);
        }

        $reservation = $service->create(
            $request->user(),
            $startsAt,
            (int) $request->integer('people_count'),
        );

        return response()->json([
            'message' => __('Reserva creada.'),
            'data' => $reservation,
        ], 201);
    }

    public function index(ListReservationsRequest $request, ReservationQueryService $queryService): JsonResponse
    {
        return response()->json([
            'data' => $queryService->listByDate($request->input('date')),
        ]);
    }
}
