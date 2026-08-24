<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class ReservationException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = Response::HTTP_UNPROCESSABLE_ENTITY,
    ) {
        parent::__construct($message);
    }

    public static function outsideOpeningHours(): self
    {
        return new self('El horario solicitado esta fuera del horario de atencion.');
    }

    public static function insufficientLeadTime(int $minutes): self
    {
        return new self("Las reservas deben realizarse con al menos {$minutes} minutos de anticipacion.");
    }

    public static function noAvailability(): self
    {
        return new self('No hay disponibilidad para la cantidad de personas solicitada.', Response::HTTP_CONFLICT);
    }
}
