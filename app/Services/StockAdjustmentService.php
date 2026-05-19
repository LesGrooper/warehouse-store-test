<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Adjustment;
use App\Models\Product;
use Illuminate\Support\Facades\DB;

class StockAdjustmentService
{
    /**
     * Adjust product stock dengan pessimistic lock untuk mencegah race condition
     * Stock disimpan di pivot table product_warehouse.quantity
     *
     * @param int $productId
     * @param int $quantity
     * @param string $type ('in' atau 'out')
     * @param int $warehouseId
     * @param string $reason
     * @return Adjustment
     * @throws \Exception
     */
    public function adjustStock(
        int $productId,
        int $quantity,
        string $type,
        int $warehouseId,
        string $reason
    ): Adjustment {
        return DB::transaction(function () use ($productId, $quantity, $type, $warehouseId, $reason) {
            // Lock pivot table product_warehouse untuk mencegah race condition
            $currentStock = DB::table('product_warehouse')
                ->lockForUpdate()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();

            if (!$currentStock) {
                throw new \Exception("Product not found in this warehouse");
            }

            // Validasi stock jika type adalah 'out'
            if ($type === 'out' && $currentStock->quantity < $quantity) {
                throw new \Exception("Insufficient stock. Available: {$currentStock->quantity}, Requested: {$quantity}");
            }

            // Calculate new quantity
            $newQuantity = $type === 'in'
                ? $currentStock->quantity + $quantity
                : $currentStock->quantity - $quantity;

            // Update stock di pivot table
            DB::table('product_warehouse')
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => $newQuantity]);

            // Create adjustment record
            $adjustment = Adjustment::create([
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->date(),
                'reason' => $reason,
                'created_by' => auth()->id(),
            ]);

            // Record in adjustment_product pivot table
            $adjustment->products()->attach($productId, [
                'quantity' => $quantity,
                'type' => $type,
            ]);

            return $adjustment;
        });
    }

    /**
     * Transfer stock antar product dalam warehouse yang sama dengan double lock
     *
     * @param int $fromProductId
     * @param int $toProductId
     * @param int $quantity
     * @param int $warehouseId
     * @return array
     * @throws \Exception
     */
    public function transferStock(
        int $fromProductId,
        int $toProductId,
        int $quantity,
        int $warehouseId
    ): array {
        return DB::transaction(function () use ($fromProductId, $toProductId, $quantity, $warehouseId) {
            // Lock both pivot table rows, ordered by product_id to prevent deadlock
            $stocks = DB::table('product_warehouse')
                ->lockForUpdate()
                ->where('warehouse_id', $warehouseId)
                ->whereIn('product_id', [$fromProductId, $toProductId])
                ->orderBy('product_id')
                ->get()
                ->keyBy('product_id');

            if ($stocks->count() < 2) {
                throw new \Exception("One or both products not found in this warehouse");
            }

            $fromStock = $stocks[$fromProductId];
            $toStock = $stocks[$toProductId];

            // Validate stock
            if ($fromStock->quantity < $quantity) {
                throw new \Exception("Insufficient stock in source product. Available: {$fromStock->quantity}");
            }

            // Update both pivot table rows
            DB::table('product_warehouse')
                ->where('product_id', $fromProductId)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => $fromStock->quantity - $quantity]);

            DB::table('product_warehouse')
                ->where('product_id', $toProductId)
                ->where('warehouse_id', $warehouseId)
                ->update(['quantity' => $toStock->quantity + $quantity]);

            // Create adjustment records for audit trail
            $adjustmentOut = Adjustment::create([
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->date(),
                'reason' => "Transfer to product {$toProductId}",
                'created_by' => auth()->id(),
            ]);

            $adjustmentOut->products()->attach($fromProductId, [
                'quantity' => $quantity,
                'type' => 'out',
            ]);

            $adjustmentIn = Adjustment::create([
                'warehouse_id' => $warehouseId,
                'adjustment_date' => now()->date(),
                'reason' => "Transfer from product {$fromProductId}",
                'created_by' => auth()->id(),
            ]);

            $adjustmentIn->products()->attach($toProductId, [
                'quantity' => $quantity,
                'type' => 'in',
            ]);

            return [
                'from_product_id' => $fromProductId,
                'to_product_id' => $toProductId,
                'quantity' => $quantity,
                'adjustment_out' => $adjustmentOut,
                'adjustment_in' => $adjustmentIn,
            ];
        });
    }

    /**
     * Bulk adjustment dengan savepoint per item
     *
     * @param array $adjustments Array of ['product_id' => int, 'quantity' => int, 'type' => string, ...]
     * @return array
     */
    public function bulkAdjust(array $adjustments): array
    {
        $results = [];
        $errors = [];

        DB::transaction(function () use ($adjustments, &$results, &$errors) {
            foreach ($adjustments as $index => $adj) {
                try {
                    // Nested transaction dengan savepoint
                    $result = DB::transaction(function () use ($adj) {
                        return $this->adjustStock(
                            $adj['product_id'],
                            $adj['quantity'],
                            $adj['type'],
                            $adj['warehouse_id'] ?? 1,
                            $adj['reason'] ?? 'Bulk adjustment'
                        );
                    });alam warehouse dengan shared lock
     *
     * @param int $productId
     * @param int $warehouseId
     * @return object|null
     */
    public function getStockWithSharedLock(int $productId, int $warehouseId)
    {
        return DB::transaction(function () use ($productId, $warehouseId) {
            return DB::table('product_warehouse')
                ->sharedLock()
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->first();
        });
    }

    /**
     * Adjust stock dengan skip locked (untuk batch processing)
     * Mencari products dengan stock rendah dan increase stock
     *
     * @param int $quantity
     * @param string $type
     * @param int $warehouseId
     * @param int $limit
     * @return array
     */
    public function adjustLowStockProducts(
        int $quantity,
        string $type,
        int $warehouseId,
        int $limit = 10
    ): array {
        return DB::transaction(function () use ($quantity, $type, $warehouseId, $limit) {
            $lowStockRecords = DB::table('product_warehouse')
                ->lockForUpdate()
                ->skipLocked() // Skip rows yang sudah di-lock process lain
                ->where('warehouse_id', $warehouseId)
                ->where('quantity', '<', 50)
                ->limit($limit)
                ->get();

            $adjusted = [];

            foreach ($lowStockRecords as $record) {
                $newQuantity = $type === 'in'
                    ? $record->quantity + $quantity
                    : max(0, $record->quantity - $quantity);

                DB::table('product_warehouse')
                    ->where('product_id', $record->product_id)
                    ->where('warehouse_id', $warehouseId)
                    ->update(['quantity' => $newQuantity]);

                $adjusted[] = [
                    'product_id' => $record->product_id,
                    'warehouse_id' => $warehouseId,
                    'old_quantity' => $record->quantity,
                    'new_quantity' => $newQuantity,
                ]
        return DB::transaction(function () use ($quantity, $type, $limit) {
            $products = Product::lockForUpdate()
                ->skipLocked() // Skip products yang sudah di-lock process lain
                ->where('stock', '<', 50)
                ->limit($limit)
                ->get();

            $adjusted = [];

            foreach ($products as $product) {
                $product->stock = $type === 'in'
                    ? $product->stock + $quantity
                    : max(0, $product->stock - $quantity);

                $product->save();
                $adjusted[] = $product;
            }

            return $adjusted;
        });
    }
}
