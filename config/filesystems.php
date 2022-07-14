<?php
return [
    /*
    |--------------------------------------------------------------------------
    | Default Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Here you may specify the default filesystem disk that should be used
    | by the framework. The "local" disk, as well as a variety of cloud
    | based disks are available to your application. Just store away!
    |
    */
    'default' => env('FILESYSTEM_DRIVER', 'public'),
    /*
    |--------------------------------------------------------------------------
    | Default Cloud Filesystem Disk
    |--------------------------------------------------------------------------
    |
    | Many applications store files both locally and in the cloud. For this
    | reason, you may specify a default "cloud" driver here. This driver
    | will be bound as the Cloud disk implementation in the container.
    |
    */
    'cloud' => env('FILESYSTEM_CLOUD', 's3'),
    /*
    |--------------------------------------------------------------------------
    | Filesystem Disks
    |--------------------------------------------------------------------------
    |
    | Here you may configure as many filesystem "disks" as you wish, and you
    | may even configure multiple disks of the same driver. Defaults have
    | been setup for each driver as an example of the required options.
    |
    | Supported Drivers: "local", "ftp", "sftp", "s3", "rackspace"
    |
    */
    'disks' => [
        'local' => [
            'driver' => 'local',
            'root' => storage_path('app'),
        ],
        'public' => [
            'driver' => 'local',
            'root' => base_path('public/uploads'),
            'url' => env('APP_URL').'/uploads',
            'visibility' => 'public',
        ],
        // 's3' => [
        //     'driver' => 's3',
        //     'key' => env('AWS_KEY'),
        //     'secret' => env('AWS_SECRET'),
        //     'region' => env('AWS_S3_REGION'),
        //     'bucket' => env('AWS_BUCKET'),
        //     //'url' => 'https://s3.' . env('AWS_S3_REGION') . '.amazonaws.com/' . env('AWS_BUCKET'),
        //     'url' => env('APP_URL_STATIC'),
        //     'visibility' => 'public-read',
        //     //'url' => 'https://s3.' . env('AWS_S3_REGION') . '.amazonaws.com/' . env('AWS_BUCKET'),
        //     'url' => env('APP_URL_STATIC'),
        //     'visibility' => 'public-read',
        // ],
        's3' => [
            'driver' => 's3',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'region' => env('AWS_DEFAULT_REGION'),
            'bucket' => env('AWS_BUCKET'),
            'url' => env('AWS_URL'),
            'endpoint' => env('AWS_ENDPOINT'),
            'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),

        ],
        'azure' => [
            'driver'    => 'azure',
            'name'      => env('AZURE_STORAGE_ACCOUNT'),
            'key'       => env('AZURE_STORAGE_KEY'),
            'container' => env('AZURE_STORAGE_CONTAINER'),
            'url'       => env('AZURE_STORAGE_URL'),
            'prefix'    => null,
        ],
    ],
];
