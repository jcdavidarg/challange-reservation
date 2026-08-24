<?php

return [

    // Orden en que el sistema intenta asignar ubicaciones ("por orden")
    'locations' => ['A', 'B', 'C', 'D'],

    // Duracion estandar de una reserva
    'duration_minutes' => (int) env('RESERVA_DURATION_MINUTES', 120),

    // Anticipacion minima para reservar
    'min_lead_minutes' => (int) env('RESERVA_MIN_LEAD_MINUTES', 15),

    // Maximo de mesas que pueden unirse en una sola reserva
    'max_tables_per_reservation' => 3,

    // Horarios por dia ISO (1=lunes .. 7=domingo).
    // Apertura/cierre en minutos desde la medianoche del dia de apertura,
    // asi el sabado 22:00-02:00 cierra en 1560 (cruza medianoche sin casos especiales).
    'schedule' => [
        1 => ['open' => 600, 'close' => 1440],   // L-V 10:00 a 24:00
        2 => ['open' => 600, 'close' => 1440],
        3 => ['open' => 600, 'close' => 1440],
        4 => ['open' => 600, 'close' => 1440],
        5 => ['open' => 600, 'close' => 1440],
        6 => ['open' => 1320, 'close' => 1560],  // Sabado 22:00 a 02:00 (+1 dia)
        7 => ['open' => 720, 'close' => 960],    // Domingo 12:00 a 16:00
    ],

];
