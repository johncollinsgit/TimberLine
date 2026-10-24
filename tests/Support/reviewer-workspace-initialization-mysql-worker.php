<?php

require_once __DIR__.'/reviewer-workspace-mysql-worker.php';

/** LOCAL CI fixture only. Real synthetic DB User/session, never a production owner impersonation entry. */
final class ReviewerWorkspaceInitializationMySqlWorker
{
    public static function ownerRequest(array $session, string $sessionId, int $ownerId, string $slug): \Illuminate\Http\Request
    {
        $request = ReviewerWorkspaceMySqlWorker::request($session, $sessionId);
        $owner = \App\Models\User::query()->findOrFail($ownerId);
        $route = new \Illuminate\Routing\Route('POST', 'owner-workspaces/{workspace}/fixture', static fn () => null);
        $route->bind($request);
        $route->setParameter('workspace', $slug);
        $request->setRouteResolver(static fn () => $route);
        $request->setUserResolver(static fn () => $owner);

        return $request;
    }

    public static function main(): void
    {
        try {
            // Existing gate checks testing/explicit approval/loopback/exact CI schema
            // before any autoload, runtime read or database connection. No new bypass.
            ReviewerWorkspaceMySqlWorker::environment();
            $app = ReviewerWorkspaceMySqlWorker::boot();
            ReviewerWorkspaceMySqlWorker::bindOwner();
            $input = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
            \App\Proposals\ReviewerWorkspaceSchema::exact($input, ['worker', 'action', 'session', 'session_id', 'owner_id', 'slug', 'approval', 'body']);
            if (! in_array($input['action'], ['apply', 'status'], true) || ! is_string($input['worker'])
                || ! preg_match('/^[a-z0-9-]{1,40}$/D', $input['worker']) || ! is_int($input['owner_id'])
                || $input['owner_id'] < 1 || $input['slug'] !== 'fictional-owner-initializer-mysql'
                || ! is_array($input['session']) || ! is_string($input['session_id']) || ! is_array($input['body']) || ! is_array($input['approval'])) {
                throw new RuntimeException('Closed synthetic fixture input required.');
            }
            $approvalHash = \App\Proposals\ReviewerWorkspaceSchema::digest($input['approval']);
            $reviewed = \App\Proposals\ReviewerWorkspaceInitialization::parseApprovals(json_encode((object) [$approvalHash => $input['approval']], JSON_THROW_ON_ERROR));
            if ($reviewed === null) {
                throw new RuntimeException('Closed synthetic data approval required.');
            }
            // Explicit test fixture metadata only. Production has no worker/config-input entry.
            config(['service_proposals.reviewer_workspace.owner_initialization' => ['enabled' => true, 'approvals' => $reviewed]]);
            $request = self::ownerRequest($input['session'], $input['session_id'], $input['owner_id'], $input['slug']);
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
            try {
                $service = app(\App\Proposals\ReviewerWorkspaceInitialization::class);
                $result = ['http_status' => 200, 'result' => $service->{$input['action']}($request, $input['body'])];
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $error) {
                $result = ['http_status' => $error->getStatusCode()];
            }
            \Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['connection_id' => $connection, ...$result], JSON_THROW_ON_ERROR);
            $app->flush();
        } catch (Throwable) {
            fwrite(STDOUT, '{"error":"disposable_initializer_worker_refused"}');
            exit(2);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ReviewerWorkspaceInitializationMySqlWorker::main();
}
