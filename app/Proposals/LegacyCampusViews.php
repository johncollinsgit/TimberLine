<?php

namespace App\Proposals;

use Illuminate\Contracts\View\Engine;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Factory;
use Illuminate\View\ViewFinderInterface;
use InvalidArgumentException;
use LogicException;
use Throwable;

/** Isolated Blade factory over the authenticated snapshot; no filesystem view lookup or compiled cache. */
final class LegacyCampusViews
{
    private Factory $factory;

    public function __construct(LegacyCampusArtifact $artifact)
    {
        $bytes = $artifact->viewBytes();
        $names = [];
        foreach ($bytes as $path => $source) {
            $relative = substr($path, strlen($artifact->viewRoot()) + 1, -strlen('.blade.php'));
            $names['campus-v5::'.str_replace('/', '.', $relative)] = $path;
        }
        $finder = new class($names) implements ViewFinderInterface
        {
            public function __construct(private array $names) {}

            public function find($view)
            {
                if (! is_string($view) || ! isset($this->names[$view])) {
                    throw new InvalidArgumentException('An unlisted legacy view was refused.');
                }

                return $this->names[$view];
            }

            public function addLocation($location)
            {
                throw new LogicException('The legacy finder is immutable.');
            }

            public function addNamespace($namespace, $hints)
            {
                throw new LogicException('The legacy finder is immutable.');
            }

            public function prependNamespace($namespace, $hints)
            {
                throw new LogicException('The legacy finder is immutable.');
            }

            public function replaceNamespace($namespace, $hints)
            {
                throw new LogicException('The legacy finder is immutable.');
            }

            public function addExtension($extension)
            {
                throw new LogicException('The legacy finder is immutable.');
            }

            public function flush() {}
        };
        $engine = new class($bytes) implements Engine
        {
            private BladeCompiler $compiler;

            public function __construct(private array $bytes)
            {
                // compileString never reads/writes this unused cache path. Only signed Blade is evaluated.
                $this->compiler = new BladeCompiler(new Filesystem, '/legacy-campus-in-memory-only', shouldCache: false);
            }

            public function get($path, array $data = [])
            {
                abort_unless(is_string($path) && array_key_exists($path, $this->bytes), 503);
                $compiled = $this->compiler->compileString($this->bytes[$path]);

                return (static function (string $__compiled, array $__data): string {
                    extract($__data, EXTR_SKIP);
                    $__level = ob_get_level();
                    ob_start();
                    try {
                        // This is authenticated server-side template code, never request-supplied code.
                        eval('?>'.$__compiled);

                        return ltrim(ob_get_clean());
                    } catch (Throwable $error) {
                        while (ob_get_level() > $__level) {
                            ob_end_clean();
                        }
                        throw $error;
                    }
                })($compiled, $data);
            }
        };
        $engines = new EngineResolver;
        $engines->register('blade', fn () => $engine);
        $this->factory = new Factory($engines, $finder, new Dispatcher(app()));
        $this->factory->setContainer(app());
        $this->factory->share('app', app());
        $this->factory->share('legacyAssets', $artifact);
    }

    public function render(string $name, array $data): string
    {
        return $this->factory->make('campus-v5::'.$name, $data)->render();
    }
}
