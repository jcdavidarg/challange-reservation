<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'date_format:H:i'],
            // El tope alto solo descarta basura; la imposibilidad real de
            // sentar al grupo responde 409 desde el algoritmo, no 422.
            'people_count' => ['required', 'integer', 'min:1', 'max:100'],
        ];
    }
}
