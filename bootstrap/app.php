<?php

require_once __DIR__ . '/../vendor/autoload.php';

//use Illuminate\Support\Facades\Session;

// try {
//     (new Dotenv\Dotenv(__DIR__ . '/../'))->load();
// } catch (Dotenv\Exception\InvalidPathException $e) {
//     //
// }

$dorcasENV = ".env"; // $_SESSION['dorcasENV'] ?? '.env'; // session('dorcasENV', '.env');
//$dorcasENV = Session::get('dorcasENV', '.env');

try {
//    (Dotenv\Dotenv::create(__DIR__ . '/../', $dorcasENV))->load();
    //old implementation
//    (Dotenv\Dotenv::create(__DIR__ . '/../'))->load();
    //end of comment
    //new implementation
    (new Laravel\Lumen\Bootstrap\LoadEnvironmentVariables(
        dirname(__DIR__)
    ))->bootstrap();
    //end of new implementation
} catch (Dotenv\Exception\InvalidPathException $e) {
    //
}


//new implementation
date_default_timezone_set(env('APP_TIMEZONE', 'UTC'));
//end of new implementation

/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/
//new implementation

$app = new Laravel\Lumen\Application(
    dirname(__DIR__)
);

//end of new implementation

//old implementation
// $app->withFacades();

// $app->withEloquent();
//end of old implementation

/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/


//new implemetation
$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);
//end of new implementation


/*
|--------------------------------------------------------------------------
| Create The Application
|--------------------------------------------------------------------------
|
| Here we will load the environment and create the application instance
| that serves as the central piece of this framework. We'll use this
| application as an "IoC" container and router for this framework.
|
*/

//$app = new Laravel\Lumen\Application(
//    realpath(__DIR__ . '/../')
//);




$app->withFacades(true, [
    'Illuminate\Support\Facades\Notification' => 'Notification',
    Barryvdh\Snappy\Facades\SnappyPdf::class => 'PDF',
    \Illuminate\Support\Facades\Storage::class => 'Storage'
]);

$app->register(\Illuminate\Redis\RedisServiceProvider::class);

$app->alias('cache', 'Illuminate\Cache\CacheManager');
$app->alias('auth', 'Illuminate\Auth\AuthManager');
$app->alias('mail.manager', \Illuminate\Mail\MailManager::class);
$app->alias('mail.manager', \Illuminate\Contracts\Mail\Factory::class);


// use Illuminate\Support\Facades\Cache;
// use Illuminate\Support\Facades\Storage;
// use Barryvdh\Snappy\Facades\SnappyPdf;
// use Illuminate\Support\Facades\Notification;



$app->withEloquent();

$app->instance('path.config', app()->basePath() . DIRECTORY_SEPARATOR . 'config');




//


/*config(['database.redis'=>[
    'cluster' => env('REDIS_CLUSTER', false),
    'default' => [
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'port' => env('REDIS_PORT', 6379),
        'database' => env('REDIS_DATABASE', 0),
        'password' => env('REDIS_PASSWORD', null),
    ],
]]);*/

/*
|--------------------------------------------------------------------------
| Process Configuration Files
|--------------------------------------------------------------------------
|
| Lumen does not automatically load configuration files, so we have to
| manually load up each of them.
|
*/
$app->configure('app');
$app->configure('auth');
$app->configure('bugsnag');
$app->configure('database');
// $app->configure('cache');
$app->configure('dorcas-api');
$app->configure('filesystems');
$app->configure('invoicing');
$app->configure('mail');
$app->configure('permission');
$app->configure('queue');
$app->configure('scout');
$app->configure('services');
$app->configure('snappy');
$app->configure('scout');


/*
|--------------------------------------------------------------------------
| Register Container Bindings
|--------------------------------------------------------------------------
|
| Now we will register a few bindings in the service container. We will
| register the exception handler and the console kernel. You may add
| your own bindings here if you like or you can make another file.
|
*/

$app->singleton(
    Illuminate\Contracts\Debug\ExceptionHandler::class,
    App\Exceptions\Handler::class
);

$app->singleton(
    Illuminate\Contracts\Console\Kernel::class,
    App\Console\Kernel::class
);

$app->singleton(
    Illuminate\Contracts\Filesystem\Factory::class,
    function ($app) {
        return new Illuminate\Filesystem\FilesystemManager($app);
    }
);


/*
|--------------------------------------------------------------------------
| Register Middleware
|--------------------------------------------------------------------------
|
| Next, we will register the middleware with the application. These can
| be global middleware that run before and after each request into a
| route or middleware that'll be assigned to some specific routes.
|
*/

$app->middleware([
    \App\Http\Middleware\ResolveApplicationId::class,
    \App\Http\Middleware\InstalledAppUserDataAccessGate::class
]);

$app->routeMiddleware([
    'auth' => App\Http\Middleware\Authenticate::class,
    'administrator' => \App\Http\Middleware\Administrator::class,
    'permission' => Spatie\Permission\Middlewares\PermissionMiddleware::class,
    'role' => Spatie\Permission\Middlewares\RoleMiddleware::class,
]);

/*
|--------------------------------------------------------------------------
| Register Service Providers
|--------------------------------------------------------------------------
|
| Here we will register all of the application's service providers which
| are used to bind services into the container. Service providers are
| totally optional, so you are not required to uncomment this line.
|
*/

$app->register(App\Providers\AppServiceProvider::class);
$app->register(App\Providers\AuthServiceProvider::class);
$app->register(App\Providers\EventServiceProvider::class);
$app->register(\Illuminate\Filesystem\FilesystemServiceProvider::class);
$app->register(Laravel\Passport\PassportServiceProvider::class);
$app->register(Dusterio\LumenPassport\PassportServiceProvider::class);
$app->register(\Illuminate\Mail\MailServiceProvider::class);
$app->register(\Illuminate\Notifications\NotificationServiceProvider::class);
$app->register(\Barryvdh\Snappy\LumenServiceProvider::class);
$app->register(\Fedeisas\LaravelMailCssInliner\LaravelMailCssInlinerServiceProvider::class);
$app->register(\Spatie\Permission\PermissionServiceProvider::class);
//$app->register(\Illuminate\Redis\RedisServiceProvider::class);
$app->register(Laravel\Scout\ScoutServiceProvider::class);

$app->register(\Illuminate\Queue\QueueServiceProvider::class);

//$app->register(\Bugsnag\BugsnagLaravel\BugsnagServiceProvider::class);
//$app->register(Bugsnag\BugsnagLaravel\BugsnagServiceProvider::class);



//$app->alias('mailer', \Illuminate\Contracts\Mail\Mailer::class);




/*
|--------------------------------------------------------------------------
| Load The Application Routes
|--------------------------------------------------------------------------
|
| Next we will include the routes file so that they can all be added to
| the application. This will provide all of the URLs the application
| can respond to, as well as the controllers that may handle them.
|
*/

$app->router->group([
    'namespace' => 'App\Http\Controllers',
], function ($router) {
    require __DIR__ . '/../routes/web.php';
});
return $app;
