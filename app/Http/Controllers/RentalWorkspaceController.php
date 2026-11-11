<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Rentals\RentalAccess;
use App\Services\Rentals\RentalFiles;
use App\Services\Rentals\RentalRepository;
use Illuminate\Http\Request;

class RentalWorkspaceController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(RentalAccess::class)->context($tenant, $request->user());

        return $tenant;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        return response()->view('rentals.app', ['tenant' => $tenant, 'bootstrap' => [
            'baseUrl' => url('/rentals'), 'tenant' => $tenant->slug, 'workspaceName' => $tenant->name, 'userId' => (string) $request->user()->id,
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function properties(Request $request, RentalRepository $repository)
    {
        return response()->json($repository->properties($this->tenant($request), $request->user()))->header('Cache-Control', 'private, no-store');
    }

    public function create(Request $request, RentalRepository $repository)
    {
        return response()->json($repository->create($this->tenant($request), $request->user(), $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function data(Request $request, string $property, RentalRepository $repository)
    {
        return response()->json($repository->read($this->tenant($request), $request->user(), $property))->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request, string $property, RentalRepository $repository)
    {
        return response()->json($repository->execute($this->tenant($request), $request->user(), $property, $request->all()))->header('Cache-Control', 'private, no-store');
    }

    public function receipt(Request $request, string $property, string $requestKey, RentalRepository $repository)
    {
        $tenant = $this->tenant($request);
        $state = $repository->read($tenant, $request->user(), $property);
        $applied = \Illuminate\Support\Facades\DB::table('rental_events')->where('tenant_id', $tenant->id)->where('property_id', $property)
            ->where('actor_id', $request->user()->id)->where('request_key', $requestKey)->exists();

        return response()->json(['applied' => $applied, 'state' => $state])->header('Cache-Control', 'private, no-store');
    }

    public function upload(Request $request, string $property, RentalFiles $files)
    {
        $tenant = $this->tenant($request);
        $request->validate(['file' => 'required|file|max:5120']);

        return response()->json($files->upload($tenant, $request->user(), $property, $request->file('file'), $request->except('file')))->header('Cache-Control', 'private, no-store');
    }

    public function file(Request $request, string $property, string $file, RentalFiles $files)
    {
        return $files->download($this->tenant($request), $request->user(), $property, $file);
    }
}
