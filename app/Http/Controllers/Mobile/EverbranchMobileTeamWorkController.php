<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TeamFieldLinkController;
use App\Http\Controllers\TeamFileController;
use App\Http\Controllers\TeamWorkController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** Transport adapter only. Existing Field Service task/channel APIs retain their contracts. */
class EverbranchMobileTeamWorkController extends Controller
{
    public function __construct(private TeamWorkController $work, private TeamFileController $files, private TeamFieldLinkController $field) {}

    private function version(JsonResponse $response): JsonResponse
    {
        $response->setData(['contract_version' => 1] + $response->getData(true));

        return $response;
    }

    private function idempotency(Request $request, string $field = 'idempotency_key'): void
    {
        $key = $request->header('Idempotency-Key');
        abort_unless(is_string($key) && Str::isUuid($key), 422, 'A UUID Idempotency-Key header is required.');
        abort_if($request->exists($field) && $request->input($field) !== $key, 422, 'The request key must match the Idempotency-Key header.');
        $request->merge([$field => $key]);
    }

    public function data(Request $request, string $tenant)
    {
        return $this->version($this->work->data($request));
    }

    public function overview(Request $request, string $tenant, string $id)
    {
        return $this->version($this->work->overview($request, $id));
    }

    public function project(Request $request, string $tenant, string $id)
    {
        return $this->version($this->work->project($request, $id));
    }

    public function task(Request $request, string $tenant, string $id)
    {
        return $this->version($this->work->task($request, $id));
    }

    public function template(Request $request, string $tenant, string $id, string $template)
    {
        return $this->version($this->work->template($request, $id, $template));
    }

    public function inbox(Request $request, string $tenant)
    {
        return $this->version($this->work->inbox($request));
    }

    public function updateInbox(Request $request, string $tenant, string $id)
    {
        return $this->version($this->work->updateInbox($request, $id));
    }

    public function command(Request $request, string $tenant)
    {
        $this->idempotency($request);

        return $this->version($this->work->command($request));
    }

    public function taskFiles(Request $request, string $tenant, string $task)
    {
        return $this->version($this->files->index($request, $task));
    }

    public function beginFile(Request $request, string $tenant, string $task)
    {
        $this->idempotency($request, 'client_key');

        return $this->version($this->files->begin($request, $task));
    }

    public function chunkFile(Request $request, string $tenant, string $upload, string $index)
    {
        return $this->version($this->files->chunk($request, $upload, $index));
    }

    public function completeFile(Request $request, string $tenant, string $upload)
    {
        return $this->version($this->files->complete($request, $upload));
    }

    public function cancelFile(Request $request, string $tenant, string $upload)
    {
        return $this->version($this->files->cancel($request, $upload));
    }

    public function downloadFile(Request $request, string $tenant, string $upload)
    {
        return $this->files->download($request, $upload);
    }

    public function linkedJobs(Request $request, string $tenant, string $id)
    {
        return $this->version($this->field->jobs($request, $id));
    }

    public function linkedTasks(Request $request, string $tenant, string $job)
    {
        return $this->version($this->field->tasks($request, $job));
    }

    public function linkJob(Request $request, string $tenant, string $id, string $job)
    {
        $this->idempotency($request);

        return $this->version($this->field->link($request, $id, $job));
    }
}
