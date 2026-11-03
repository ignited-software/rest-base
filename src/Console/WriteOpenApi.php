<?php

namespace Ignited\RestBase\Console;

use Ignited\RestBase\OpenApi;
use Illuminate\Console\Command;

/** Writes the OpenAPI document to a file, to commit next to the code; a test can then compare the two. */
class WriteOpenApi extends Command
{
    protected $signature = 'rest-base:openapi {--output= : the file to write; default config rest-base.openapi.file} {--check : only compare, and fail when the file is out of date}';

    protected $description = 'Write (or check) the OpenAPI document built from the API descriptors';

    public function handle(OpenApi $openApi): int
    {
        $file = $this->option('output') ?: config('rest-base.openapi.file');
        if (! is_string($file) || $file === '') {
            $this->error('Name a file with --output, or set rest-base.openapi.file.');

            return self::FAILURE;
        }
        $json = $openApi->json();
        if ($this->option('check')) {
            $same = is_file($file) && file_get_contents($file) === $json;
            $same ? $this->info("{$file} is up to date.") : $this->error("{$file} is out of date: run php artisan rest-base:openapi.");

            return $same ? self::SUCCESS : self::FAILURE;
        }
        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0775, true);
        }
        file_put_contents($file, $json);
        $this->info("Wrote {$file}.");

        return self::SUCCESS;
    }
}
