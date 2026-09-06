<?php

use Symfony\Component\Process\Process;

test('forge allowlist helper preserves the environment and adds one explicit tenant idempotently', function (): void {
    $input = tempnam(sys_get_temp_dir(), 'everbranch-env-');
    $firstOutput = tempnam(sys_get_temp_dir(), 'everbranch-env-json-');
    $secondInput = tempnam(sys_get_temp_dir(), 'everbranch-env-second-');
    $secondOutput = tempnam(sys_get_temp_dir(), 'everbranch-env-json-second-');
    file_put_contents($input, "APP_NAME=Everbranch\nSECRET_VALUE=do-not-change\nEVERBRANCH_AGREEMENT_CHECKOUT_TENANT_SLUGS=front-yard-foods\n");

    try {
        $script = dirname(__DIR__, 3).'/scripts/ci/add-env-allowlist-value.php';
        $first = new Process([PHP_BINARY, $script, $input, $firstOutput, 'carolina-barrel-co']);
        $first->mustRun();
        $firstPayload = json_decode((string) file_get_contents($firstOutput), true, flags: JSON_THROW_ON_ERROR);

        expect($firstPayload['content'])->toContain('SECRET_VALUE=do-not-change')
            ->and($firstPayload['content'])->toContain('EVERBRANCH_AGREEMENT_CHECKOUT_TENANT_SLUGS=front-yard-foods,carolina-barrel-co');

        file_put_contents($secondInput, $firstPayload['content']);
        $second = new Process([PHP_BINARY, $script, $secondInput, $secondOutput, 'carolina-barrel-co']);
        $second->mustRun();
        $secondPayload = json_decode((string) file_get_contents($secondOutput), true, flags: JSON_THROW_ON_ERROR);

        expect(substr_count($secondPayload['content'], 'carolina-barrel-co'))->toBe(1)
            ->and($secondPayload['content'])->toBe($firstPayload['content']);
    } finally {
        @unlink($input);
        @unlink($firstOutput);
        @unlink($secondInput);
        @unlink($secondOutput);
    }
});

test('forge allowlist helper refuses wildcard rollout', function (): void {
    $input = tempnam(sys_get_temp_dir(), 'everbranch-env-');
    $output = tempnam(sys_get_temp_dir(), 'everbranch-env-json-');
    file_put_contents($input, "EVERBRANCH_AGREEMENT_CHECKOUT_TENANT_SLUGS=*\n");

    try {
        $process = new Process([PHP_BINARY, dirname(__DIR__, 3).'/scripts/ci/add-env-allowlist-value.php', $input, $output, 'carolina-barrel-co']);
        $process->run();

        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('Refusing to modify a wildcard');
    } finally {
        @unlink($input);
        @unlink($output);
    }
});
