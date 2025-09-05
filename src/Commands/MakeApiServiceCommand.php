<?php

namespace Rupadana\ApiService\Commands;

use Filament\Facades\Filament;
use Filament\Panel;
use Filament\Support\Commands\Concerns\CanManipulateFiles;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;

use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
if (!function_exists('blank')) {
    function blank($value)
    {
        return empty($value) && !is_numeric($value);
    }
}

class MakeApiServiceCommand extends Command
{
    use CanManipulateFiles;
    protected $description = 'Create a new API Service for supporting filamentphp Resource';
    protected $signature = 'make:filament-api-service {resource?} {--panel=}';

    public function handle(): int
    {
        $panel = $this->option('panel');
        if ($panel) {
            $panel = Filament::getPanel($panel);
        }
        if (! $panel) {
            $panels = Filament::getPanels();
            /** @var Panel $panel */
            $panel = (count($panels) > 1) ? $panels[select(
                label: 'Which panel would you like to create this in?',
                options: array_map(
                    fn (Panel $panel): string => $panel->getId(),
                    $panels,
                ),
                default: Filament::getDefaultPanel()->getId()
            )] : Arr::first($panels);
        }

        $inputResource = $this->argument('resource') ?? text(
            label: 'What is the Resource name?',
            placeholder: 'Blog',
            required: true,
        );

        // Normalize to namespace format
        $inputResource = \Illuminate\Support\Str::of($inputResource)
            ->replace('/', '\\')
            ->trim('\\')
            ->trim(' ');

        // If input is fully qualified (starts with any known resource namespace), use as-is, otherwise prepend default
        $resourceNamespaces = $panel->getResourceNamespaces();
        $resourceNamespaceBase = Arr::first($resourceNamespaces) ?? 'App\\Filament\\Resources';
        $startsWithKnownNamespace = false;
        foreach ($resourceNamespaces as $ns) {
            if (Str::startsWith($inputResource, $ns)) {
                $startsWithKnownNamespace = true;
                break;
            }
        }
        if (!$startsWithKnownNamespace) {
            $model = $resourceNamespaceBase . '\\' . $inputResource;
        } else {
            $model = (string) $inputResource;
        }
        // Always remove any leading App\Filament\Resources\ from the model
        $model = preg_replace('/^App\\Filament\\Resources\\/i', '', $model);

        // Remove trailing 'Resource' for model namespace
        $model = \Illuminate\Support\Str::of($model)->beforeLast('Resource');
        if (blank($model)) {
            $model = 'Resource';
        }

        $modelClass = (string) \Illuminate\Support\Str::of($model)->afterLast('\\');
        $modelNamespace = \Illuminate\Support\Str::of($model)->contains('\\') ? (string) \Illuminate\Support\Str::of($model)->beforeLast('\\') : '';
        $pluralModelClass = (string) \Illuminate\Support\Str::of($modelClass)->pluralStudly();

        $resourceDirectories = $panel->getResourceDirectories();
        $resourceNamespaces = $panel->getResourceNamespaces();

        $namespace = (count($resourceNamespaces) > 1) ?
            select(
                label: 'Which namespace would you like to create this in?',
                options: $resourceNamespaces
            ) : (Arr::first($resourceNamespaces) ?? 'App\\Filament\\Resources');
        $path = (count($resourceDirectories) > 1) ?
            $resourceDirectories[array_search($namespace, $resourceNamespaces)] : (Arr::first($resourceDirectories) ?? app_path('Filament/Resources/'));

        $resource = "{$model}Resource";
        $resourceClass = "{$modelClass}Resource";
        $apiServiceClass = "{$model}ApiService";
        $transformer = "{$model}Transformer";
        $resourceNamespace = $modelNamespace;
        $namespace .= $resourceNamespace !== '' ? "\\{$resourceNamespace}" : '';

        $createHandlerClass = 'CreateHandler';
        $updateHandlerClass = 'UpdateHandler';
        $detailHandlerClass = 'DetailHandler';
        $paginationHandlerClass = 'PaginationHandler';
        $deleteHandlerClass = 'DeleteHandler';

        $baseResourcePath =
            (string) str("{$pluralModelClass}\\{$resource}")
                ->prepend('/')
                ->prepend($path)
                ->replace('\\', '/')
                ->replace('//', '/');

        $transformerClass = "{$namespace}\\{$pluralModelClass}\\{$resourceClass}\\Api\\Transformers\\{$transformer}";
        $handlersNamespace = "{$namespace}\\{$pluralModelClass}\\{$resourceClass}\\Api\\Handlers";

        $resourceApiDirectory = "{$baseResourcePath}/Api/$apiServiceClass.php";
        $createHandlerDirectory = "{$baseResourcePath}/Api/Handlers/$createHandlerClass.php";
        $updateHandlerDirectory = "{$baseResourcePath}/Api/Handlers/$updateHandlerClass.php";
        $detailHandlerDirectory = "{$baseResourcePath}/Api/Handlers/$detailHandlerClass.php";
        $paginationHandlerDirectory = "{$baseResourcePath}/Api/Handlers/$paginationHandlerClass.php";
        $deleteHandlerDirectory = "{$baseResourcePath}/Api/Handlers/$deleteHandlerClass.php";

        Artisan::call('make:filament-api-transformer', [
            'resource' => $model,
            '--panel' => $panel->getId(),
        ]);
        collect(['Create', 'Update'])
            ->each(function ($name) use ($model, $panel) {
                Artisan::call('make:filament-api-request', [
                    'name' => $name,
                    'resource' => $model,
                    '--panel' => $panel->getId(),
                ]);
            });

        $this->copyStubToApp('ResourceApiService', $resourceApiDirectory, [
            'namespace' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}\\Api",
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'resourcePageClass' => $resourceApiDirectory,
            'apiServiceClass' => $apiServiceClass,
        ]);

        $this->copyStubToApp('DeleteHandler', $deleteHandlerDirectory, [
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'handlersNamespace' => $handlersNamespace,
            'model' => $model,
        ]);

        $this->copyStubToApp('DetailHandler', $detailHandlerDirectory, [
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'handlersNamespace' => $handlersNamespace,
            'transformer' => $transformer,
            'transformerClass' => $transformerClass,
            'model' => $model,
        ]);

        $this->copyStubToApp('CreateHandler', $createHandlerDirectory, [
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'handlersNamespace' => $handlersNamespace,
            'model' => $model,
        ]);

        $this->copyStubToApp('UpdateHandler', $updateHandlerDirectory, [
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'handlersNamespace' => $handlersNamespace,
            'model' => $model,
        ]);

        $this->copyStubToApp('PaginationHandler', $paginationHandlerDirectory, [
            'resource' => "{$namespace}\\{$pluralModelClass}\\{$resourceClass}",
            'resourceClass' => $resourceClass,
            'handlersNamespace' => $handlersNamespace,
            'transformer' => $transformer,
            'transformerClass' => $transformerClass,
            'model' => $model,

        ]);

        $this->components->info("Successfully created API for {$resource}!");
        $this->components->info("It automatically registered to '/api' route group");

        return static::SUCCESS;
    }
}
