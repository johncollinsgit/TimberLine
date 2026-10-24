<?php

namespace App\Proposals;

use Symfony\Component\HttpKernel\Exception\HttpException;

/** Constructed only for pure pre-write rejection after fresh grant and absent-receipt checks. */
final class ReviewerWorkspaceRejection extends HttpException
{
    public function __construct(int $status, public readonly string $requestId, public readonly string $digest, public readonly string $workspaceId)
    {
        parent::__construct($status);
    }

    public function envelope(): array
    {
        return ['error' => $this->getStatusCode() === 409 ? 'workspace_conflict' : 'workspace_invalid',
            'automatic_retry' => false, 'definitive_not_applied' => true,
            'request_id' => $this->requestId, 'digest' => $this->digest, 'workspace_id' => $this->workspaceId];
    }
}
