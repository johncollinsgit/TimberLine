<?php

namespace App\Services\CampusFacilities;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;

class DemoVendors
{
    public function attention(array $state): array
    {
        $settings = $state['operations']['settings'];
        $today = CarbonImmutable::now($settings['timezone'])->startOfDay();
        $vendors = array_column($state['operations']['vendors'], 'name', 'id');
        $rows = [];
        foreach ($state['operations']['certificates'] as $certificate) {
            $days = $certificate['expiresOn'] ? (int) round($today->diffInDays(CarbonImmutable::parse($certificate['expiresOn'], $settings['timezone']), false)) : null;
            $status = $days === null ? 'Needs review' : ($days < 0 ? 'Expired' : ($days <= $settings['certificateWarningDays'] ? 'Due soon' : 'Current'));
            $rows[] = [...$certificate, 'vendorName' => $vendors[$certificate['vendorId']] ?? 'Sample vendor', 'daysRemaining' => $days, 'attention' => $status];
        }

        return $rows;
    }

    public function validFor(array $state, string $vendorId): bool
    {
        $today = now($state['operations']['settings']['timezone'])->toDateString();
        foreach ($state['operations']['settings']['certificateTypes'] as $type) {
            if (! collect($state['operations']['certificates'])->contains(fn ($row) => $row['vendorId'] === $vendorId && $row['type'] === $type && $row['reviewedBy'] !== null && $row['expiresOn'] !== null && $row['expiresOn'] >= $today)) {
                return false;
            }
        }

        return true;
    }

    public function handle(array &$state, array $actor, string $action, string $id, int $revision, array $data): array
    {
        $records = app(DemoRecords::class);
        $settings = $state['operations']['settings'];
        if ($action === 'vendor.save') {
            $data = $records->data($data, ['name' => 'required|string|min:3|max:120', 'trade' => 'required|string|min:3|max:120', 'rating' => 'required|integer|min:1|max:5', 'notes' => 'nullable|string|max:1200', 'contact' => 'required|string|min:3|max:150', 'contactName' => 'sometimes|nullable|string|max:90', 'phone' => 'sometimes|nullable|string|max:50', 'coordinatorId' => 'sometimes|nullable|string|max:50', 'status' => 'sometimes|required|in:Active,On hold,Archived', 'projectIds' => 'sometimes|present|array|max:100', 'projectIds.*' => 'required|string|max:70|distinct']);
            if (! empty($data['coordinatorId'])) {
                $records->person($state, $data['coordinatorId']);
            }
            foreach ($data['projectIds'] ?? [] as $projectId) {
                $records->row($state, 'projects', $projectId);
            }
            $defaults = $revision === 0 ? ['status' => 'Active', 'contactName' => '', 'phone' => '', 'coordinatorId' => null, 'projectIds' => []] : [];
            $records->save($state, 'vendors', $id, $revision, [...$defaults, ...$data, 'notes' => $data['notes'] ?? '']);

            return ['message' => 'Contractor company saved.', 'resourceId' => $id];
        }
        $old = $revision ? $records->existing($state, 'certificates', $id, $revision) : null;
        if ($action === 'certificate.propose') {
            $data = $records->data($data, ['vendorId' => 'required|string|max:70', 'type' => ['required', Rule::in($settings['certificateTypes'])], 'expiresOn' => 'required|date_format:Y-m-d', 'reference' => 'required|string|min:5|max:500']);
            $records->row($state, 'vendors', $data['vendorId']);
            abort_unless($data['expiresOn'] > now($settings['timezone'])->toDateString(), 422, 'Use a future expiration date for the proposed replacement.');
            if ($old) {
                abort_unless($old['vendorId'] === $data['vendorId'] && $old['type'] === $data['type'], 422, 'Keep this certificate attached to its original vendor and type.');
                abort_if($old['pending'], 422, 'Review the pending replacement before proposing another.');
            }
            $row = $old ?? ['vendorId' => $data['vendorId'], 'type' => $data['type'], 'expiresOn' => null, 'reference' => '', 'reviewedBy' => null];
            $row['pending'] = ['expiresOn' => $data['expiresOn'], 'reference' => $data['reference'], 'proposedBy' => $actor['name'], 'at' => now()->toIso8601String()];
            $records->save($state, 'certificates', $id, $revision, $row);

            return ['message' => 'Replacement proposed. The accepted certificate stays in effect until review.', 'resourceId' => $id];
        }
        abort_unless($old, 404, 'Choose an existing certificate.');
        $records->requireReviewer($actor, $settings['certificateReviewerRole']);
        if ($action === 'certificate.review') {
            $data = $records->data($data, ['decision' => 'required|in:approve,reject', 'reason' => 'required|string|min:5|max:1000']);
            abort_unless($old['pending'], 422, 'Propose a replacement before reviewing it.');
            abort_if(count($old['reviews'] ?? []) >= 50, 422, 'This certificate has reached its demo review limit.');
            $reviews = $old['reviews'] ?? [];
            $reviews[] = [...$data, 'at' => now()->toIso8601String(), 'by' => $actor['name'], 'replacement' => $old['pending']];
            if ($data['decision'] === 'approve') {
                abort_unless($old['pending']['expiresOn'] > now($settings['timezone'])->toDateString(), 422, 'This proposed replacement has already expired.');
                $old['expiresOn'] = $old['pending']['expiresOn'];
                $old['reference'] = $old['pending']['reference'];
                $old['reviewedBy'] = $actor['id'];
            }
            $records->save($state, 'certificates', $id, $revision, [...$old, 'pending' => null, 'reviews' => $reviews]);

            return ['message' => $data['decision'] === 'approve' ? 'Replacement accepted in the demo register.' : 'Replacement rejected; the previously accepted record is retained.', 'resourceId' => $id];
        }
        if ($action === 'certificate.remind') {
            $records->data($data, []);
            $attention = collect($this->attention($state))->firstWhere('id', $id);
            abort_unless($attention['attention'] !== 'Current', 422, 'This certificate is outside the configured warning window.');
            $noticeId = 'notice-'.substr(hash('sha256', $id.':'.$old['revision'].':'.now($settings['timezone'])->toDateString()), 0, 24);
            $exists = collect($state['operations']['notifications'])->contains('id', $noticeId);
            if (! $exists) {
                $records->save($state, 'notifications', $noticeId, 0, ['title' => 'Demo certificate reminder', 'text' => $attention['vendorName'].' · '.$old['type'].' · '.$attention['attention'], 'certificateId' => $id, 'audienceRole' => $settings['certificateReviewerRole'], 'readAt' => null, 'readBy' => null]);
            }

            return ['message' => $exists ? 'This reminder is already in the demo inbox for today.' : 'Reminder added to the demo inbox. No email or text was sent.', 'resourceId' => $noticeId];
        }
        abort(422, 'Unsupported vendor action.');
    }
}
