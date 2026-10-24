<?php

require_once __DIR__.'/reviewer-workspace-mysql-worker.php';

/** Strict disposable fixture only; config admission here is synthetic and never a production executor. */
final class ReviewerWorkspaceScheduledMySqlWorker
{
    public static function configure(string $digest): void
    {
        config(['service_proposals.reviewer_workspace.scheduled_cleanup' => ['enabled' => true, 'pins_sha256' => $digest],
            'service_proposals.campus_review.enabled' => false, 'service_proposals.reviewer_workspace.enabled' => false,
            'service_proposals.reviewer_workspace.writes_enabled' => false, 'service_proposals.reviewer_workspace.initialization_enabled' => false,
            'service_proposals.reviewer_workspace.maintenance_enabled' => false, 'service_proposals.reviewer_workspace.financial_enabled' => false,
            'service_proposals.reviewer_workspace.collaboration.enabled' => false, 'service_proposals.reviewer_workspace.collaboration.comments_write_enabled' => false]);
    }

    public static function main(): void
    {
        try {
            ReviewerWorkspaceMySqlWorker::environment();
            $app = ReviewerWorkspaceMySqlWorker::boot();
            ReviewerWorkspaceMySqlWorker::bindOwner();
            $raw = stream_get_contents(STDIN, 2049);
            if (strlen($raw) > 2048) {
                throw new RuntimeException('Bounded fixture input required.');
            }
            $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
            \App\Proposals\ReviewerWorkspaceSchema::exact($input, ['worker', 'digest']);
            if (! is_string($input['worker']) || ! preg_match('/^[a-z0-9-]{1,40}$/D', $input['worker'])
                || ! \App\Proposals\ReviewerWorkspaceSchema::hash($input['digest'])) {
                throw new RuntimeException('Closed synthetic approval required.');
            }
            self::configure($input['digest']);
            $connection = (int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
            $barrier = getenv('ENTERPRISE_RUNTIME_ROOT').'/barrier';
            file_put_contents($barrier.'/ready-'.$input['worker'], (string) $connection);
            $until = microtime(true) + 15;
            while (! is_file($barrier.'/go-'.$input['worker']) && microtime(true) < $until) {
                usleep(10000);
            }
            if (! is_file($barrier.'/go-'.$input['worker'])) {
                throw new RuntimeException('Bounded barrier timeout.');
            }
            file_put_contents($barrier.'/attempt-'.$input['worker'], 'attempt');
            $out = app(\App\Proposals\ReviewerWorkspaceScheduledCleanup::class)->run();
            if ((int) \Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id !== $connection) {
                throw new RuntimeException('Observed connection changed.');
            }
            \Illuminate\Support\Facades\Http::assertNothingSent();
            echo json_encode(['connection_id' => $connection, 'result' => $out], JSON_THROW_ON_ERROR);
            $app->flush();
        } catch (Throwable) {
            fwrite(STDOUT, '{"error":"disposable_scheduled_worker_refused"}');
            exit(2);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    ReviewerWorkspaceScheduledMySqlWorker::main();
}
