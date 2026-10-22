<?php

namespace App\Console\Commands;

use Illuminate\Foundation\Console\ServeCommand as BaseServeCommand;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * artisan serve (the production web server) with the PHP settings in docker/php/uploads.ini passed to its php -S
 * child as -d flags. PHP's default upload_max_filesize (2M) is below the admin image rules; an ini scan dir is not
 * an option, because PHP_INI_SCAN_DIR replaces the scan dir where Nix's php wrapper enables the extensions.
 */
#[AsCommand(name: 'serve')]
class ServeCommand extends BaseServeCommand
{
    protected function serverCommand()
    {
        $command = parent::serverCommand();
        $settings = parse_ini_file(base_path('docker/php/uploads.ini')) ?: [];
        $flags = collect($settings)->flatMap(fn ($value, $key) => ['-d', "{$key}={$value}"])->all();

        array_splice($command, 1, 0, $flags);

        return $command;
    }
}
