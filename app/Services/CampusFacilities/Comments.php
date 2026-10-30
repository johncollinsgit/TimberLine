<?php

namespace App\Services\CampusFacilities;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;

class Comments
{
    public function post(DemoStore $store, Request $request, array $input): array
    {
        $data = Validator::make($input['data'] ?? [], ['id' => 'required|uuid', 'text' => 'present|nullable|string|max:3000', 'photos' => 'present|array|max:5', 'photos.*.id' => 'required|uuid', 'mentions' => 'present|array|max:5', 'mentions.*' => 'required|uuid|distinct', 'notify' => 'required|boolean'])->validate();
        $person = app(Tickets::class)->actor($input);
        $prepared = $store->access(function (&$state) use ($request, $input, $data, $person) {
            $id = $input['id'] ?? '';
            abort_unless(is_string($id) && isset($state['tickets'][$id]), 404, 'Ticket not found.');
            $ticket = $state['tickets'][$id];
            abort_unless(app(Tickets::class)->canSee($ticket, $person), 403, 'Choose the demo person responsible for this ticket.');
            $viewer = app(Participants::class)->current($state, $request);
            $author = ['id' => $viewer['id'] ?? 'demo:'.$person['id'], 'name' => $viewer['name'] ?? $person['name']];
            $receiptKey = $id.':'.$data['id'];
            $digest = hash('sha256', json_encode([$data, $author['id']], JSON_THROW_ON_ERROR));
            if (isset($state['comment_receipts'][$receiptKey])) {
                abort_unless(hash_equals($state['comment_receipts'][$receiptKey], $digest), 409, 'This comment identifier belongs to an earlier draft. Review the saved conversation before posting again.');

                return ['ticket' => $ticket, 'deliveries' => []];
            }
            abort_if(count($ticket['comments'] ?? []) >= 100 || count($ticket['events']) >= 200, 422, 'This demo ticket has reached its conversation limit.');
            $photos = [];
            foreach ($data['photos'] as $photo) {
                abort_unless(isset($state['attachments'][$photo['id']]), 422, 'Upload the photo before posting.');
                $photos[$photo['id']] = $state['attachments'][$photo['id']];
            }
            $text = trim($data['text'] ?? '');
            abort_unless($text !== '' || count($photos), 422, 'Write a comment or add a photo.');
            $mentioned = [];
            foreach ($data['mentions'] as $memberId) {
                $member = $state['participants'][$memberId] ?? null;
                abort_unless($member && $member['verified'] && $member['enabled'], 422, 'A selected mention recipient is no longer available. Refresh the mention menu.');
                $mentioned[] = ['id' => $member['id'], 'name' => $member['name']];
            }
            $deliveries = [];
            $status = 'not_requested';
            if ($data['notify'] && count($mentioned)) {
                $day = now()->format('Y-m-d');
                if (app(DemoMail::class)->configured() && ($state['mail_usage'][$day] ?? 0) + count($mentioned) <= 50) {
                    // Claim every intended send before any HTTP. Replays never redispatch.
                    foreach ($mentioned as $member) {
                        $deliveries[] = ['recipient' => Crypt::decryptString($state['participants'][$member['id']]['email']), 'member' => $member['id']];
                    }
                    $state['mail_usage'][$day] = ($state['mail_usage'][$day] ?? 0) + count($mentioned);
                    $status = 'pending';
                } else {
                    $status = 'unavailable';
                }
            }
            $comment = ['id' => $data['id'], 'at' => now()->toIso8601String(), 'author' => $author, 'text' => $text, 'photos' => array_values($photos), 'mentions' => $mentioned, 'notification' => $status];
            $ticket['comments'][] = $comment;
            $ticket['events'][] = app(Tickets::class)->event($author['name'], 'Comment added');
            $ticket['revision']++;
            $ticket['updatedAt'] = $comment['at'];
            $state['tickets'][$id] = $ticket;
            $state['comment_receipts'][$receiptKey] = $digest;

            return ['ticket' => $ticket, 'comment' => $comment, 'deliveries' => $deliveries];
        });
        if (! count($prepared['deliveries'])) {
            return $prepared['ticket'];
        }
        $ticket = $prepared['ticket'];
        $comment = $prepared['comment'];
        $link = 'https://'.config('tenancy.domains.canonical.landlord_host').'/platform/demos/lees-mcrae/?ticket='.rawurlencode($ticket['id']);
        $statuses = [];
        foreach ($prepared['deliveries'] as $delivery) {
            $statuses[] = app(DemoMail::class)->deliver($delivery['recipient'], 'You were mentioned on '.$ticket['id'], $comment['author']['name'].' mentioned you on '.$ticket['id'].".\n\n".$ticket['title']."\n".$ticket['fields']['location'].' · '.$ticket['fields']['room']."\nStatus: ".$ticket['status']."\n\nComment:\n".$comment['text']."\n\nOpen the ticket to see its photos and conversation. Manage your email preferences with the person icon in the ticket composer.", $link, 'Open ticket');
        }
        $status = in_array('unknown', $statuses, true) ? 'unknown' : (in_array('failed', $statuses, true) ? 'failed' : 'accepted');

        return $store->access(function (&$state) use ($ticket, $comment, $status) {
            // A reset can retire the original ticket while delivery was in progress.
            if (! isset($state['tickets'][$ticket['id']])) {
                return $ticket;
            }
            foreach ($state['tickets'][$ticket['id']]['comments'] ?? [] as $index => $row) {
                if ($row['id'] === $comment['id']) {
                    $state['tickets'][$ticket['id']]['comments'][$index]['notification'] = $status;
                }
            }

            return $state['tickets'][$ticket['id']];
        });
    }
}
