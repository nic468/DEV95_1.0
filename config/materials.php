<?php

return [
    'import_max_kilobytes' => (int) env('MATERIALS_IMPORT_MAX_KILOBYTES', 10240),
    'cost_center_names' => [
        '10251' => 'UMS Búzios',
        '10256' => 'UNBC',
        '10259' => 'UNES',
    ],
    'known_units' => ['HH', 'KG', 'PC', 'M', 'M2', 'MT', 'UN'],
];
