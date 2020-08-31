<?php

/**
 * Purpose:
 *  Console commands to finish env detector setup
 * History:
 *  082920 - Wilkes: Created file
 * @author Casper Wilkes <casper@casperwilkes.net>
 * @package CasperWilkes\EnvDetector
 * @copyright 2019 - casper wilkes
 * @license MIT
 */

namespace EnvDetector\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\{File, Log};

/**
 * Class Publish
 * @package EnvDetector\Commands
 */
class Publish extends Command {

    /**
     * Describes the vendor publish command
     * @var string
     */
    private $publish = '!!Must have run `php artisan vendor:publish --tag=env-detector`!!';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'envdetector:publish '
                           . '{--a|all : Writes all [Default]}'
                           . '{--b|bootstrap : Create bootstrap files}'
                           . '{--c|configs : (Over)Writes the .env config files}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generates the environment detector bootstrap and config files.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        $this->info('Started environment detector setup');

        // Get our options from top of options array //
        $opts = array_slice($this->options(), 0, 4);

        // Check if all are false, or all are true //
        $all = !in_array(true, $opts, true) || $this->option('all');

        // Set remaining options //
        $config = $all ? : $this->option('configs');
        $bootstrap = $all ? : $this->option('bootstrap');

        if ($config) {
            $this->comment('Publishing configs');
            $this->configs();
            $this->comment('Finished publishing configs');
        }

        if ($bootstrap) {
            $this->comment('Bootstrapping App.php');
            $this->bootstrap();
            $this->comment('Finished bootstrapping App.php');
        }

        $this->info('Finished environment detector setup');
    }

    /**
     * Backs up, and writes to app.php. This is how the environment detector "registers"
     * @return void
     */
    private function bootstrap(): void {
        // Check bootstrap file has been published //
        if (!File::exists(base_path('bootstrap/environment_detector.php'))) {
            $this->warn('cannot perform bootstrap of App.php');
            $this->warn($this->publish);

            return;
        }

        // Full path to app.php //
        $file_path = base_path('bootstrap/app.php');

        // Check for backed up app files //
        $backups = File::glob($file_path . '.*');

        if (!empty($backups)) {
            $this->warn('App.php is already backed up.');
            $this->comment('App.php unchanged');

            return;
        }

        // Make sure we can operate on the app file //
        try {
            if (!File::exists($file_path)) {
                $this->warn("{$file_path} does not exist");
            } elseif (!File::isReadable($file_path)) {
                $this->warn("{$file_path} cannot be read");
            } elseif (!File::isWritable($file_path)) {
                $this->warn("{$file_path} is not writable");
            } else {
                $this->bootstrapApp($file_path);
            }
        } catch (Exception $e) {
            $this->alert($e->getMessage());
            Log::critical(__METHOD__, ['Exception' => $e]);
            $this->error('An exception has occurred while creating file. Please check logs for more information');
        }
    }

    /**
     * Backup and append App bootstrap file
     * @param string $file_path
     * @return void
     */
    private function bootstrapApp(string $file_path): void {
        // Read in app as an array //
        $file = file($file_path);
        // Key of last return index //
        $needle = false;

        // Account for zero index //
        $count = count($file) - 1;

        // Get last 5 lines //
        for ($i = $count; $i >= ($count - 5); --$i) {
            // Check for return statement //
            if (false !== stripos($file[$i], 'return')) {
                // Get key of last return //
                $needle = $i;
            }

            // Break if key is found //
            if (is_int($needle)) {
                break;
            }
        }

        if (!is_int($needle)) {
            $this->warn('Could not find return statement in app.php');
        } else {
            // Get contents of stub as array //
            $require = file(dirname(__DIR__) . '/Stubs/require.stub');

            // Add contents to file //
            array_splice($file, $needle, 0, $require);

            // Backup app.php path //
            $backup = $file_path . '._bu_' . date('Ymd');

            // backup original file //
            if (!File::copy($file_path, $backup)) {
                $this->error('Could not backup app.php');
            } else {
                $this->comment('App.php Backed up: ' . basename($backup));

                // Attempt to write to app.php //
                if (!File::put($file_path, $file)) {
                    // Put contents of file array //
                    $this->error('Could not bootstrap app.php');
                } else {
                    $this->comment('App.php bootstrapped');
                }
            }
        }
    }

    /**
     * Creates config files
     * @return void
     */
    private function configs(): void {
        // Config necessary to setup //
        $files = collect(config('environment_detector.environments'));

        // Config file must exist //
        if ($files->isEmpty()) {
            $this->warn('Cannot create configs for environment');
            $this->warn($this->publish);

            return;
        }

        // Check default template exists //
        if (!File::exists(base_path('.env.example'))) {
            $this->warn('Cannot continue without .env.example present');

            return;
        }

        // Loop over each config //
        $files->each(function ($host, $short) {
            $file_name = ".env.{$short}";

            if (!File::exists(base_path($file_name))) {
                // Generate a brand new config //
                $this->writeConfig($this->generateRandomKey(), $file_name);
            } else {
                $this->info("`{$file_name}` detected");

                // Ask if they want to overwrite or keep //
                if ($this->confirm("Would you like to overwrite `{$file_name}`")) {
                    // Get config key from original //
                    $key = $this->generateConfigKey(File::get(base_path($file_name)));
                    // Overwrite original //
                    $this->writeConfig($key, $file_name);
                }
            }
        });
    }

    /**
     * Generate a random key for the application.
     * @return string
     */
    private function generateRandomKey(): string {
        return 'base64:' . base64_encode(
                Encrypter::generateKey($this->laravel['config']['app.cipher'])
            );
    }

    /**
     * Gets the app key from the current config
     * @param $contents
     * @return string
     */
    private function generateConfigKey($contents): string {
        $key_pattern = '/^APP_KEY=(?P<key>.*)/m';
        preg_match($key_pattern, $contents, $match);

        return $match['key'];
    }

    /**
     * Get a regex pattern that will match env APP_KEY with any random key.
     * @return string
     */
    private function keyReplacementPattern(): string {
        return "/^APP_KEY=(?P<key>.*)/m";
    }

    /**
     * Takes a key and the file content. Replaces the key in the file contents
     * @param string $key
     * @param string $file_data
     * @return string
     */
    private function replaceKey(string $key, string $file_data): string {
        return preg_replace(
            $this->keyReplacementPattern(),
            'APP_KEY=' . $key,
            $file_data
        );
    }

    /**
     * Writes out the config file
     * @param string $key Key to insert into template
     * @param string $destination Destination file name
     */
    private function writeConfig(string $key, string $destination): void {
        $file_name = basename($destination);

        // Get contents of .env.example //
        $template = File::get(base_path('.env.example'));
        $contents = $this->replaceKey($key, $template);

        // Save the config //
        if (File::put($destination, $contents)) {
            $this->comment("Created: {$file_name}");
        } else {
            $this->warn("Could not create: {$file_name}");
        }
    }

}
