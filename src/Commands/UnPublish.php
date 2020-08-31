<?php

/**
 * Purpose:
 *  Console commands to remove env detector setup
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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Class UnPublish
 * @package EnvDetector\Commands
 */
class UnPublish extends Command {

    /**
     * Reference name of files
     * @var string
     */
    private $ref_name = 'environment_detector.php';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'envdetector:unpublish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Removes the published environment detector settings and restores App.php to original settings.'
                             . ' Leaves previously created .env files in place.';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle() {
        $this->info('Started Removing environment detector setup');

        $this->comment('Removing environment_detector file');

        $this->removeEnv();

        $this->comment('Finished environment_detector file');

        $this->comment('Restoring App.php');

        $this->restoreApp();

        $this->comment('Finished restoring App.php');

        $this->info('Finished Removing environment detector setup');
    }

    /**
     * Removes the environment detector file from bootstrap
     * @return void
     */
    private function removeEnv(): void {
        // Get full path //
        $env_path = base_path("bootstrap/{$this->ref_name}");

        // Check file exists //
        if (!File::exists($env_path)) {
            $this->warn("Could not locate `{$this->ref_name}` for removal");
        } else {
            // Remove the file //
            if (!File::delete($env_path)) {
                $this->warn("Could not remove: `{$this->ref_name}`");
            } else {
                $this->comment("Removed: `{$this->ref_name}`");
            }
        }
    }

    /**
     * Restores App.php to previous version, or attempts to remove require statement
     * @return void
     */
    private function restoreApp(): void {
        // Full path to app.php //
        $file_path = base_path('bootstrap/app.php');

        // Get the backup file to use //
        $backup = $this->getBackupFile($file_path);

        try {
            // No backup file exists, attempt to update current //
            if ($backup === '') {
                if (!$this->confirm('No backup detected, would you like to attempt to modify current App.php')) {
                    $this->comment('Exiting backup restore');

                    return;
                }

                // Check if filepath exists //
                if (!File::exists($file_path)) {
                    $this->warn("{$file_path} does not exist");
                } elseif (!File::isReadable($file_path)) {
                    $this->warn("{$file_path} cannot be read");
                } elseif (!File::isWritable($file_path)) {
                    $this->warn("{$file_path} is not writable");
                } else {
                    // Get current contents //
                    $file = File::get($file_path);
                    // Get the search needle //
                    $needle = File::get(dirname(__DIR__) . '/Stubs/require.stub');
                    // Get ref name without suffix //
                    $detect = basename($this->ref_name, '.php');

                    // Check if environment detector exists in app.php //
                    if (stripos($file, $detect) === false) {
                        $this->info('No mention of environment detector in App.php');

                        return;
                    }

                    // Replace env detector stub //
                    $replace = str_replace($needle, '', $file);

                    File::replace($file_path, $replace);

                    // Reset and reuse file var //
                    unset($file);

                    $file = File::get($file_path);

                    // Check again //
                    if (stripos($file, $detect) === false) {
                        $this->info('Environment detector require removed from App.php');
                    } else {
                        $this->info('Unable to confirm environment_detector require was removed from App.php');
                    }

                }
            } elseif (File::move($backup, $file_path)) {
                // Attempt to move backup file //
                $this->comment('Latest backup restored');
            } else {
                // Could not restore backup //
                $this->comment('Could not restore latest backup');
            }
        } catch (Exception $e) {
            $this->alert($e->getMessage());
            Log::critical(__METHOD__, ['Exception' => $e]);
            $this->error('An exception has occurred restoring App.php. Please check logs for more information');
        }
    }

    /**
     * Gets the backup file if it exists
     * @param string $file_path The file path to search for backups
     * @return string
     */
    private function getBackupFile(string $file_path): string {
        // collect backed up app files //
        $backups = collect(File::glob($file_path . '.*'));

        // Backup file name //
        $backup = '';

        // Check if backups exist //
        if ($backups->isEmpty()) {
            $this->warn('No Backups of App.php found');
        } elseif (count($backups) > 1) {

            // Set last modified time //
            $backups->transform(function ($name, $key) {
                return [
                    'name' => $name,
                    'time' => File::lastModified($name),
                ];
            });

            // Determine last modified date //
            $sorted = $backups->sortByDesc('time');
            $backup = $sorted->shift()['name'];
        } else {
            $backup = $backups->shift();
        }

        return $backup;
    }
}
