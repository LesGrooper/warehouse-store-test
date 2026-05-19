<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreAdjustmentRequest;
use App\Models\Adjustment;
use App\Services\StockAdjustmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Contoh integrasi StockAdjustmentService dengan locking dan transaction
 * 
 * File ini menunjukkan cara menggunakan pessimistic lock dan DB transaction
 * di dalam controller untuk mencegah race condition
 */
class AdjustmentControllerWithLocking extends Controller
{
    public function __construct(private StockAdjustmentService $stockService) {}

    /**
     * Store adjustment dengan pessimistic lock dan transaction
     * 
     * This method demonstrates:
     * 1. Using pessimistic locking (lockForUpdate)
     * 2. Database transactions for data consistency
     * 3. Proper error handling
     */
    public function store(StoreAdjustmentRequest $request): JsonResponse
    {
        try {
            // Service automatically uses DB::transaction() dan lockForUpdate()
            $adjustment = $this->stockService->adjustStock(
                $request->product_id,
                $request->quantity,
                $request->type,
                $request->warehouse_id,
                $request->reason
            );

            return response()->json([
                'message' => 'Adjustment created successfully',
                'data' => $adjustment->load(['product', 'warehouse', 'creator'])
            ], 201);

        } catch (\Exception $e) {
            // Transaction automatically rolled back by Laravel
            return response()->json([
                'message' => 'Failed to create adjustment',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Transfer stock antar product dengan double pessimistic lock
     * 
     * POST /api/adjustments/transfer
     * {
     *   "from_product_id": 1,
     *   "to_product_id": 2,
     *   "quantity": 10,
     *   "warehouse_id": 1
     * }
     */
    public function transfer(StoreAdjustmentRequest $request): JsonResponse
    {
        try {
            Gate::authorize('create', Adjustment::class);

            $result = $this->stockService->transferStock(
                $request->from_product_id,
                $request->to_product_id,
                $request->quantity,
                $request->warehouse_id
            );

            return response()->json([
                'message' => 'Stock transferred successfully',
                'data' => $result
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Transfer failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }

    /**
     * Bulk adjustment untuk multiple products
     * 
     * POST /api/adjustments/bulk
     * [
     *   {
     *     "product_id": 1,
     *     "quantity": 5,
     *     "type": "out",
     *     "warehouse_id": 1,
     *     "reason": "Damage"
     *   },
     *   {
     *     "product_id": 2,
     *     "quantity": 10,
     *     "type": "in",
     *     "warehouse_id": 1,
     *     "reason": "Receiving"
     *   }
     * ]
     */
    public function bulkStore(StoreAdjustmentRequest $request): JsonResponse
    {
        try {
            Gate::authorize('create', Adjustment::class);

            $result = $this->stockService->bulkAdjust($request->adjustments ?? []);

            return response()->json([
                'message' => 'Bulk adjustments completed',
                'data' => $result,
                'success_count' => count($result['success']),
                'failed_count' => count($result['failed'])
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Bulk adjustment failed',
                'error' => $e->getMessage()
            ], 422);
        }
    }
}
