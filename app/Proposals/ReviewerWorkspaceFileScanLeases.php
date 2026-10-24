<?php

namespace App\Proposals;

use App\Models\TeamOperationRecord;
use Illuminate\Support\Facades\DB;

/** Pure bounded child-lease check under the existing tenant transaction; no feature activation or blob IO. */
final class ReviewerWorkspaceFileScanLeases
{
    public function __construct(private ReviewerWorkspaceStore $workspace, private ReviewerWorkspaceAttachmentsStore $files,
        private ReviewerWorkspaceImagesStore $images, private ReviewerWorkspaceDownloadsStore $downloads) {}

    public function active(TeamOperationRecord $upload): bool
    {
        abort_unless(DB::transactionLevel() > 0, 503);
        $context = $this->workspace->context(false);
        $this->files->validate($upload);
        abort_unless((int) $upload->tenant_id === (int) $context['tenant']->id && $upload->area_id === $context['area_id']
            && $upload->data['binding']['context_hash'] === $context['context_hash'], 503);
        foreach ([['kind' => ReviewerWorkspaceImagesStore::KIND, 'key' => 'image_scan', 'feature' => 'images', 'store' => $this->images],
            ['kind' => ReviewerWorkspaceDownloadsStore::KIND, 'key' => 'pdf_scan', 'feature' => 'downloads', 'store' => $this->downloads]] as $type) {
            $key = ReviewerWorkspaceSchema::digest([$type['key'] => $upload->id, 'version' => $upload->data['binding']['version_id']]);
            $row = TeamOperationRecord::query()->where(['tenant_id' => $context['tenant']->id, 'area_id' => $context['area_id'],
                'kind' => $type['kind'], 'source_key' => $key])->lockForUpdate()->first();
            if ($row === null) {
                continue;
            }
            $type['store']->validate($row);
            $binding = $row->data['binding'];
            unset($binding[$type['feature']]);
            $payload = $row->data['payload'];
            abort_unless($binding === $upload->data['binding'] && $row->data['workspace_id'] === $upload->data['workspace_id']
                && ReviewerWorkspaceSchema::sameTarget($row->data['target'], $upload->data['target'])
                && $payload['upload_id'] === $upload->id && $payload['original_sha256'] === $upload->data['payload']['sha256']
                && $payload['size'] === $upload->data['payload']['size'] && $payload['mime'] === $upload->data['payload']['mime'], 503);
            if ($payload['claim'] !== null && now()->lt($payload['claim']['until'])) {
                return true;
            }
        }

        return false;
    }
}
