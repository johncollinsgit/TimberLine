<?php

namespace App\SoftwareSupport\Transport;

use App\SoftwareSupport\Service;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class TestReceiver
{
    public function __construct(private readonly string $audience, private readonly TestVerifier $verifier,
        private readonly TestSigner $responseSigner, private readonly array $bindings, private readonly OwnerInbox $inbox) {}

    public function receive(array $packet): array
    {
        abort_unless(app()->environment('testing') && config('software_support.test_transport_enabled', false), 404);
        $command = $this->verifier->verify('campus-request', $this->audience, $packet);
        Validator::make(['command' => $command], [
            'command' => ['required', 'array:version,issuer,audience,campus_workspace,department,principal,request_key,operation,payload'],
            'command.version' => ['required', Rule::in([1])],
            'command.issuer' => ['required', 'string', 'max:120'], 'command.audience' => ['required', 'string', 'max:120'],
            'command.campus_workspace' => ['required', 'string', 'max:120'], 'command.department' => ['required', 'string', 'max:120'],
            'command.principal' => ['required', 'string', 'max:120'], 'command.request_key' => ['required', 'uuid'],
            'command.operation' => ['required', 'in:submit,read,reply'], 'command.payload' => ['required', 'array'],
        ])->validate();
        $binding = collect($this->bindings)->first(fn (DepartmentBinding $candidate) => $candidate->matches($command));
        abort_unless($binding, 403);
        $rules = match ($command['operation']) {
            'submit' => ['payload' => ['array:subject,category,priority,body'], 'payload.subject' => ['required', 'string', 'max:180'],
                'payload.category' => ['required', 'in:help,bug,billing,feature,account'], 'payload.priority' => ['required', Rule::in(Service::PRIORITIES)],
                'payload.body' => ['required', 'string', 'max:8000']],
            'reply' => ['payload' => ['array:reference,body'], 'payload.reference' => ['required', 'uuid'], 'payload.body' => ['required', 'string', 'max:8000']],
            'read' => ['payload' => ['array:reference'], 'payload.reference' => ['required', 'uuid']],
        };
        Validator::make(['payload' => $command['payload']], $rules)->validate();
        $bindingDigest = hash('sha256', $packet['body']."\n".json_encode([
            $binding->ownerWorkspace, $binding->clientReference, $binding->ownerDepartment,
        ], JSON_THROW_ON_ERROR));
        $result = $this->inbox->execute($binding, $command, $bindingDigest);
        $response = ['version' => 1, 'issuer' => $this->audience, 'audience' => $command['issuer'],
            'request_hash' => hash('sha256', $packet['body']), 'result' => $result];

        return $this->responseSigner->sign('owner-response', json_encode($response, JSON_THROW_ON_ERROR))->wire();
    }
}
