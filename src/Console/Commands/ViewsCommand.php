<?php

namespace Slipstream\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Foundation\Configuration\ApplicationBuilder;
use Illuminate\Foundation\Exceptions\RegisterErrorViewPaths;
use Symfony\Component\Finder\Finder;

/**
 * Compile every view where Laravel will look for it, so an artifact deploy
 * records them all before the swap: a compiled view that appears after the
 * deploy is drift.
 *
 * view:cache alone leaves gaps. It compiles each file by its real path,
 * while Laravel renders a package view by the path the package registered
 * (often with "/../" in it), and the compiled file is named by a hash of that
 * path. It never sees the error pages either, a namespace Laravel adds only
 * when it renders one, or the /up health page, which renders by file path.
 */
class ViewsCommand extends Command
{
    protected $signature = 'slipstream:views';

    protected $description = 'Compile every view by the path Laravel renders it from, error pages and the health page included';

    public function handle(): int
    {
        $view = $this->laravel['view'];
        $compiler = $view->getEngineResolver()->resolve('blade')->getCompiler();

        // The error pages' namespace exists only once an error page renders.
        (new RegisterErrorViewPaths)();

        $finder = $view->getFinder();
        $roots = $finder->getPaths();
        foreach ($finder->getHints() as $hints) {
            foreach ((array) $hints as $hint) {
                $roots[] = (string) $hint;
            }
        }
        $names = array_map(
            fn (string $extension): string => '*.'.$extension,
            array_keys(array_filter($view->getExtensions(), fn (string $engine): bool => $engine === 'blade')),
        );

        $files = [];
        foreach (array_unique($roots) as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach (Finder::create()->in($root)->files()->name($names) as $file) {
                // The path the view finder builds: the root as registered,
                // then the view's own path.
                $files[] = $root.'/'.str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        // The health route renders this file by a path with "/../" in it.
        $health = dirname((string) (new \ReflectionClass(ApplicationBuilder::class))->getFileName()).'/../resources/health-up.blade.php';
        if (is_file($health)) {
            $files[] = $health;
        }

        $files = array_values(array_unique($files));
        $compiled = 0;
        foreach ($files as $file) {
            if ($compiler->isExpired($file)) {
                $compiler->compile($file);
                $compiled++;
            }
        }

        $this->components->info(sprintf(
            'Compiled %d view(s) into %s (%d already were).',
            $compiled,
            (string) config('view.compiled'),
            count($files) - $compiled,
        ));

        return self::SUCCESS;
    }
}
