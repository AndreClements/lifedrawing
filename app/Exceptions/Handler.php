<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\Response;

/**
 * Global exception handler.
 *
 * Catches everything the kernel's try-catch misses. Logs with and-yet context.
 * Shows clean error pages in production, detailed info in development.
 */
final class Handler
{
    public function __construct(
        private readonly string $environment = 'production',
        private readonly string $logPath = '',
    ) {}

    /** Handle an exception and return a Response. */
    public function handle(\Throwable $e): Response
    {
        $this->log($e);

        $status = match (true) {
            $e instanceof ConsentException  => 403,
            $e instanceof DignityException  => 403,
            $e instanceof AppException      => 500,
            default                         => 500,
        };

        if ($this->environment === 'local') {
            return Response::html($this->renderDev($e), $status);
        }

        return Response::html($this->renderProd($status), $status);
    }

    private function log(\Throwable $e): void
    {
        $path = $this->logPath ?: LDR_ROOT . '/storage/logs/app.log';
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $context = ($e instanceof AppException) ? $e->context() : [];
        $entry = sprintf(
            "[%s] %s: %s in %s:%d%s\n",
            date('Y-m-d H:i:s'),
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $context ? ' | and_yet: ' . ($context['and_yet'] ?? 'none') : '',
        );

        file_put_contents($path, $entry, FILE_APPEND | LOCK_EX);
    }

    private function renderDev(\Throwable $e): string
    {
        $class = get_class($e);
        $message = htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
        $file = htmlspecialchars($e->getFile(), ENT_QUOTES, 'UTF-8');
        $line = $e->getLine();
        $trace = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
        $andYet = ($e instanceof AppException) ? htmlspecialchars($e->andYet, ENT_QUOTES, 'UTF-8') : '';
        $andYetHtml = $andYet !== '' ? "<div class=\"and-yet\"><strong>And Yet:</strong> {$andYet}</div>" : '';

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>Error</title>
        <style>
            body { font-family: system-ui, sans-serif; max-width: 900px; margin: 2rem auto; padding: 0 1rem; color: #1a1a1a; background: #fafafa; }
            h1 { color: #c0392b; font-size: 1.4rem; }
            .meta { color: #666; font-size: 0.9rem; margin-bottom: 1rem; }
            .and-yet { background: #fff3cd; border-left: 4px solid #ffc107; padding: 0.75rem 1rem; margin: 1rem 0; font-style: italic; }
            pre { background: #f5f5f5; padding: 1rem; overflow-x: auto; font-size: 0.85rem; border-radius: 4px; }
        </style>
        </head>
        <body>
            <h1>{$class}</h1>
            <p><strong>{$message}</strong></p>
            <p class="meta">{$file}:{$line}</p>
            {$andYetHtml}
            <pre>{$trace}</pre>
        </body>
        </html>
        HTML;
    }

    private function renderProd(int $status): string
    {
        $title = match ($status) {
            403 => 'Access Denied',
            404 => 'Not Found',
            default => 'Something Went Wrong',
        };

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>{$title}</title>
        <style>
            body { font-family: system-ui, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; color: #333; background: #fafafa; }
            .box { text-align: center; }
            h1 { font-size: 3rem; margin-bottom: 0.5rem; color: #1a1a1a; }
            p { color: #666; }
        </style>
        </head>
        <body>
            <div class="box">
                <h1>{$status}</h1>
                <p>{$title}</p>
            </div>
        </body>
        </html>
        HTML;
    }
}
