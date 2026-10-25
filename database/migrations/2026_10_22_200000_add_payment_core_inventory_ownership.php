<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const OWNER = 'payment_core_operation_id';

    private const CHECK = 'website_reservation_owner_ck';

    private const EXPRESSION = '(website_order_id IS NOT NULL AND payment_core_operation_id IS NULL AND expires_at IS NOT NULL) OR (website_order_id IS NULL AND payment_core_operation_id IS NOT NULL AND expires_at IS NULL)';

    public function up(): void
    {
        $collation = DB::getDriverName() === 'mysql' ? 'utf8mb4_bin' : 'binary';
        foreach (['inventory_snapshot', 'inventory_hash', 'inventory_state', 'inventory_payment_state'] as $column) {
            if (! Schema::hasColumn('payment_core_operations', $column)) {
                Schema::table('payment_core_operations', function (Blueprint $table) use ($column, $collation): void {
                    if ($column === 'inventory_snapshot') {
                        $table->longText($column)->nullable();
                    } else {
                        $table->string($column, $column === 'inventory_hash' ? 64 : 16)->collation($collation)->nullable();
                    }
                });
            }
        }
        if (! Schema::hasColumn('website_inventory_reservations', self::OWNER)) {
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->string(self::OWNER, 32)->collation($collation)->nullable());
        }
        // Changing nullability may require dropping the existing FK first.
        // Reject incompatible existing semantics before any ownership repair.
        $this->assertForeign('website_reservations_order_fk', 'website_order_id', 'website_orders', 'cascade');
        $this->assertForeign('website_reservation_payment_fk', self::OWNER, 'payment_core_operations', 'restrict');
        $this->assertRows();
        $columns = collect(Schema::getColumns('website_inventory_reservations'))->keyBy('name');
        if (! $columns['website_order_id']['nullable']) {
            if ($this->foreign('website_reservations_order_fk')) {
                Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->dropForeign('website_reservations_order_fk'));
            }
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->unsignedBigInteger('website_order_id')->nullable()->change());
        }
        if (! $columns['expires_at']['nullable']) {
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->timestamp('expires_at')->nullable()->change());
        }
        if (! $this->foreign('website_reservations_order_fk')) {
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->foreign('website_order_id', 'website_reservations_order_fk')->references('id')->on('website_orders')->cascadeOnDelete());
        }
        if (! $this->foreign('website_reservation_payment_fk')) {
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->foreign(self::OWNER, 'website_reservation_payment_fk')->references('id')->on('payment_core_operations')->restrictOnDelete());
        }
        $index = collect(Schema::getIndexes('website_inventory_reservations'))->firstWhere('name', 'website_reservation_payment_variant_uq');
        if ($index && ($index['columns'] !== [self::OWNER, 'website_product_variant_id'] || ! $index['unique'])) {
            throw new RuntimeException('Retained inventory owner index has incompatible semantics.');
        }
        if (! $index) {
            Schema::table('website_inventory_reservations', fn (Blueprint $table) => $table->unique([self::OWNER, 'website_product_variant_id'], 'website_reservation_payment_variant_uq'));
        }
        if (DB::getDriverName() === 'mysql') {
            $saved = DB::selectOne('SELECT cc.CHECK_CLAUSE AS expression, tc.ENFORCED AS enforced FROM information_schema.TABLE_CONSTRAINTS tc JOIN information_schema.CHECK_CONSTRAINTS cc ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ? AND tc.CONSTRAINT_NAME = ?', ['website_inventory_reservations', self::CHECK]);
            if ($saved && ($this->normalize($saved->expression) !== $this->normalize(self::EXPRESSION) || strtoupper($saved->enforced) !== 'YES')) {
                throw new RuntimeException('Retained inventory owner check has incompatible semantics.');
            }
            if (! $saved) {
                DB::statement('ALTER TABLE website_inventory_reservations ADD CONSTRAINT '.self::CHECK.' CHECK ('.self::EXPRESSION.')');
            }
        }
    }

    public function down(): void
    {
        if (DB::table('website_inventory_reservations')->whereNotNull(self::OWNER)->exists()
            || DB::table('payment_core_operations')->whereNotNull('inventory_hash')->exists()) {
            throw new RuntimeException('Inventory ownership rollback must retain durable payment evidence.');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE website_inventory_reservations DROP CHECK '.self::CHECK);
        }
        Schema::table('website_inventory_reservations', function (Blueprint $table): void {
            $table->dropForeign('website_reservation_payment_fk');
            $table->dropUnique('website_reservation_payment_variant_uq');
            $table->dropColumn(self::OWNER);
            $table->unsignedBigInteger('website_order_id')->nullable(false)->change();
            $table->timestamp('expires_at')->nullable(false)->change();
        });
        Schema::table('payment_core_operations', fn (Blueprint $table) => $table->dropColumn(['inventory_snapshot', 'inventory_hash', 'inventory_state', 'inventory_payment_state']));
    }

    private function assertRows(): void
    {
        $holds = DB::table('website_inventory_reservations');
        if ((clone $holds)->whereRaw('NOT ('.self::EXPRESSION.')')->exists()
            || (clone $holds)->select(self::OWNER, 'website_product_variant_id')->whereNotNull(self::OWNER)->groupBy(self::OWNER, 'website_product_variant_id')->havingRaw('COUNT(*) > 1')->exists()
            || DB::table('website_inventory_reservations as h')->leftJoin('payment_core_operations as o', 'h.'.self::OWNER, '=', 'o.id')
                ->leftJoin('website_product_variants as v', 'h.website_product_variant_id', '=', 'v.id')->whereNotNull('h.'.self::OWNER)
                ->where(fn ($q) => $q->whereNull('o.id')->orWhereNull('v.id')->orWhereColumn('h.tenant_id', '<>', 'o.tenant_id')
                    ->orWhereColumn('h.tenant_id', '<>', 'v.tenant_id')->orWhere('o.kind', '<>', 'payment')->orWhere('h.quantity', '<', 1))->exists()) {
            throw new RuntimeException('Retained inventory owner rows are incompatible; evidence was not rewritten.');
        }
        $ops = DB::table('payment_core_operations');
        if ((clone $ops)->whereNull('inventory_hash')->where(fn ($q) => $q->whereNotNull('inventory_snapshot')->orWhereNotNull('inventory_state')->orWhereNotNull('inventory_payment_state'))->exists()
            || (clone $ops)->whereNotNull('inventory_hash')->where(fn ($q) => $q->whereNull('inventory_snapshot')->orWhereNull('inventory_state')->orWhere('kind', '<>', 'payment')
                ->orWhereNotIn('inventory_state', ['held', 'committed', 'released', 'review']))->exists()) {
            throw new RuntimeException('Retained inventory snapshot is incomplete; evidence was not rewritten.');
        }
    }

    private function foreign(string $name): ?array
    {
        return collect(Schema::getForeignKeys('website_inventory_reservations'))->firstWhere('name', $name);
    }

    private function assertForeign(string $name, string $column, string $target, string $delete): void
    {
        $fk = $this->foreign($name);
        if ($fk && ($fk['columns'] !== [$column] || $fk['foreign_table'] !== $target || $fk['foreign_columns'] !== ['id']
            || ! in_array(strtolower($fk['on_update']), ['restrict', 'no action'], true)
            || ! in_array(strtolower($fk['on_delete']), $delete === 'cascade' ? ['cascade'] : ['restrict', 'no action'], true))) {
            throw new RuntimeException('Retained inventory owner foreign key has incompatible semantics.');
        }
    }

    /** Parse the finite ownership predicate grammar. Discarding parentheses
     * would incorrectly accept a differently grouped AND/OR expression.
     */
    private function normalize(string $expression): string
    {
        $tokens = preg_split('/\\s+|(?=[()])|(?<=[()])/', str_replace('`', '', strtolower(trim($expression))), -1, PREG_SPLIT_NO_EMPTY);
        $offset = 0;
        $tree = $this->parseOr($tokens, $offset);
        if ($offset !== count($tokens)) {
            throw new RuntimeException('Retained inventory owner check has incompatible semantics.');
        }

        return json_encode($tree, JSON_THROW_ON_ERROR);
    }

    private function parseOr(array $tokens, int &$offset): array
    {
        $tree = $this->parseAnd($tokens, $offset);
        while (($tokens[$offset] ?? null) === 'or') {
            $offset++;
            $tree = $this->booleanNode('or', $tree, $this->parseAnd($tokens, $offset));
        }

        return $tree;
    }

    private function parseAnd(array $tokens, int &$offset): array
    {
        $tree = $this->parseAtom($tokens, $offset);
        while (($tokens[$offset] ?? null) === 'and') {
            $offset++;
            $tree = $this->booleanNode('and', $tree, $this->parseAtom($tokens, $offset));
        }

        return $tree;
    }

    private function parseAtom(array $tokens, int &$offset): array
    {
        if (($tokens[$offset] ?? null) === '(') {
            $offset++;
            $tree = $this->parseOr($tokens, $offset);
            if (($tokens[$offset++] ?? null) !== ')') {
                throw new RuntimeException('Retained inventory owner check has incompatible semantics.');
            }

            return $tree;
        }
        $column = $tokens[$offset++] ?? null;
        if (! in_array($column, ['website_order_id', 'payment_core_operation_id', 'expires_at'], true)
            || ($tokens[$offset++] ?? null) !== 'is') {
            throw new RuntimeException('Retained inventory owner check has incompatible semantics.');
        }
        $not = ($tokens[$offset] ?? null) === 'not';
        if ($not) {
            $offset++;
        }
        if (($tokens[$offset++] ?? null) !== 'null') {
            throw new RuntimeException('Retained inventory owner check has incompatible semantics.');
        }

        return ['is_null', $column, $not];
    }

    private function booleanNode(string $operator, array $left, array $right): array
    {
        return [$operator, ...($left[0] === $operator ? array_slice($left, 1) : [$left]),
            ...($right[0] === $operator ? array_slice($right, 1) : [$right])];
    }
};
