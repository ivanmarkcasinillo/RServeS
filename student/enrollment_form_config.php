<?php

$enrollment_program_catalog = [
    'College of Education' => [
        'Bachelor of Elementary Education (BEEd)' => [],
        'Bachelor of Secondary Education (BSEd)' => [
            'English',
            'Filipino',
            'Mathematics',
            'Social Studies',
        ],
    ],
    'College of Technology' => [
        'Bachelor of Science in Industrial Technology / Bachelor of Industrial Technology' => [
            'Computer Technology',
            'Electronics Technology',
        ],
    ],
    'College of Hospitality and Tourism Management' => [
        'Bachelor of Science in Hospitality Management (BSHM)' => [],
        'Bachelor of Science in Tourism Management (BSTM)' => [],
    ],
];

$enrollment_college_aliases = [
    'COED' => 'College of Education',
    'COT' => 'College of Technology',
    'CHTM' => 'College of Hospitality and Tourism Management',
    'College of Education' => 'College of Education',
    'College of Technology' => 'College of Technology',
    'College of Hospitality and Tourism Management' => 'College of Hospitality and Tourism Management',
];

$enrollment_marital_status_options = [
    'Single',
    'Married',
    'Separated',
    'Widowed',
];

if (!function_exists('normalizeEnrollmentCollege')) {
    function normalizeEnrollmentCollege($value, array $aliases): string
    {
        $candidates = is_array($value) ? $value : explode(',', (string) $value);

        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate === '') {
                continue;
            }

            if (isset($aliases[$candidate])) {
                return $aliases[$candidate];
            }
        }

        return trim((string) (is_array($value) ? ($value[0] ?? '') : $value));
    }
}
