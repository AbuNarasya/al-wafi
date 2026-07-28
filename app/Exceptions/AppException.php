<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Error domain dengan kode status HTTP — padanan `AppError` di backend lama.
 * Di-render otomatis jadi JSON { message } dengan status yang sesuai.
 */
class AppException extends Exception
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage()], $this->status);
    }
}
