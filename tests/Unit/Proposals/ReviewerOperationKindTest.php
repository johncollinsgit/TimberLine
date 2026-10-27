<?php

namespace Tests\Unit\Proposals;

use App\Proposals\ReviewerAiReceipts;
use App\Proposals\ReviewerWorkspaceAttachmentsStore;
use App\Proposals\ReviewerWorkspaceCollaborationStore;
use App\Proposals\ReviewerWorkspaceDownloadsStore;
use App\Proposals\ReviewerWorkspaceImagesStore;
use App\Proposals\ReviewerWorkspaceScheduledCleanup;
use App\Proposals\ReviewerWorkspaceStore;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\TestCase;

final class ReviewerOperationKindTest extends TestCase
{
    public static function operationKindLimit(): int
    {
        $source = file_get_contents(dirname(__DIR__, 3).'/database/migrations/2026_10_20_010000_create_team_operations_tables.php');
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source);
        $finder = new NodeFinder;
        $creates = $finder->find($ast, fn ($node) => $node instanceof Node\Expr\StaticCall
            && $node->class instanceof Node\Name && $node->class->toString() === 'Schema'
            && $node->name instanceof Node\Identifier && $node->name->toString() === 'create'
            && ($node->args[0]->value instanceof Node\Scalar\String_)
            && $node->args[0]->value->value === 'team_operation_records');
        if (count($creates) !== 1) {
            throw new \RuntimeException('Expected exact released operation-record migration.');
        }
        $columns = $finder->find(array_values($creates)[0]->args[1]->value,
            fn ($node) => $node instanceof Node\Expr\MethodCall
                && $node->name instanceof Node\Identifier && $node->name->toString() === 'string'
                && ($node->args[0]->value instanceof Node\Scalar\String_) && $node->args[0]->value->value === 'kind');
        if (count($columns) !== 1 || ! (array_values($columns)[0]->args[1]->value instanceof Node\Scalar\Int_)) {
            throw new \RuntimeException('Expected exact literal kind-column bound.');
        }

        return array_values($columns)[0]->args[1]->value->value;
    }

    public function test_reviewer_record_kinds_fit_the_released_schema_and_remain_unique(): void
    {
        $limit = self::operationKindLimit();
        $this->assertSame(24, $limit, 'Released schema must not be widened to accommodate a new source kind.');
        $kinds = [ReviewerAiReceipts::KIND, ReviewerWorkspaceStore::KIND, ReviewerWorkspaceStore::COMMAND,
            ReviewerWorkspaceCollaborationStore::THREAD, ReviewerWorkspaceCollaborationStore::RECEIPT,
            ReviewerWorkspaceAttachmentsStore::KIND, ReviewerWorkspaceImagesStore::KIND,
            ReviewerWorkspaceDownloadsStore::KIND, ReviewerWorkspaceScheduledCleanup::CURSOR];
        $this->assertCount(count($kinds), array_unique($kinds));
        foreach ($kinds as $kind) {
            $this->assertMatchesRegularExpression('/^ent_[a-z_]+$/D', $kind);
            $this->assertLessThanOrEqual($limit, strlen($kind), $kind);
        }
    }
}
