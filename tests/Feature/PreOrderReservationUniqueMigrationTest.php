<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PreOrderReservationUniqueMigrationTest extends TestCase
{
    private const MIGRATION = 'database/migrations/2026_10_22_133453_add_unique_reservation_id_to_pre_orders_table.php';

    private const UNIQUE_INDEX = 'pre_orders_reservation_id_unique';

    #[DataProvider('supportingIndexStates')]
    public function test_mysql_migration_can_roll_back_and_migrate_again_without_losing_foreign_key_support(bool $existingIndex): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('This regression requires MySQL foreign-key index enforcement.');
        }

        // Never migrate or roll back the configured development or shared testing database.
        $database = 'testing_preorder_rollback_'.bin2hex(random_bytes(8));
        $configuration = DB::connection()->getConfig();
        $admin = DB::build(array_replace($configuration, [
            'name' => 'preorder_migration_admin', 'url' => null, 'database' => null,
        ]));
        config(['database.connections.preorder_migration_test' => array_replace($configuration, [
            'name' => 'preorder_migration_test', 'url' => null, 'database' => $database,
        ])]);

        try {
            $admin->statement('CREATE DATABASE `'.$database.'`');
            $connection = DB::connection('preorder_migration_test');
            $this->assertSame($database, $connection->selectOne('SELECT DATABASE() AS name')->name);
            $schema = $connection->getSchemaBuilder();

            $baseline = array_values(array_filter(
                glob(database_path('migrations/*.php')),
                fn (string $path): bool => basename($path) !== basename(self::MIGRATION)
            ));
            $this->runMigrationCommand('migrate', ['--path' => $baseline, '--realpath' => true]);
            $this->runMigrationCommand('migrate', ['--path' => [self::MIGRATION]]);

            // Reproduce deployed schemas where InnoDB kept only the unique supporting index.
            foreach ($schema->getIndexes('pre_orders') as $index) {
                if ($index['name'] !== self::UNIQUE_INDEX && ($index['columns'][0] ?? null) === 'reservation_id') {
                    $schema->table('pre_orders', fn (Blueprint $table) => $table->dropIndex($index['name']));
                }
            }

            if ($existingIndex) {
                $schema->table('pre_orders', fn (Blueprint $table) => $table->index('reservation_id', 'pre_orders_reservation_id_index'));
            }

            $ownerId = $connection->table('users')->insertGetId([
                'name' => 'Migration Owner', 'email' => 'migration@example.test', 'password' => 'unused',
            ]);
            $restaurantId = $connection->table('restaurants')->insertGetId([
                'name' => 'Migration Restaurant', 'slug' => 'migration-test', 'address' => 'Test Address',
            ]);
            $reservationId = $connection->table('reservations')->insertGetId([
                'restaurant_id' => $restaurantId, 'owner_user_id' => $ownerId,
                'code' => 'GT-MIGRATION', 'reserved_at' => '2026-10-23 12:00:00', 'guest_count' => 1,
            ]);
            $orderId = $connection->table('pre_orders')->insertGetId(['reservation_id' => $reservationId]);
            $order = $connection->table('pre_orders')->find($orderId);

            for ($cycle = 0; $cycle < 2; $cycle++) {
                $this->assertTrue($schema->hasIndex('pre_orders', self::UNIQUE_INDEX, 'unique'));
                $this->assertInsertFails($connection, $reservationId, 1062);

                $this->runMigrationCommand('migrate:rollback', ['--path' => [self::MIGRATION], '--step' => 1]);

                $this->assertFalse($schema->hasIndex('pre_orders', self::UNIQUE_INDEX));
                $this->assertTrue(collect($schema->getIndexes('pre_orders'))->contains(
                    fn (array $index): bool => ($index['columns'][0] ?? null) === 'reservation_id'
                ));
                $this->assertCount(1, $schema->getForeignKeys('pre_orders'));
                $this->assertEquals($order, $connection->table('pre_orders')->find($orderId));
                $this->assertInsertFails($connection, $reservationId + 1, 1452);

                // Rollback removes uniqueness, while the foreign key remains enforced.
                $duplicateId = $connection->table('pre_orders')->insertGetId(['reservation_id' => $reservationId]);
                $connection->table('pre_orders')->where('id', $duplicateId)->delete();
                $this->runMigrationCommand('migrate', ['--path' => [self::MIGRATION]]);
            }

            $this->assertInsertFails($connection, $reservationId, 1062);
            $connection->table('reservations')->where('id', $reservationId)->delete();
            $this->assertSame(0, $connection->table('pre_orders')->count());

            // Also exercise rollback and installation of the complete migration chain.
            $this->runMigrationCommand('migrate:rollback');
            $this->runMigrationCommand('migrate:rollback');
            $this->assertFalse($schema->hasTable('pre_orders'));
            $this->runMigrationCommand('migrate');
            $this->assertTrue($schema->hasIndex('pre_orders', self::UNIQUE_INDEX, 'unique'));
            $this->assertCount(1, $schema->getForeignKeys('pre_orders'));
        } finally {
            DB::purge('preorder_migration_test');
            // This name is generated locally, never read from connection configuration or user input.
            $admin->statement('DROP DATABASE IF EXISTS `'.$database.'`');
            DB::purge('preorder_migration_admin');
        }
    }

    public static function supportingIndexStates(): array
    {
        return [
            'unique index is the only foreign-key support' => [false],
            'a separate supporting index already exists' => [true],
        ];
    }

    private function runMigrationCommand(string $command, array $options = []): void
    {
        $this->assertSame(0, Artisan::call($command, array_merge($options, [
            '--database' => 'preorder_migration_test', '--force' => true,
        ])), Artisan::output());
    }

    private function assertInsertFails(Connection $connection, int $reservationId, int $errorCode): void
    {
        try {
            $connection->table('pre_orders')->insert(['reservation_id' => $reservationId]);
            $this->fail('The database constraint must reject this pre-order.');
        } catch (QueryException $exception) {
            $this->assertSame($errorCode, $exception->errorInfo[1]);
        }
    }
}
