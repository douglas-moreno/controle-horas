<?php

return [
    'temporary_file_upload' => [
        'disk' => env('LIVEWIRE_UPLOAD_DISK', 'local'),
        'rules' => null,         // Remove restrições padrão
        'directory' => env('LIVEWIRE_UPLOAD_DIRECTORY', 'livewire-tmp'),
        'middleware' => null,
        'preview_mimes' => [
            'txt'
        ],
        'max_upload_time' => env('LIVEWIRE_MAX_UPLOAD_TIME', 60),  // Aumenta tempo máximo
    ],

    // Use o valor do .env (p.ex. LIVEWIRE_UPLOAD_MAX_FILESIZE=100) e deixe como string "100M" por compatibilidade visual
    'upload_max_filesize' => env('LIVEWIRE_UPLOAD_MAX_FILESIZE', '50M'),  // Aumenta limite
];
