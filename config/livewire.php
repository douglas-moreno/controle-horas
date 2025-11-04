<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => null,         // Remove restrições padrão
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'preview_mimes' => [
            'txt'
        ],
        'max_upload_time' => 60  // Aumenta tempo máximo
    ],
    'upload_max_filesize' => '50M'  // Aumenta limite
];
