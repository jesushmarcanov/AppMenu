<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Exceptions\ApiException;
use App\Exceptions\ValidationException;
use App\Models\Reservation;
use App\Services\ReservationService;

final class ReservationController
{
    public function store(Request $request, array $params = []): Response
    {
        if (trim((string) $request->input('website', '')) !== '') {
            throw new ApiException('SPAM_DETECTED', 'No se pudo procesar la solicitud.', 422);
        }

        $data = Validator::validate($request->body(), [
            'name' => 'required|string|minlen:2|maxlen:120',
            'phone' => 'required|phone|maxlen:30',
            'email' => 'nullable|string|email|maxlen:190',
            'reservation_date' => 'required|date',
            'reservation_time' => 'required|time',
            'party_size' => 'required|integer|min:1|max:100',
            'notes' => 'nullable|string|maxlen:1000',
            'locale' => 'nullable|in:es,en',
        ]);

        $locale = Lang::resolve((string) ($data['locale'] ?? $request->queryParam('lang', 'es')));
        $result = (new ReservationService())->create($data, $locale);

        return Response::success($result, [], 201);
    }

    public function availability(Request $request, array $params = []): Response
    {
        $date = trim((string) $request->queryParam('date', ''));
        $locale = Lang::resolve((string) $request->queryParam('lang', 'es'));

        return Response::success((new ReservationService())->availability($date, $locale));
    }

    public function index(Request $request, array $params = []): Response
    {
        $filters = [
            'page' => $request->queryParam('page', 1),
            'per_page' => $request->queryParam('per_page', 20),
            'status' => $request->queryParam('status'),
            'date_from' => $request->queryParam('date_from'),
            'date_to' => $request->queryParam('date_to'),
            'q' => $request->queryParam('q'),
        ];

        $result = Reservation::paginate($filters);

        return Response::success($result['items'], $result['meta']);
    }

    public function show(Request $request, array $params = []): Response
    {
        $reservation = Reservation::findReservation((int) ($params['id'] ?? 0));
        if (!$reservation) {
            throw new ApiException('NOT_FOUND', 'La reservación no existe.', 404);
        }

        return Response::success($reservation);
    }

    public function update(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        if (Reservation::findReservation($id) === null) {
            throw new ApiException('NOT_FOUND', 'La reservación no existe.', 404);
        }

        $body = $request->body();
        $data = Validator::validate($body, [
            'status' => 'nullable|in:pending,confirmed,cancelled,completed',
            'admin_notes' => 'nullable|string|maxlen:2000',
        ]);

        if ($data === []) {
            throw new ValidationException(['status' => 'No hay cambios para guardar.']);
        }

        Reservation::updateReservation($id, $data);
        audit('reservation.updated', 'reservations', $id, ['status' => $data['status'] ?? null]);

        return Response::success(Reservation::findReservation($id));
    }

    public function destroy(Request $request, array $params = []): Response
    {
        $id = (int) ($params['id'] ?? 0);
        Reservation::deleteReservation($id);
        audit('reservation.deleted', 'reservations', $id);

        return Response::noContent();
    }
}
