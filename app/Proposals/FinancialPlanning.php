<?php

namespace App\Proposals;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Bounded private browser-session comparisons, separate from proposal/business records. Controllers retain authority. */
class FinancialPlanning
{
    public function __construct(private FinancialScenario $calculator) {}

    private function key(string $scope): string
    {
        return 'proposal_financial.'.hash('sha256', $scope);
    }

    private function exactInput(array $input): string
    {
        $sort = function (mixed $value) use (&$sort): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }

            return array_map($sort, $value);
        };

        return json_encode($sort($input), JSON_THROW_ON_ERROR);
    }

    private function binding(array $payload, string $scope): string
    {
        return hash('sha256', $scope."\0".FinancialScenario::rateFingerprint()."\0".json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function state(Request $request, array $payload, string $scope): array
    {
        $binding = $this->binding($payload, $scope);
        $state = $request->session()->get($this->key($scope));

        return is_array($state) && ($state['binding'] ?? null) === $binding
            ? $state : ['binding' => $binding, 'draft' => null, 'scenarios' => []];
    }

    public function view(Request $request, array $payload, string $scope, string $postUrl): array
    {
        $state = $this->state($request, $payload, $scope);
        $scenarios = [];
        foreach ($state['scenarios'] as $id => $input) {
            $scenarios[$id] = $this->calculator->calculate($payload, $input);
        }

        return ['binding' => $state['binding'], 'post_url' => $postUrl, 'draft' => $state['draft'], 'scenarios' => $scenarios];
    }

    public function apply(Request $request, array $payload, string $scope): void
    {
        $state = $this->state($request, $payload, $scope);
        abort_unless(is_string($request->input('planning_hash')) && hash_equals($state['binding'], $request->input('planning_hash')), 409, 'The saved proposal or rate reference changed. Reload this Financial panel.');
        $action = $request->input('action');
        abort_unless(in_array($action, ['prepare', 'save', 'remove', 'clear', 'profile:0', 'profile:1', 'profile:2'], true), 422);
        if ($action === 'clear') {
            $state['draft'] = null;
            $state['scenarios'] = [];
        } elseif ($action === 'remove') {
            $id = $request->input('scenario_id');
            abort_unless(is_string($id) && array_key_exists($id, $state['scenarios']), 404);
            unset($state['scenarios'][$id]);
        } elseif ($action === 'prepare') {
            if (count($state['scenarios']) >= 3) {
                throw ValidationException::withMessages(['cart' => 'Remove a scenario before adding another. Compare up to three at once.']);
            }
            $state['draft'] = ['id' => (string) Str::uuid(), 'input' => $this->calculator->prepare($payload,
                $request->only(['name', 'service_id', 'department_count', 'profile', 'management']))];
        } else {
            $id = $request->input('scenario_id');
            abort_unless(is_string($id) && (($state['draft']['id'] ?? null) === $id || isset($state['scenarios'][$id])), 409, 'Start a new scenario before saving these assumptions.');
            $input = $request->input('scenario');
            abort_unless(is_array($input), 422);
            $input = $this->calculator->normalize($payload, $input);
            if (str_starts_with($action, 'profile:')) {
                abort_unless(($state['draft']['id'] ?? null) === $id, 409);
                $index = (int) substr($action, -1);
                abort_unless(isset($input['departments'][$index]), 422);
                $row = $input['departments'][$index];
                $input['departments'][$index] = array_replace($this->calculator->profile($row['profile']),
                    array_intersect_key($row, array_flip(['label', 'method', 'model', 'ai', 'voice', 'sms', 'email'])));
                $state['draft']['input'] = $input;
            } else {
                if (isset($state['scenarios'][$id])) {
                    abort_unless($this->exactInput($state['scenarios'][$id]) === $this->exactInput($input), 409, 'This comparison was already saved with different assumptions. Add a new scenario.');
                } else {
                    abort_unless(count($state['scenarios']) < 3, 422);
                    $state['scenarios'][$id] = $input;
                }
                $state['draft'] = null;
            }
        }
        $request->session()->put($this->key($scope), $state);
    }

    /** Keep invalid form feedback bounded and exclude every unrelated field (passwords/tokens included). */
    public function feedback(Request $request): array
    {
        $copy = static function (mixed $value, array $keys): array {
            if (! is_array($value)) {
                return [];
            }

            return collect(array_intersect_key($value, array_flip($keys)))->filter(fn ($v) => is_scalar($v) || $v === null)
                ->map(fn ($v) => $v === null ? null : mb_substr((string) $v, 0, 80))->all();
        };
        $out = $copy($request->all(), ['name', 'service_id', 'department_count', 'profile', 'management']);
        $scenario = $request->input('scenario');
        if (is_array($scenario)) {
            $out['scenario'] = $copy($scenario, ['name', 'service_id', 'term_months', 'management', 'method', 'phone_numbers']);
            $rows = is_array($scenario['departments'] ?? null) ? array_slice(array_values($scenario['departments']), 0, 3) : [];
            $out['scenario']['departments'] = array_map(fn ($row) => $copy($row, ['label', 'profile', 'ai', 'model', 'questions', 'input_tokens', 'output_tokens', 'voice', 'voice_minutes', 'sms', 'sms_segments', 'email', 'emails', 'attachment_gb', 'method']), $rows);
        }

        return $out;
    }
}
