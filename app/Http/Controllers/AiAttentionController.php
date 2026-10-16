<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AiBranch\AiSharedAttention;
use Illuminate\Http\Request;

class AiAttentionController extends Controller
{
    private function workspace(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    public function index(Request $request, string $tenant = '')
    {
        $data = $request->validate(['view' => ['sometimes', 'in:open,snoozed,resolved'], 'after' => ['sometimes', 'integer', 'min:0']]);

        return response()->json(app(AiSharedAttention::class)->listing($this->workspace($request), $request->user(), $data['view'] ?? 'open', (int) ($data['after'] ?? 0)))->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $tenant, int $item)
    {
        $data = $request->validate(['operation' => ['required', 'in:claim,release,assign,snooze,resolve,reopen'], 'version' => ['required', 'string', 'size:64'],
            'request_id' => ['required', 'uuid'], 'confirmed' => ['accepted'], 'assignee_id' => ['sometimes', 'integer', 'min:1'],
            'until' => ['sometimes', 'date'], 'resolution' => ['sometimes', 'string', 'max:500']]);
        $options = array_intersect_key($data, array_flip(['assignee_id', 'until', 'resolution']));

        return response()->json(app(AiSharedAttention::class)->transition($this->workspace($request), $request->user(), $item,
            $data['operation'], $data['version'], $data['request_id'], $options))->header('Cache-Control', 'no-store, private');
    }

    public function webUpdate(Request $request, int $item)
    {
        return $this->update($request, '', $item);
    }
}
