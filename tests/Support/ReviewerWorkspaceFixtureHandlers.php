<?php

namespace Tests\Support;

use Closure;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use LogicException;
use ReflectionFunction;

/** Preserve PHPUnit/custom handler stacks around these bare-TestCase Laravel fixture bootstraps, including failed boot. */
trait ReviewerWorkspaceFixtureHandlers
{
    private mixed $workspacePreviousErrorHandler;

    private mixed $workspacePreviousExceptionHandler;

    private int $workspacePreviousErrorReporting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workspacePreviousErrorHandler = self::currentWorkspaceFixtureHandler(false);
        $this->workspacePreviousExceptionHandler = self::currentWorkspaceFixtureHandler(true);
        $this->workspacePreviousErrorReporting = error_reporting();
    }

    protected function tearDown(): void
    {
        try {
            try {
                self::unwindWorkspaceFixtureHandler($this->workspacePreviousErrorHandler, false);
            } finally {
                self::unwindWorkspaceFixtureHandler($this->workspacePreviousExceptionHandler, true);
            }
        } finally {
            error_reporting($this->workspacePreviousErrorReporting);
            parent::tearDown();
        }
    }

    /** Balanced observer available before PHP8.5; restore pops the temporary frame instead of re-pushing the previous callback. */
    private static function currentWorkspaceFixtureHandler(bool $exception): mixed
    {
        if ($exception) {
            $previous = set_exception_handler(null);
            restore_exception_handler();
        } else {
            $previous = set_error_handler(null);
            restore_error_handler();
        }

        return $previous;
    }

    private static function unwindWorkspaceFixtureHandler(mixed $previous, bool $exception): void
    {
        for ($depth = 0; $depth <= 8; $depth++) {
            $current = self::currentWorkspaceFixtureHandler($exception);
            if ($current === $previous) {
                return;
            }
            // Never pop unrelated/PHPUnit handlers or re-push the old callback as a duplicate stack entry.
            if ($depth === 8 || ! $current instanceof Closure || ! (new ReflectionFunction($current))->getClosureThis() instanceof HandleExceptions) {
                throw new LogicException('Unexpected handler stack in workspace MySQL fixture cleanup.');
            }
            if ($exception) {
                restore_exception_handler();
            } else {
                restore_error_handler();
            }
        }
    }
}
