<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Terapkan filter rentang tanggal `?date_from=`/`?date_to=` (format Y-m-d,
     * inklusif) pada satu ekspresi tanggal. `$expression` boleh nama kolom atau
     * SQL seperti COALESCE(...) — dipakai karena tiap tahap pipeline punya kolom
     * tanggal acuannya sendiri.
     */
    protected function applyDateRange($query, Request $request, string $expression)
    {
        return $query
            ->when(
                $request->date_from,
                fn ($q, $from) => $q->whereRaw("$expression >= ?", [Carbon::parse($from)->startOfDay()])
            )
            ->when(
                $request->date_to,
                fn ($q, $to) => $q->whereRaw("$expression <= ?", [Carbon::parse($to)->endOfDay()])
            );
    }

    protected function success(string $message, mixed $data = null, int $status = 200): JsonResponse
    {
        $response = ['status' => true, 'message' => $message];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $status);
    }

    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $response = ['status' => false, 'message' => $message];

        if (! empty($errors)) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $status);
    }
}
