<?php

require_once __DIR__.'/reviewer-workspace-mysql-worker.php';

/** Closed disposable CI HTTP fixture only, never an application identity or runtime executor. */
final class ReviewerWorkspacePreparationMySqlWorker
{
    public static function configureHttp(): void
    {
        config(['session.driver' => 'file', 'session.files' => getenv('ENTERPRISE_RUNTIME_ROOT').'/storage/framework/sessions']);
        app('auth')->forgetGuards();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
    }

    /** Test fixture authentication through the native guard; not password-login acceptance. */
    public static function session(\App\Models\User $owner): array
    {
        self::configureHttp();
        $store = app('session')->driver();
        app()->instance('session.store', $store);
        $store->start();
        app('auth')->guard('web')->login($owner);
        $store->regenerateToken();
        $name = config('session.cookie');
        $session = ['cookie' => app('encrypter')->encrypt(
            \Illuminate\Cookie\CookieValuePrefix::create($name, app('encrypter')->getKey()).$store->getId(), false),
            'csrf' => $store->token(), 'id' => $store->getId()];
        $store->save();
        app('auth')->forgetGuards();

        return $session;
    }

    public static function configureApproval(array $approval): void
    {
        $hash = \App\Proposals\ReviewerWorkspaceSchema::digest($approval);
        $rows = \App\Proposals\ReviewerWorkspacePreparation::parseApprovals(json_encode((object) [$hash => $approval], JSON_THROW_ON_ERROR));
        if ($rows === null || $approval['collaboration_descriptor'] !== null) {
            throw new RuntimeException('Closed fictional preparation required.');
        }
        config(['service_proposals.reviewer_workspace.owner_preparation' => ['enabled' => true, 'approvals' => $rows]]);
    }

    /** The actual Kernel binds Request, route, native session, mounted guards and CSRF; no User resolver. */
    public static function http(array $session, string $action, array $body, array $approval): array
    {
        self::configureHttp();
        $form = $action === 'publish';
        $slug = 'fictional-owner-preparation-mysql';
        $path = $form ? $approval['prepared_draft_id'].'/publish' : 'reviewer-preparation/'.$action;
        $server = ['SCRIPT_NAME' => '/enterprise-owner/index.php', 'PHP_SELF' => '/enterprise-owner/index.php',
            'SCRIPT_FILENAME' => '/FICTIONAL/index.php', 'HTTPS' => 'on', 'HTTP_ORIGIN' => 'https://proposal.example.invalid',
            'HTTP_ACCEPT' => $form ? 'text/html' : 'application/json', 'CONTENT_TYPE' => $form ? 'application/x-www-form-urlencoded' : 'application/json',
            'HTTP_X_CSRF_TOKEN' => $session['csrf'], 'HTTP_SEC_FETCH_SITE' => 'same-origin'];
        if ($form) {
            $body['_token'] = $session['csrf'];
        }
        $request = \Illuminate\Http\Request::create('https://proposal.example.invalid/enterprise-owner/owner-workspaces/'.$slug.'/proposals/'.$path,
            'POST', $form ? $body : [], [config('session.cookie') => $session['cookie']], [], $server,
            $form ? null : json_encode((object) $body, JSON_THROW_ON_ERROR));
        $kernel = app(\Illuminate\Contracts\Http\Kernel::class);
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);
        $expectedRoute = $form ? 'service-proposals.owner.publish' : 'service-proposals.owner.preparation.action';
        if ($request->route()?->getName() !== $expectedRoute || ! $request->hasSession()
            || $request->session()->getId() !== $session['id'] || $request->session()->token() !== $session['csrf']) {
            throw new RuntimeException('Actual mounted native session/route changed.');
        }
        $out = ['http_status' => $response->getStatusCode(), 'route' => $expectedRoute,
            'session_sha256' => hash('sha256', $request->session()->getId()), 'owner_id' => $request->user()?->getKey()];
        if (! $form) {
            $value = json_decode($response->getContent(), true);
            if (is_array($value)) {
                $out['result'] = $value;
            }
        } elseif ($response->getStatusCode() === 302) {
            $expectedLocation = route('service-proposals.owner.edit', [$slug, $approval['prepared_draft_id']]);
            if ($response->headers->get('Location') !== $expectedLocation || $request->session()->has('errors')) {
                throw new RuntimeException('Publication was redirected to login or validation.');
            }
            $out['edit_redirect_verified'] = true;
            $version = \App\Proposals\Version::query()->where('draft_id', $approval['prepared_draft_id'])
                ->where('publish_key', hash('sha256', $body['publish_key']))->sole();
            $out['publication'] = ['id' => $version->id, 'hash' => $version->content_hash,
                'token_sha256' => hash('sha256', $version->access_result['token'])];
        }
        \Illuminate\Support\Facades\Http::assertNothingSent();

        return $out;
    }

    public static function main(): void
    {
        try {
            ReviewerWorkspaceMySqlWorker::environment(); // Before autoload/runtime/DB. Unchanged strict CI guard.
            $app = ReviewerWorkspaceMySqlWorker::boot();
            ReviewerWorkspaceMySqlWorker::bindOwner();
            $raw = stream_get_contents(STDIN, 32769);
            if (strlen($raw) > 32768) {
                throw new RuntimeException('Bounded fixture input required.');
            }
            $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            \App\Proposals\ReviewerWorkspaceSchema::exact($input, ['worker', 'action', 'session', 'approval', 'body']);
            if (! in_array($input['action'], ['apply', 'status', 'publish'], true) || ! is_string($input['worker'])
                || ! preg_match('/^[a-z0-9-]{1,40}$/D', $input['worker']) || ! is_array($input['session'])
                || ! is_array($input['body']) || ! is_array($input['approval'])) {
                throw new RuntimeException('Closed worker input required.');
            }
            \App\Proposals\ReviewerWorkspaceSchema::exact($input['session'], ['cookie', 'csrf', 'id']);
            if (! is_string($input['session']['cookie']) || strlen($input['session']['cookie']) > 4096
                || ! is_string($input['session']['csrf']) || ! preg_match('/^[A-Za-z0-9]{40}$/D', $input['session']['csrf'])
                || ! is_string($input['session']['id']) || ! preg_match('/^[A-Za-z0-9]{40}$/D', $input['session']['id'])) {
                throw new RuntimeException('Closed synthetic session required.');
            }
            self::configureApproval($input['approval']);
            $connection = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $barrier = getenv('ENTERPRISE_RUNTIME_ROOT').'/barrier';
            file_put_contents($barrier.'/ready-'.$input['worker'], (string) $connection);
            $deadline = microtime(true) + 15;
            while (! is_file($barrier.'/go-'.$input['worker']) && microtime(true) < $deadline) {
                usleep(10000);
            }
            if (! is_file($barrier.'/go-'.$input['worker'])) {
                throw new RuntimeException('Parent barrier timed out.');
            }
            file_put_contents($barrier.'/attempt-'.$input['worker'], 'attempt');
            $result = self::http($input['session'], $input['action'], $input['body'], $input['approval']);
            $actual = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            if ($actual !== $connection) {
                throw new RuntimeException('Observed operation connection changed.');
            }
            echo json_encode(['connection_id' => $connection, ...$result], JSON_THROW_ON_ERROR);
            $app->flush();
        } catch (Throwable) {
            fwrite(STDOUT, '{"error":"disposable_preparation_worker_refused"}');
            exit(2);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ReviewerWorkspacePreparationMySqlWorker::main();
}
