<?php

namespace Tests\Unit\Proposals;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use LogicException;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;
use Tests\Integration\ReviewerWorkspaceMySqlConcurrencyTest;
use Tests\Support\ReviewerWorkspaceFixtureHandlers;

/** Local fixture semantics only: actual Laravel handler bootstrap, no DB/server/app-kernel bootstrap. */
final class ReviewerWorkspaceMySqlFixtureTest extends TestCase
{
    public function test_actual_bootstrap_handlers_and_error_mask_restore_exact_prior_stacks_even_failed_or_nested_boot(): void
    {
        foreach (['before_boot', 'normal', 'failed_after_handlers', 'nested'] as $phase) {
            $outerError = static fn () => false;
            $innerError = static fn () => false;
            $outerException = static fn () => null;
            $innerException = static fn () => null;
            $originalMask = error_reporting();
            set_error_handler($outerError);
            set_error_handler($innerError);
            set_exception_handler($outerException);
            set_exception_handler($innerException);
            error_reporting(E_ALL & ~E_DEPRECATED);
            $mask = error_reporting();
            $harness = $this->handlerHarness();
            $harness->beginFixture();
            try {
                if ($phase !== 'before_boot') {
                    $this->bootstrapHandlers();
                    if ($phase === 'nested') {
                        $this->bootstrapHandlers();
                    }
                }
                if (in_array($phase, ['before_boot', 'failed_after_handlers'], true)) {
                    throw new RuntimeException('FICTIONAL fixture boot failure.');
                }
            } catch (RuntimeException $e) {
                $this->assertSame('FICTIONAL fixture boot failure.', $e->getMessage());
            } finally {
                try {
                    $harness->endFixture();
                    $this->assertSame($innerError, \Symfony\Polyfill\Php85\Php85::get_error_handler());
                    $this->assertSame($innerException, \Symfony\Polyfill\Php85\Php85::get_exception_handler());
                    $this->assertSame($mask, error_reporting());
                    restore_error_handler();
                    restore_exception_handler();
                    $this->assertSame($outerError, \Symfony\Polyfill\Php85\Php85::get_error_handler());
                    $this->assertSame($outerException, \Symfony\Polyfill\Php85\Php85::get_exception_handler());
                } finally {
                    restore_error_handler();
                    restore_exception_handler();
                    error_reporting($originalMask);
                    HandleExceptions::forgetApp();
                }
            }
        }
    }

    public function test_cleanup_refuses_to_pop_a_foreign_handler_and_still_restores_other_handler_and_mask(): void
    {
        $beforeError = \Symfony\Polyfill\Php85\Php85::get_error_handler();
        $beforeException = \Symfony\Polyfill\Php85\Php85::get_exception_handler();
        $beforeMask = error_reporting();
        $harness = $this->handlerHarness();
        $harness->beginFixture();
        $this->bootstrapHandlers();
        $foreign = static fn () => false;
        set_error_handler($foreign);
        try {
            try {
                $harness->endFixture();
                $this->fail('Foreign handler must not be popped by fixture cleanup.');
            } catch (LogicException $e) {
                $this->assertSame('Unexpected handler stack in workspace MySQL fixture cleanup.', $e->getMessage());
            }
            $this->assertSame($foreign, \Symfony\Polyfill\Php85\Php85::get_error_handler());
            $this->assertSame($beforeException, \Symfony\Polyfill\Php85\Php85::get_exception_handler());
            $this->assertSame($beforeMask, error_reporting());
        } finally {
            restore_error_handler(); // Remove only this test's foreign handler.
            $harness->endFixture();
            HandleExceptions::forgetApp();
        }
        $this->assertSame($beforeError, \Symfony\Polyfill\Php85\Php85::get_error_handler());
    }

    public function test_receipt_comparison_preserves_exact_closed_keys_types_and_values_independent_of_mysql_object_order(): void
    {
        $test = new ReviewerWorkspaceMySqlConcurrencyTest('test_unapproved_worker_refuses_before_framework_or_database_bootstrap');
        $method = new ReflectionMethod($test, 'assertSameReceipt');
        $expected = ['request_id' => 'FICTIONAL_REQUEST', 'record_id' => 'FICTIONAL_RECORD', 'revision' => 2, 'digest' => str_repeat('a', 64)];
        foreach ($this->permutations(array_keys($expected)) as $keys) {
            $actual = [];
            foreach ($keys as $key) {
                $actual[$key] = $expected[$key];
            }
            $method->invoke($test, $expected, $actual);
        }
        $bad = $expected;
        unset($bad['request_id']);
        $cases = [$bad, [...$expected, 'extra' => 'forbidden'], [...$expected, 'revision' => '2'], [...$expected, 'request_id' => 'FOREIGN'],
            [...$expected, 'record_id' => 'FOREIGN'], [...$expected, 'digest' => str_repeat('b', 64)], [...$expected, 'revision' => 3]];
        foreach ($cases as $actual) {
            $rejected = false;
            try {
                $method->invoke($test, $expected, $actual);
            } catch (AssertionFailedError) {
                $rejected = true;
            }
            $this->assertTrue($rejected, 'A changed receipt must be rejected.');
        }
    }

    private function bootstrapHandlers(): void
    {
        $app = new Application;
        $app->instance('env', 'testing');
        (new HandleExceptions)->bootstrap($app);
    }

    private function handlerHarness(): TestCase
    {
        return new class('fixture') extends TestCase
        {
            use ReviewerWorkspaceFixtureHandlers;

            public function beginFixture(): void
            {
                $this->setUp();
            }

            public function endFixture(): void
            {
                $this->tearDown();
            }
        };
    }

    private function permutations(array $keys): array
    {
        if ($keys === []) {
            return [[]];
        }
        $out = [];
        foreach ($keys as $index => $key) {
            foreach ($this->permutations(array_values(array_diff_key($keys, [$index => true]))) as $tail) {
                $out[] = [$key, ...$tail];
            }
        }

        return $out;
    }
}
