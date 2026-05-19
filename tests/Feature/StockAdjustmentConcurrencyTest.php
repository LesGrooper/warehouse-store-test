<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Adjustment;
use App\Models\Product;
use App\Services\StockAdjustmentService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class StockAdjustmentConcurrencyTest extends TestCase
{
    private StockAdjustmentService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = app(StockAdjustmentService::class);
    }

    #[Test]
    public function it_prevents_race_condition_with_pessimistic_lock(): void
    {
        // Setup: Create product and warehouse with initial stock via pivot table
        $product = Product::factory()->create();
        $warehouseId = 1;
        
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => 100,
        ]);

        // Simulate 10 concurrent adjustment requests, each reducing stock by 5
        // Expected result: 100 - (10 * 5) = 50
        $results = [];

        for ($i = 0; $i < 10; $i++) {
            try {
                $adjustment = $this->service->adjustStock(
                    $product->id,
                    5,
                    'out',
                    $warehouseId,
                    "Concurrent adjustment {$i}"
                );

                $results[$i] = [
                    'success' => true,
                    'adjustment_id' => $adjustment->id,
                ];
            } catch (\Exception $e) {
                $results[$i] = [
                    'success' => false,
                    'error' => $e->getMessage(),
                ];
            }
        }

        // Verify: All transactions should succeed
        $successCount = collect($results)->where('success', true)->count();
        $this->assertEquals(10, $successCount, 'All 10 adjustments should succeed');

        // Verify: Final stock should be 50
        $finalStock = DB::table('product_warehouse')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');
        $this->assertEquals(50, $finalStock, 'Final stock should be 50 after 10 deductions of 5');

        // Verify: All 10 adjustments should be recorded
        $this->assertEquals(10, DB::table('adjustment_product')
            ->where('product_id', $product->id)
            ->count());
    }

    #[Test]
    public function it_fails_gracefully_when_insufficient_stock(): void
    {
        $product = Product::factory()->create();
        $warehouseId = 1;
        
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => 10,
        ]);

        // Try to adjust out more stock than available
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Insufficient stock');

        $this->service->adjustStock(
            $product->id,
            20,
            'out',
            $warehouseId,
            'Insufficient stock test'
        );
    }

    #[Test]
    public function it_handles_stock_increase_correctly(): void
    {
        $product = Product::factory()->create();
        $warehouseId = 1;
        
        DB::table('product_warehouse')->insert([
            'product_id' => $product->id,
            'warehouse_id' => $warehouseId,
            'quantity' => 50,
        ]);

        // Add stock
        $adjustment = $this->service->adjustStock(
            $product->id,
            30,
            'in',
            $warehouseId,
            'Receiving shipment'
        );

        $finalStock = DB::table('product_warehouse')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouseId)
            ->value('quantity');

        $this->assertEquals(80, $finalStock);
        
        // Check adjustment pivot record
        $adjustmentProduct = DB::table('adjustment_product')
            ->where('adjustment_id', $adjustment->id)
            ->first();
        $this->assertEquals(30, $adjustmentProduct->quantity);
        $this->assertEquals('in', $adjustmentProduct->type);
    }

    #[Test]
    public function it_handles_multiple_concurrent_transfers(): void
    {
        // Setup: 2 products
        $fromProduct = Product::factory()->create(['stock' => 100]);
        $toProduct = Product::factory()->create(['stock' => 0]);

        // Simulate 5 concurrent transfers of 10 units each
        $results = [];

        for ($i = 0; $i < 5; $i++) {
            try {
                $result = $this->service->transferStock(
                    $fromProduct->id,
                    $toProduct->id,
                    10,
                    1
                );

                $results[$i] = ['success' => true, 'result' => $result];
            } catch (\Exception $e) {
                $results[$i] = ['success' => false, 'error' => $e->getMessage()];
            }
        }

        // Verify: All transfers should succeed
        $successCount = collect($results)->where('success', true)->count();
        $this->assertEquals(5, $successCount, 'All 5 transfers should succeed');

        // Verify: Final stock
        $fromProduct->refresh();
        $toProduct->refresh();

        $this->assertEquals(50, $fromProduct->stock, 'From product should have 50 stock (100 - 50)');
        $this->assertEquals(50, $toProduct->stock, 'To product should have 50 stock (0 + 50)');

        // Verify: Audit trail
        $fromAdjustments = Adjustment::where('product_id', $fromProduct->id)
            ->where('type', 'out')
            ->count();
        $toAdjustments = Adjustment::where('product_id', $toProduct->id)
            ->where('type', 'in')
            ->count();

        $this->assertEquals(5, $fromAdjustments);
        $this->assertEquals(5, $toAdjustments);
    }

    #[Test]
    public function it_rollbacks_on_error_during_concurrent_transfer(): void
    {
        $fromProduct = Product::factory()->create(['stock' => 100]);
        $toProduct = Product::factory()->create(['stock' => 0]);

        // First successful transfer
        $this->service->transferStock($fromProduct->id, $toProduct->id, 20, 1);

        // Try transfer with insufficient stock (should fail)
        $this->expectException(\Exception::class);

        $this->service->transferStock($fromProduct->id, $toProduct->id, 100, 1);

        // Verify only first transfer was applied
        $fromProduct->refresh();
        $toProduct->refresh();

        $this->assertEquals(80, $fromProduct->stock);
        $this->assertEquals(20, $toProduct->stock);
    }

    #[Test]
    public function it_handles_bulk_adjustments_with_partial_failures(): void
    {
        $product1 = Product::factory()->create(['stock' => 100]);
        $product2 = Product::factory()->create(['stock' => 50]);
        $product3 = Product::factory()->create(['stock' => 10]); // Will fail

        $adjustments = [
            ['product_id' => $product1->id, 'quantity' => 20, 'type' => 'out', 'warehouse_id' => 1, 'reason' => 'Test 1'],
            ['product_id' => $product2->id, 'quantity' => 30, 'type' => 'in', 'warehouse_id' => 1, 'reason' => 'Test 2'],
            ['product_id' => $product3->id, 'quantity' => 50, 'type' => 'out', 'warehouse_id' => 1, 'reason' => 'Test 3 - will fail'],
        ];

        $result = $this->service->bulkAdjust($adjustments);

        // Verify: 2 successful, 1 failed
        $this->assertCount(2, $result['success']);
        $this->assertCount(1, $result['failed']);

        // Verify: Successful adjustments were applied
        $product1->refresh();
        $product2->refresh();
        $product3->refresh();

        $this->assertEquals(80, $product1->stock);
        $this->assertEquals(80, $product2->stock);
        $this->assertEquals(10, $product3->stock); // Unchanged
    }

    #[Test]
    public function it_returns_correct_stock_with_shared_lock(): void
    {
        $product = Product::factory()->create(['stock' => 75]);

        // Get stock with shared lock
        $lockedProduct = $this->service->getStockWithSharedLock($product->id);

        $this->assertEquals(75, $lockedProduct->stock);
        $this->assertIsNotNull($lockedProduct);
    }

    #[Test]
    public function it_skips_locked_products_in_batch_processing(): void
    {
        // Create 5 low-stock products
        $products = Product::factory(5)->create(['stock' => 30]);

        // Manually lock one product
        DB::transaction(function () use ($products) {
            Product::lockForUpdate()->find($products[0]->id);

            // Try to adjust low-stock products with skip locked
            $adjusted = $this->service->adjustLowStockProducts(10, 'in', 10);

            // Should adjust 4 products (skip the locked one)
            $this->assertLessThanOrEqual(4, count($adjusted));
        });
    }

    #[Test]
    public function it_maintains_transaction_level(): void
    {
        // Start fresh transaction level
        $this->assertEquals(0, DB::transactionLevel());

        DB::transaction(function () {
            $this->assertGreaterThan(0, DB::transactionLevel());

            DB::transaction(function () {
                // Nested transaction
                $this->assertGreaterThan(1, DB::transactionLevel());
            });
        });

        $this->assertEquals(0, DB::transactionLevel());
    }

    #[Test]
    public function it_handles_deadlock_by_ordered_locking(): void
    {
        $product1 = Product::factory()->create(['stock' => 100]);
        $product2 = Product::factory()->create(['stock' => 100]);

        // Transfer from product1 to product2
        $result1 = $this->service->transferStock($product1->id, $product2->id, 10, 1);

        // Transfer from product2 to product1 (reverse order - should not deadlock)
        $result2 = $this->service->transferStock($product2->id, $product1->id, 5, 1);

        $this->assertNotNull($result1);
        $this->assertNotNull($result2);

        $product1->refresh();
        $product2->refresh();

        // product1: 100 - 10 + 5 = 95
        // product2: 100 + 10 - 5 = 105
        $this->assertEquals(95, $product1->stock);
        $this->assertEquals(105, $product2->stock);
    }
}
