<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\HttpFoundation\File\Exception\FileException;
use Symfony\Component\HttpFoundation\File\Exception\FileNotFoundException;
use App\Http\Controllers\Setup\Init as AuthInit;
use App\Http\Controllers\Auth\Register as AuthRegister;
//use Ramsey\Uuid\Uuid;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;

class DorcasSetup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'dorcas:setup {--database=} {--repeat} {--reset} {--partner=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Setup Dorcas Installation';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle($options = "")
    {

        $this->info('Starting Dorcas (CORE) Setup...');

        // $value = $this->argument('name');
        // // and
        // $value = $this->option('name');
        // // or get all as array
        // $arguments = $this->argument();
        // $options = $this->option();

        // default setup
        $database_old = $this->option('database');
        $database = getenv('DB_DATABASE');
        $databaseHub = getenv('DB_HUB_DATABASE');

        $partnerName = $this->option('partner') ?? env('DORCAS_PARTNER_NAME', 'Sample Community');

        $repeatSetup = $this->option('repeat') ?? false;

        $resetDB = $this->option('reset') ?? false;

        if (!$databaseHub) {
            $this->info('Skipping creation of database as env(DB_HUB_DATABASE) is empty');
            return;
        }

        if (!$database) {
            $this->info('Skipping creation of database as env(DB_DATABASE) is empty');
            return;
        }

        if ($resetDB) {
            $this->info('Deleting Databases...');

            try {
                $conn = mysqli_connect(env('DB_HUB_HOST'), env('DB_HUB_USERNAME'), env('DB_HUB_PASSWORD'));

                if (!$conn) {
                    die("Connection to HUB failed: " . mysqli_connect_error());
                }

                $sql = "DROP DATABASE `" . $databaseHub . "`";
                if (mysqli_query($conn, $sql)) {
                    $this->info(sprintf('Successfully DELETED %s database', $databaseHub));
                } else {
                    $this->error(sprintf('Error deleting %s database, %s', $databaseHub, mysqli_error($conn)));
                }
                
                mysqli_close($conn);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to delete %s database, %s', $database, $exception->getMessage()));
            }

            try {
                $conn = mysqli_connect(env('DB_HOST'), env('DB_USERNAME'), env('DB_PASSWORD'));

                if (!$conn) {
                    die("Connection to CORE failed: " . mysqli_connect_error());
                }

                $sql = "DROP DATABASE `" . $database . "`";
                if (mysqli_query($conn, $sql)) {
                    $this->info(sprintf('Successfully DELETED %s database', $database));
                } else {
                    $this->error(sprintf('Error deleting %s database, %s', $database, mysqli_error($conn)));
                }
                
                mysqli_close($conn);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to delete %s database, %s', $database, $exception->getMessage()));
            }

        }

        $firstTimeCore = $this->checkDB("CORE", "mysql", $database);
        $firstTimeHub = $this->checkDB("HUB", "hub_mysql", $databaseHub);


        if ($firstTimeHub || $repeatSetup) {

            $this->info('Checking / Creating HUB Database via ' . env('DB_HUB_HOST'));

            try {
                $conn = mysqli_connect(env('DB_HUB_HOST'), env('DB_HUB_USERNAME'), env('DB_HUB_PASSWORD'));

                if (!$conn) {
                    die("Connection to HUB failed: " . mysqli_connect_error());
                }
                
                // Create database
                $sql = "CREATE DATABASE IF NOT EXISTS `" . $databaseHub . "`";
                if (mysqli_query($conn, $sql)) {
                    $this->info(sprintf('Successfully created %s database', $databaseHub));
                } else {
                    $this->error(sprintf('Error creating %s database, %s', $databaseHub, mysqli_error($conn)));
                }
                
                mysqli_close($conn);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to create %s database, %s', $database, $exception->getMessage()));
            }


            $this->info('Importing HUB database...');
            try {

                $filename = resource_path('hub.sql');
                # get the filename
                if (!file_exists($filename)) {
                    throw new FileNotFoundException('Could not find the hub.sql database file at: '.$filename);
                }
                if (!is_readable($filename)) {
                    throw new FileException('The hub.sql ('.$filename.') file is not readable by the process.');
                }


                $connImport = mysqli_connect(getenv('DB_HUB_HOST'), getenv('DB_HUB_USERNAME'), getenv('DB_HUB_PASSWORD'));

                if (!$connImport) {
                    die("Connection failed: " . mysqli_connect_error());
                }

                $sql = "USE `" . $databaseHub . "`";
                if (mysqli_query($connImport, $sql)) {
                    $this->info(sprintf('Successfully selected %s database', $databaseHub));
                } else {
                    $this->error(sprintf('Error selecting %s database, %s', $databaseHub, mysqli_error($connImport)));
                }

                $queryLines = 0;
                $tempLine = '';
                // Read in the full file
                $lines = file($filename);
                // Loop through each line
                foreach ($lines as $line) {

                    // Skip it if it's a comment
                    if (substr($line, 0, 2) == '--' || $line == '')
                        continue;

                    // Add this line to the current segment
                    $tempLine .= $line;
                    // If its semicolon at the end, so that is the end of one query
                    if (substr(trim($line), -1, 1) == ';')  {
                        // Perform the query
                        mysqli_query($connImport, $tempLine) or $this->error(sprintf("Error in " . $tempLine .": %s", mysqli_error($connImport)));
                        
                        // Reset temp variable to empty
                        $tempLine = '';
                        $queryLines++;
                    }
                }

                $this->info(sprintf('%s SQL lines imported successfully to HUB', $queryLines));

                mysqli_close($connImport);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to import HUB database: %s', $exception->getMessage()));
            }

        }

        if ($firstTimeCore || $repeatSetup) {
        
            $this->info('Checking / Creating CORE Database');

            try {
                $conn = mysqli_connect(getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));

                if (!$conn) {
                    die("Connection failed: " . mysqli_connect_error());
                }
                
                // Create database
                $sql = "CREATE DATABASE IF NOT EXISTS `" . $database . "`";
                if (mysqli_query($conn, $sql)) {
                    $this->info(sprintf('Successfully created %s database', $database));
                } else {
                    $this->error(sprintf('Error creating %s database, %s', $database, mysqli_error($conn)));
                }
                
                mysqli_close($conn);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to create %s database, %s', $database, $exception->getMessage()));
            }

            $this->info('Importing CORE database...');
            try {

                $filename = resource_path('core.sql');
                # get the filename
                if (!file_exists($filename)) {
                    throw new FileNotFoundException('Could not find the core.sql database file at: '.$filename);
                }
                if (!is_readable($filename)) {
                    throw new FileException('The core.sql ('.$filename.') file is not readable by the process.');
                }


                $connImport = mysqli_connect(getenv('DB_HOST'), getenv('DB_USERNAME'), getenv('DB_PASSWORD'));

                if (!$connImport) {
                    die("Connection failed: " . mysqli_connect_error());
                }

                $sql = "USE `" . $database . "`";
                if (mysqli_query($connImport, $sql)) {
                    $this->info(sprintf('Successfully selected %s database', $database));
                } else {
                    $this->error(sprintf('Error selecting %s database, %s', $database, mysqli_error($connImport)));
                }

                $queryLines = 0;
                $tempLine = '';
                // Read in the full file
                $lines = file($filename);
                // Loop through each line
                foreach ($lines as $line) {

                    // Skip it if it's a comment
                    if (substr($line, 0, 2) == '--' || $line == '')
                        continue;

                    // Add this line to the current segment
                    $tempLine .= $line;
                    // If its semicolon at the end, so that is the end of one query
                    if (substr(trim($line), -1, 1) == ';')  {
                        // Perform the query
                        mysqli_query($connImport, $tempLine) or $this->error(sprintf("Error in " . $tempLine .": %s", mysqli_error($connImport)));
                        
                        // Reset temp variable to empty
                        $tempLine = '';
                        $queryLines++;
                    }
                }

                $this->info(sprintf('%s SQL lines imported successfully', $queryLines));

                mysqli_close($connImport);

            } catch (Exception $exception) {
                $this->error(sprintf('Failed to import database: %s', $exception->getMessage()));
            }

            $this->info('Setting up OAuth and Administrative Account...');
            try {

                $init = new AuthInit();
                $setup = json_decode($init->setup()); // this one doesnt seem to return what we want o

                //create for mobile - use default

                //manually get client ids & secret in first password grant client record
                $client = DB::table("oauth_clients")->where('password_client', 1)->first();
                $client_id = $client->id;
                $client_secret = $client->secret;

                $this->info(' ID: ' . $client_id . ", Secret: " . $client_secret);

                $password = 'password';
//                    \Illuminate\Support\Str::random(10);

                $data = [
                    "firstname" => "Admin",
                    "lastname" => "User",
                    "email" => getenv('ADMINISTRATOR_EMAIL'),
                    "installer" => "true",
                    "domain" => getenv('DORCAS_BASE_DOMAIN'),
                    "password" => $password,
                    "company" => "Demo",
                    "phone" => "08012345678",
                    "feature_select" => "all",
                    "client_id" => $client_id,
                    "client_secret" => $client_secret,
                ];

                // Create Partner Account if (Community/Enterprise/Cloud Editions)
                if ( env("DORCAS_EDITION", "business") == "community" || env("DORCAS_EDITION", "business") == "enterprise" ) {
                    
                    $this->info('Creating Partner Account for ' . $partnerName);
                    // create partner account

                    $partnerUUID = (string) \Illuminate\Support\Str::uuid(); //(string) Str::orderedUuid(); //Uuid::uuid1()->toString();
                    // $partnerSlug = str_slug($partnerName);
                    $partnerNameArray = explode(" ", $partnerName);
                    $partnerSlug = strtolower($partnerNameArray[0]);

                    $partnerSlug = env('DORCAS_PARTNER_SLUG', $partnerSlug);

                    $partnerLogo = env('DORCAS_PARTNER_LOGO', 'https://dorcas-s3.s3.eu-west-1.amazonaws.com/images/logo_main.png');

                    $db = DB::connection('mysql');
                    DB::transaction(function () use($db, $partnerUUID, $partnerName, $partnerSlug, $partnerLogo) {
                         
                         $partner_id = $db->table("partners")->insertGetId([
                          'uuid' => $partnerUUID,
                          'name' => $partnerName,
                          'slug' => $partnerSlug,
                          'logo_url' => $partnerLogo,
                          //'extra_data' => [],
                          'is_verified' => 1
                        ]);
                    });

                    //add partner details to setup
                    //$data["partner"] = $partnerUUID;
                    $data["is_partner"] = 1;

                }


                if (!empty(getenv('ADMINISTRATOR_EMAIL'))) {
                    $data["trigger_event"] = 1;
                    $this->info('Triggering Email Sending...');
                }


                $register = new AuthRegister();
                $request = new \Illuminate\Http\Request($data);
                $fractal = new \League\Fractal\Manager;
                $user = $register->register($request, $fractal);

                $this->info('Username: ' . $data["email"] . " & password: " . $password);



            } catch (Exception $exception) {
                $this->error(sprintf('Failed setting up OAuth: %s', $exception->getMessage()));
            }

            $this->info('Creating Lumen App Key...');
            $key = \Illuminate\Support\Str::random(32);
            $path = base_path('.env');
            if (file_exists($path)) {
                file_put_contents($path, str_replace(
                    'APP_KEY=', 'APP_KEY='.$key, file_get_contents($path)
                ));
                $this->info('Successfully created APP KEY');
            }

        }

    }


    public function checkDB($dbTag, $dbConnection, $dbName)
    {
        try {
            DB::connection($dbConnection)->getPdo();
            $this->info("$dbTag Database Exists! Exiting first time setup...");
            return false;
        } catch (\Exception $e) {
            $this->info("$dbTag Database Not Found! Proceeding with first time setup...");
            return true;
        }
    }


}
