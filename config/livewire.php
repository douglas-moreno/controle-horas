<?php

return [
    'temporary_file_upload' => [
        'disk' => 'local',
        'rules' => 'file|mimes:txt|max:10240', // 10MB max
        'directory' => 'livewire-tmp',
        'middleware' => null,
        'preview_mimes' => [
            'txt'
        ],
        'max_upload_time' => 5
    ],
    'upload_max_filesize' => '10M'
];
