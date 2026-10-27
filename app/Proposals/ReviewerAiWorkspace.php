<?php

namespace App\Proposals;

use Illuminate\Http\Request;

/** Selects the immutable publication's evidence mode. Shared mode never accepts browser facts or falls back locally. */
final class ReviewerAiWorkspace
{
    public function shared(array $scope): bool
    {
        return array_key_exists('review_workspace', $scope['version']->payload ?? []);
    }

    public function prepare(array $scope, Request $request, string $token, array $body): array
    {
        if (! $this->shared($scope)) {
            abort_unless(! array_key_exists('workspace', $body) && is_array($body['snapshot'] ?? null)
                && ($body['snapshot']['coverage'] ?? null) !== ReviewerAiSnapshot::COVERAGE_SERVER, 422);

            return ['scope' => $scope, 'snapshot' => $body['snapshot']];
        }
        abort_unless(! array_key_exists('snapshot', $body) && is_array($body['workspace'] ?? null), 422,
            'Shared review AI retrieves its facts from the hosted workspace.');
        $value = $body['workspace'];
        $keys = array_keys($value);
        sort($keys);
        abort_unless($keys === ['expected_revision', 'selection'] && is_int($value['expected_revision']) && $value['expected_revision'] >= 1
            && is_array($value['selection']), 422);
        $facts = app(ReviewerWorkspace::class)->facts($request, $token, $body['binding'], $value['selection']);
        abort_unless(($facts['pin']['revision'] ?? null) === $value['expected_revision'], 409,
            'The shared work changed. Review the current server fields before sending.');
        $context = ['selection' => $value['selection'], 'pin' => $facts['pin']];

        return ['scope' => $this->attach($scope, $request, $token, $body['binding'], $context), 'snapshot' => $facts['snapshot']];
    }

    /** Every callback resolves current recipient/workspace authority again; none is persisted in a receipt. */
    public function attach(array $scope, Request $request, string $token, array $binding, ?array $context, bool $strict = true): array
    {
        if (! $this->shared($scope)) {
            abort_unless($context === null, 409);

            return $scope;
        }
        abort_unless(is_array($context) && count($context) === 2 && is_array($context['selection'] ?? null) && is_array($context['pin'] ?? null), 409);
        $read = fn () => app(ReviewerWorkspace::class)->assertFacts($request, $token, $binding, $context['selection'], $context['pin']);
        $stale = false;
        if ($strict) {
            $read();
        } else {
            $stale = app(ReviewerWorkspace::class)->statusFacts($request, $token, $binding, $context['selection'], $context['pin'])['matches'] !== true;
        }

        return $scope + ['workspace_context' => $context, 'workspace_stale' => $stale, 'workspace_recheck' => $read];
    }
}
