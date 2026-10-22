<?php

namespace Tests\Support;

use App\SoftwareSupport\Transport\CampusAuthorization;
use App\SoftwareSupport\Transport\CampusOutbox;
use App\SoftwareSupport\Transport\DepartmentBinding;
use App\SoftwareSupport\Transport\OwnerInbox;
use Illuminate\Support\Str;
use PDO;

/** These SQLite adapters exist only in tests; no native tenant/user table or app DB is read. */
final class FixtureCampusStore implements CampusAuthorization, CampusOutbox
{
    public string $sessionPrincipal = 'campus-user:7';

    public function __construct(public PDO $db)
    {
        $db->exec('CREATE TABLE IF NOT EXISTS memberships (principal TEXT, department TEXT, active INTEGER, PRIMARY KEY (principal,department))');
        $db->exec('CREATE TABLE IF NOT EXISTS outbox (replay_key TEXT PRIMARY KEY, command TEXT, digest TEXT, state TEXT)');
    }

    public function identity(string $department, string $operation): array
    {
        $query = $this->db->prepare('SELECT active FROM memberships WHERE principal=? AND department=?');
        $query->execute([$this->sessionPrincipal, $department]);
        abort_unless($query->fetchColumn() === 1, 403);

        return ['issuer' => 'fixture-campus', 'campus_workspace' => 'campus-workspace:7', 'department' => $department, 'principal' => $this->sessionPrincipal];
    }

    public function freeze(array $command): array
    {
        $key = self::key($command);
        $json = json_encode($command, JSON_THROW_ON_ERROR);
        $digest = hash('sha256', $json);
        $this->db->beginTransaction();
        try {
            $query = $this->db->prepare('SELECT command,digest FROM outbox WHERE replay_key=?');
            $query->execute([$key]);
            if ($existing = $query->fetch(PDO::FETCH_ASSOC)) {
                abort_unless(hash_equals($existing['digest'], $digest), 409);
                $command = json_decode($existing['command'], true, flags: JSON_THROW_ON_ERROR);
            } else {
                $this->db->prepare('INSERT INTO outbox VALUES (?,?,?,?)')->execute([$key, $json, $digest, 'pending']);
            }
            $this->db->commit();

            return $command;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }

    public function uncertain(array $command): void
    {
        $this->db->prepare('UPDATE outbox SET state=? WHERE replay_key=?')->execute(['uncertain', self::key($command)]);
    }

    public function acknowledge(array $command): void
    {
        $this->db->prepare('UPDATE outbox SET state=? WHERE replay_key=?')->execute(['acknowledged', self::key($command)]);
    }

    public static function key(array $command): string
    {
        return hash('sha256', json_encode([$command['issuer'], $command['campus_workspace'], $command['principal'], $command['request_key']], JSON_THROW_ON_ERROR));
    }
}

final class FixtureOwnerInbox implements OwnerInbox
{
    public function __construct(public PDO $db)
    {
        $db->exec('CREATE TABLE IF NOT EXISTS tickets (reference TEXT PRIMARY KEY, owner_workspace TEXT, client_reference TEXT, department TEXT, subject TEXT, priority TEXT, messages TEXT, origin TEXT)');
        $db->exec('CREATE TABLE IF NOT EXISTS receipts (replay_key TEXT PRIMARY KEY, digest TEXT, result TEXT)');
    }

    public function execute(DepartmentBinding $binding, array $command, string $commandHash): array
    {
        $this->db->beginTransaction();
        try {
            $key = FixtureCampusStore::key($command);
            $query = $this->db->prepare('SELECT digest,result FROM receipts WHERE replay_key=?');
            $query->execute([$key]);
            if ($command['operation'] !== 'read' && ($existing = $query->fetch(PDO::FETCH_ASSOC))) {
                abort_unless(hash_equals($existing['digest'], $commandHash), 409);
                $result = json_decode($existing['result'], true, flags: JSON_THROW_ON_ERROR);
            } else {
                $payload = $command['payload'];
                if ($command['operation'] === 'submit') {
                    $reference = (string) Str::uuid();
                    $this->db->prepare('INSERT INTO tickets VALUES (?,?,?,?,?,?,?,?)')->execute([
                        $reference, $binding->ownerWorkspace, $binding->clientReference, $binding->ownerDepartment,
                        $payload['subject'], $payload['priority'], json_encode([$payload['body']]),
                        json_encode([$command['issuer'], $command['campus_workspace'], $command['principal']]),
                    ]);
                    $result = ['reference' => $reference, 'status' => 'open', 'receipt' => 'saved', 'human_response' => false];
                } else {
                    $query = $this->db->prepare('SELECT * FROM tickets WHERE reference=? AND owner_workspace=? AND client_reference=? AND department=?');
                    $query->execute([$payload['reference'], $binding->ownerWorkspace, $binding->clientReference, $binding->ownerDepartment]);
                    $ticket = $query->fetch(PDO::FETCH_ASSOC);
                    abort_unless($ticket, 404);
                    $messages = json_decode($ticket['messages'], true);
                    if ($command['operation'] === 'reply') {
                        $messages[] = $payload['body'];
                        $this->db->prepare('UPDATE tickets SET messages=? WHERE reference=?')->execute([json_encode($messages), $payload['reference']]);
                        $result = ['reference' => $payload['reference'], 'receipt' => 'reply_saved', 'human_response' => false];
                    } else {
                        $result = ['reference' => $payload['reference'], 'subject' => $ticket['subject'], 'priority' => $ticket['priority'], 'status' => 'open', 'messages' => $messages];
                    }
                }
                if ($command['operation'] !== 'read') {
                    $this->db->prepare('INSERT INTO receipts VALUES (?,?,?)')->execute([$key, $commandHash, json_encode($result)]);
                }
            }
            $this->db->commit();

            return $result;
        } catch (\Throwable $error) {
            $this->db->rollBack();
            throw $error;
        }
    }
}
