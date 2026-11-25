<?php

namespace App\Proposals;

use Illuminate\Http\Request;

final class LiveCopyController
{
    public function __construct(private Access $access, private LiveCopy $liveCopy) {}

    public function edit(Request $request, string $workspace, string $versionId)
    {
        $tenant = $this->access->owner($request, true);
        $version = Version::query()->where('tenant_id', $tenant->id)->findOrFail($versionId);
        abort_unless(config('service_proposals.publish_enabled') && $this->access->linkAvailable($version), 410);
        $copy = $this->liveCopy->find($version);

        return response()->view('service-proposals.live-copy', compact('tenant', 'version', 'copy'));
    }

    public function update(Request $request, string $workspace, string $versionId)
    {
        $tenant = $this->access->owner($request, true);
        $input = $request->validate([
            'revision' => ['required', 'integer', 'min:0'],
            'intro' => ['present', 'nullable', 'string', 'max:6000'],
            'heading' => ['present', 'nullable', 'required_with:body', 'string', 'max:120'],
            'body' => ['present', 'nullable', 'required_with:heading', 'string', 'max:4000'],
        ]);
        $revision = (int) $input['revision'];
        unset($input['revision']);
        $this->liveCopy->save($tenant, $versionId, $request->user()->id, $revision, $input);

        return redirect()->route('service-proposals.owner.live-copy', [$workspace, $versionId])
            ->with('status', 'Live proposal copy saved.');
    }
}
