<?php

namespace App\Observers;

use App\Models\TeamOperationRecord;
use App\Services\Team\Search\SearchDocuments;
use App\Services\Team\Search\SearchIndex;

/** Search writes join the canonical transaction, so rollback cannot publish phantom results. */
class TeamSearchObserver
{
    public function saved(TeamOperationRecord $record): void
    {
        app(SearchDocuments::class)->indexOperation($record);
    }

    public function deleted(TeamOperationRecord $record): void
    {
        app(SearchIndex::class)->remove($record->tenant_id, 'operation', $record->id);
    }
}
