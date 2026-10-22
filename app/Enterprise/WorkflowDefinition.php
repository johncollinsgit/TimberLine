<?php

namespace App\Enterprise;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/** A small, closed operational language. It has no expressions, URLs, tools or provider actions. */
class WorkflowDefinition
{
    public function compile(array $definition): array
    {
        abort_if(strlen(json_encode($definition, JSON_THROW_ON_ERROR)) > 65536, 422, 'The workflow is too large.');
        $slug = 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D';
        $d = Validator::make($definition, [
            'schema_version' => ['required', 'integer', 'in:1'],
            'nodes' => ['required', 'array', 'min:2', 'max:24'],
            'nodes.*' => ['required', 'array:id,type,label,position,screen,assignee_id,condition,task_id'],
            'nodes.*.id' => ['required', 'string', $slug, 'distinct'],
            'nodes.*.type' => ['required', Rule::in(['start', 'form', 'condition', 'assignment', 'approval', 'complete'])],
            'nodes.*.label' => ['required', 'string', 'max:100'],
            'nodes.*.position' => ['required', 'array:x,y'],
            'nodes.*.position.x' => ['required', 'numeric', 'between:-10000,10000'],
            'nodes.*.position.y' => ['required', 'numeric', 'between:-10000,10000'],
            'nodes.*.screen' => ['sometimes', 'array:title,description,fields'],
            'nodes.*.screen.title' => ['required_with:nodes.*.screen', 'string', 'max:100'],
            'nodes.*.screen.description' => ['present_with:nodes.*.screen', 'nullable', 'string', 'max:1000'],
            'nodes.*.screen.fields' => ['present_with:nodes.*.screen', 'array', 'max:8'],
            'nodes.*.screen.fields.*' => ['array:key,label,type,required,options'],
            'nodes.*.screen.fields.*.key' => ['required', 'string', $slug],
            'nodes.*.screen.fields.*.label' => ['required', 'string', 'max:100'],
            'nodes.*.screen.fields.*.type' => ['required', Rule::in(['text', 'textarea', 'select', 'date', 'checkbox'])],
            'nodes.*.screen.fields.*.required' => ['required', 'boolean'],
            'nodes.*.screen.fields.*.options' => ['sometimes', 'array', 'min:1', 'max:12'],
            'nodes.*.screen.fields.*.options.*' => ['string', 'max:100'],
            'nodes.*.assignee_id' => ['sometimes', 'string', 'regex:/^[1-9][0-9]{0,18}$/D'],
            'nodes.*.task_id' => ['sometimes', 'uuid'],
            'nodes.*.condition' => ['sometimes', 'array:field,operator,value'],
            'nodes.*.condition.field' => ['required_with:nodes.*.condition', 'string', $slug],
            'nodes.*.condition.operator' => ['required_with:nodes.*.condition', 'in:equals,not_equals'],
            'nodes.*.condition.value' => ['present_with:nodes.*.condition'],
            'edges' => ['present', 'array', 'min:1', 'max:32'],
            'edges.*' => ['array:id,source,target,branch'],
            'edges.*.id' => ['required', 'string', $slug, 'distinct'],
            'edges.*.source' => ['required', 'string', $slug],
            'edges.*.target' => ['required', 'string', $slug],
            'edges.*.branch' => ['required', 'in:always,yes,no'],
        ])->validate();
        abort_unless(array_diff(array_keys($definition), ['schema_version', 'nodes', 'edges']) === [], 422, 'Unsupported workflow settings.');
        foreach ($d['nodes'] as &$node) {
            if (isset($node['screen'])) {
                $node['screen']['description'] = $node['screen']['description'] ?? '';
            }
        }
        unset($node);
        $nodes = array_column($d['nodes'], null, 'id');
        $start = array_values(array_filter($nodes, fn ($n) => $n['type'] === 'start'));
        abort_unless(count($start) === 1 && count(array_filter($nodes, fn ($n) => $n['type'] === 'complete')) > 0, 422, 'Choose one start and at least one completion.');
        $fields = [];
        foreach ($nodes as $node) {
            $manual = in_array($node['type'], ['form', 'assignment', 'approval'], true);
            abort_unless($manual === isset($node['screen']), 422, 'Only human steps must have an operational screen.');
            abort_unless(in_array($node['type'], ['assignment', 'approval'], true) === isset($node['assignee_id']), 422, 'Assignments and approvals require an existing assignee.');
            abort_unless(($node['type'] === 'condition') === isset($node['condition']), 422, 'Only conditions may contain a field condition.');
            abort_unless(! isset($node['task_id']) || in_array($node['type'], ['assignment', 'approval'], true), 422, 'Only assignments and approvals may link canonical tasks.');
            foreach ($node['screen']['fields'] ?? [] as $field) {
                abort_if(isset($fields[$field['key']]), 422, 'Every form field must have a unique key.');
                abort_unless(($field['type'] === 'select') === isset($field['options']), 422, 'Only select fields have options.');
                $fields[$field['key']] = $field + ['node' => $node['id']];
            }
        }
        $out = $parents = [];
        foreach ($d['edges'] as $edge) {
            abort_unless(isset($nodes[$edge['source']], $nodes[$edge['target']]) && $edge['source'] !== $edge['target'], 422, 'Every edge must connect two workflow steps.');
            $out[$edge['source']][] = $edge;
            $parents[$edge['target']][] = $edge['source'];
        }
        abort_if(isset($parents[$start[0]['id']]), 422, 'Start cannot have an incoming connection.');
        foreach ($nodes as $node) {
            $branches = array_column($out[$node['id']] ?? [], 'branch');
            sort($branches);
            $expected = match ($node['type']) {
                'complete' => [], 'condition' => ['no', 'yes'], default => ['always']
            };
            abort_unless($branches === $expected, 422, 'Connect each step once, each condition with yes/no, and no outgoing completion.');
        }
        $visiting = $visited = $order = [];
        $visit = function (string $id) use (&$visit, &$visiting, &$visited, &$order, $out): void {
            abort_if(isset($visiting[$id]), 422, 'Workflow cycles are not supported.');
            if (isset($visited[$id])) {
                return;
            }
            $visiting[$id] = true;
            foreach ($out[$id] ?? [] as $edge) {
                $visit($edge['target']);
            }
            unset($visiting[$id]);
            $visited[$id] = true;
            $order[] = $id;
        };
        $visit($start[0]['id']);
        abort_unless(count($visited) === count($nodes), 422, 'Every step must be reachable from Start.');
        $available = [];
        foreach (array_reverse($order) as $id) {
            $sets = array_map(fn ($p) => $available[$p] ?? [], $parents[$id] ?? []);
            $known = $sets ? array_shift($sets) : [];
            foreach ($sets as $set) {
                $known = array_intersect($known, $set);
            }
            $node = $nodes[$id];
            if ($node['type'] === 'condition') {
                $condition = $node['condition'];
                abort_unless(in_array($condition['field'], $known, true), 422, 'A condition must use a field collected earlier on every incoming path.');
                $field = $fields[$condition['field']];
                abort_unless($field['required'] && (is_string($condition['value']) || is_bool($condition['value'])), 422, 'A condition must use a required field and a literal text or checkbox value.');
                $normalized = $this->values([$field], [$field['key'] => $condition['value']]);
                foreach ($d['nodes'] as &$canonicalNode) {
                    if ($canonicalNode['id'] === $id) {
                        $canonicalNode['condition']['value'] = $normalized[$field['key']];
                    }
                }
                unset($canonicalNode);
            }
            $available[$id] = [...$known, ...array_column($node['screen']['fields'] ?? [], 'key')];
        }

        return ['schema_version' => 1, 'nodes' => array_values($d['nodes']), 'edges' => array_values($d['edges'])];
    }

    public function values(array $fields, array $values): array
    {
        abort_unless(array_diff(array_keys($values), array_column($fields, 'key')) === [], 422, 'Only fields on the current screen may be submitted.');
        $rules = [];
        foreach ($fields as $field) {
            $rules[$field['key']] = [$field['required'] ? 'required' : 'sometimes', ...($field['required'] ? [] : ['nullable']), ...match ($field['type']) {
                'checkbox' => ['boolean'], 'date' => ['string', 'date_format:Y-m-d'],
                'select' => ['string', Rule::in($field['options'])],
                'textarea' => ['string', 'max:4000'], default => ['string', 'max:500'],
            }];
        }
        $result = Validator::make($values, $rules)->validate();
        foreach ($fields as $field) {
            if (isset($result[$field['key']]) && $field['type'] === 'checkbox') {
                $result[$field['key']] = (bool) $result[$field['key']];
            }
        }

        return $result;
    }

    public static function digest(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_THROW_ON_ERROR));
    }

    public static function template(string $assignee): array
    {
        $screen = fn ($title, $fields = []) => ['title' => $title, 'description' => 'Illustrative workflow. Review before activation; no external messages are sent.', 'fields' => $fields];

        return ['schema_version' => 1, 'nodes' => [
            ['id' => 'start', 'type' => 'start', 'label' => 'Start maintenance request', 'position' => ['x' => 0, 'y' => 0]],
            ['id' => 'intake', 'type' => 'form', 'label' => 'Describe the work', 'position' => ['x' => 0, 'y' => 150], 'screen' => $screen('Work intake', [
                ['key' => 'summary', 'label' => 'Work description', 'type' => 'textarea', 'required' => true],
                ['key' => 'priority', 'label' => 'Priority', 'type' => 'select', 'required' => true, 'options' => ['Routine', 'Urgent']],
            ])],
            ['id' => 'priority', 'type' => 'condition', 'label' => 'Urgent work?', 'position' => ['x' => 0, 'y' => 300], 'condition' => ['field' => 'priority', 'operator' => 'equals', 'value' => 'Urgent']],
            ['id' => 'approval', 'type' => 'approval', 'label' => 'Coordinator approval', 'position' => ['x' => 240, 'y' => 450], 'assignee_id' => $assignee, 'screen' => $screen('Review urgent work')],
            ['id' => 'work', 'type' => 'assignment', 'label' => 'Complete the assigned work', 'position' => ['x' => 0, 'y' => 600], 'assignee_id' => $assignee, 'screen' => $screen('Work completion', [['key' => 'outcome', 'label' => 'Completion note', 'type' => 'textarea', 'required' => true]])],
            ['id' => 'complete', 'type' => 'complete', 'label' => 'Work completed', 'position' => ['x' => 0, 'y' => 750]],
        ], 'edges' => [
            ['id' => 'e1', 'source' => 'start', 'target' => 'intake', 'branch' => 'always'],
            ['id' => 'e2', 'source' => 'intake', 'target' => 'priority', 'branch' => 'always'],
            ['id' => 'e3', 'source' => 'priority', 'target' => 'approval', 'branch' => 'yes'],
            ['id' => 'e4', 'source' => 'priority', 'target' => 'work', 'branch' => 'no'],
            ['id' => 'e5', 'source' => 'approval', 'target' => 'work', 'branch' => 'always'],
            ['id' => 'e6', 'source' => 'work', 'target' => 'complete', 'branch' => 'always'],
        ]];
    }
}
