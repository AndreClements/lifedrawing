<?php

declare(strict_types=1);

namespace App\View;

/**
 * Minimal PHP template engine.
 *
 * Templates are plain PHP files. No new syntax to learn.
 * Supports layouts via extend/section/yield pattern.
 */
final class Template
{
    private string $basePath;

    /** @var array<string, string> Section content captured via section()/endSection() */
    private array $sections = [];

    private ?string $currentSection = null;

    private ?string $layout = null;

    public function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Render a template with the given data.
     *
     * @param string $view  Dot-notation path, e.g., 'sessions.index' => sessions/index.php
     * @param array  $data  Variables to extract into template scope
     */
    public function render(string $view, array $data = []): string
    {
        $file = $this->resolve($view);

        if (!file_exists($file)) {
            throw new \RuntimeException("View [{$view}] not found at [{$file}].");
        }

        // Render the view
        $content = $this->capture($file, $data);

        // If the view declared a layout, render it with sections
        if ($this->layout !== null) {
            $layoutFile = $this->resolve($this->layout);
            $this->layout = null; // Reset for next render

            $data['__content'] = $content;
            $content = $this->capture($layoutFile, $data);
        }

        return $content;
    }

    /** Render a view from a specific module's Views directory. */
    public function renderModule(string $module, string $view, array $data = []): string
    {
        $oldBase = $this->basePath;
        $this->basePath = LDR_ROOT . "/modules/{$module}/Views";
        $result = $this->render($view, $data);
        $this->basePath = $oldBase;
        return $result;
    }

    // --- Layout helpers (called from within templates) ---

    /** Declare the layout this view extends. */
    public function extend(string $layout): void
    {
        $this->layout = $layout;
    }

    /** Begin capturing a named section. */
    public function section(string $name): void
    {
        $this->currentSection = $name;
        ob_start();
    }

    /** End the current section capture. */
    public function endSection(): void
    {
        if ($this->currentSection === null) {
            throw new \RuntimeException('endSection() called without matching section().');
        }
        $this->sections[$this->currentSection] = ob_get_clean();
        $this->currentSection = null;
    }

    /** Output a section's content (called in layouts). */
    public function yield(string $name, string $default = ''): string
    {
        return $this->sections[$name] ?? $default;
    }

    // --- Internal ---

    private function resolve(string $view): string
    {
        $path = str_replace('.', DIRECTORY_SEPARATOR, $view);
        return $this->basePath . DIRECTORY_SEPARATOR . $path . '.php';
    }

    private function capture(string $file, array $data): string
    {
        extract($data, EXTR_SKIP);
        $__template = $this; // Make $this available as $__template in views

        ob_start();
        require $file;
        return ob_get_clean();
    }
}
