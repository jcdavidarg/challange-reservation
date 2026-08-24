<?php

namespace Database\Seeders;

use App\Models\Table;
use Illuminate\Database\Seeder;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        $layout = [
            'A' => [2, 2, 2, 4, 4, 4, 6, 8],
            'B' => [2, 2, 2, 4, 4, 4, 6, 8],
            'C' => [2, 4, 4, 4, 6, 8],
            'D' => [2, 2, 4, 4, 6, 10],
        ];

        foreach ($layout as $location => $capacities) {
            foreach ($capacities as $i => $capacity) {
                // firstOrCreate: idempotente, seguro ante re-seeds del contenedor
                Table::firstOrCreate(
                    ['location' => $location, 'number' => $i + 1],
                    ['capacity' => $capacity],
                );
            }
        }
    }
}
