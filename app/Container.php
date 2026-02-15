<?php

declare(strict_types=1);

namespace App;

use Closure;

/**
 * Minimal Dependency Injection Container.
 *
 * Supports singletons and factory bindings. No auto-wiring — explicit is better
 * than magic. PSR-11 compatible interface without the dependency.
 */
final class Container
{
    private static ?self $instance = null;

    /** @var array<string, Closure> */
    private array $factories = [];

    /** @var array<string, mixed> */
    private array $singletons = [];

    /** @var array<string, true> */
    private array $shared = [];

    public static function getInstance(): self
    {
        return self::$instance ??= new self();
    }

    /** Bind a factory closure. */
    public function bind(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
    }

    /** Bind as a shared singleton — resolved once, returned forever. */
    public function singleton(string $id, Closure $factory): void
    {
        $this->factories[$id] = $factory;
        $this->shared[$id] = true;
    }

    /** Store an already-resolved instance directly. */
    public function instance(string $id, mixed $value): void
    {
        $this->singletons[$id] = $value;
    }

    /** Resolve a binding. */
    public function get(string $id): mixed
    {
        // Already resolved singleton?
        if (array_key_exists($id, $this->singletons)) {
            return $this->singletons[$id];
        }

        // Has a factory?
        if (isset($this->factories[$id])) {
            $result = ($this->factories[$id])($this);

            // Cache if shared
            if (isset($this->shared[$id])) {
                $this->singletons[$id] = $result;
            }

            return $result;
        }

        throw new \RuntimeException("Container: no binding for [{$id}].");
    }

    /** Check if a binding exists. */
    public function has(string $id): bool
    {
        return array_key_exists($id, $this->singletons) || isset($this->factories[$id]);
    }
}
