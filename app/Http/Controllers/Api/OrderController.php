<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Http\Controllers\Concerns\ListsResources;
use App\Http\Controllers\Controller;
use App\Http\Requests\OrderRequest;
use App\Http\Requests\UpdateOrderStatusRequest;
use App\Http\Resources\OrderResource;
use App\Models\Order;
use App\Services\DashboardService;
use App\Services\OrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrderController extends Controller
{
    use ListsResources;

    public function __construct(private OrderService $orders) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Order::query()->with(['vehicle', 'driver']);

        $this->applySearch($query, $request, ['order_number', 'customer_name', 'customer_contact', 'pickup_address', 'delivery_address', 'cargo_description']);
        $this->applyFilters($query, $request, ['status', 'vehicle_id', 'driver_id']);

        if ($from = $request->query('pickup_from')) {
            $query->where('scheduled_pickup_at', '>=', $from);
        }
        if ($to = $request->query('pickup_to')) {
            $query->where('scheduled_pickup_at', '<=', $to.' 23:59:59');
        }
        if ($request->boolean('schedulable')) {
            $query->whereIn('status', [OrderStatus::Pending, OrderStatus::Scheduled]);
        }
        if ($request->boolean('delayed')) {
            $query->delayed();
        }
        if ($customer = $request->query('customer')) {
            $query->where('customer_name', $customer);
        }
        if ($destination = trim((string) $request->query('destination'))) {
            $query->where('delivery_address', 'like', '%'.$destination);
        }
        // Order date = when the order was booked.
        if ($from = $request->query('created_from')) {
            $query->where('created_at', '>=', $from);
        }
        if ($to = $request->query('created_to')) {
            $query->where('created_at', '<=', $to.' 23:59:59');
        }

        $this->applySort($query, $request, ['order_number', 'customer_name', 'scheduled_pickup_at', 'scheduled_delivery_at', 'status', 'amount', 'created_at']);

        return OrderResource::collection($query->paginate($this->perPage($request))->withQueryString());
    }

    /** Status counts, each with how many of those orders were booked this month and last month. */
    public function summary(DashboardService $dashboard): JsonResponse
    {
        return response()->json([
            'data' => $dashboard->distributionWithTrend(Order::query(), OrderStatus::class),
            'meta' => ['delayed' => Order::query()->delayed()->count()],
        ]);
    }

    /** Values for the Orders filters: customers, and destinations ("City, Province") taken from delivery addresses. */
    public function filterOptions(): JsonResponse
    {
        $destinations = Order::query()->distinct()->pluck('delivery_address')
            // Last two address parts, skipping street parts (those with numbers): "Lot 12 X Park, Calamba, Laguna" -> "Calamba, Laguna".
            ->map(fn (string $address) => collect(explode(',', $address))->map(fn ($part) => trim($part))
                ->filter(fn ($part) => $part !== '' && ! preg_match('/\d/', $part))->slice(-2)->implode(', '))
            ->filter()->unique()->sort()->values();

        return response()->json(['data' => [
            'customers' => Order::query()->distinct()->orderBy('customer_name')->pluck('customer_name'),
            'destinations' => $destinations,
        ]]);
    }

    public function store(OrderRequest $request): JsonResponse
    {
        $order = $this->orders->create($request->validated(), $request->user());

        return OrderResource::make($order->load(['vehicle', 'driver']))
            ->additional(['message' => "Order {$order->order_number} created."])
            ->response()->setStatusCode(201);
    }

    public function show(Order $order): OrderResource
    {
        return OrderResource::make($order->load(['vehicle', 'driver', 'creator', 'trips.vehicle', 'trips.driver']));
    }

    public function update(OrderRequest $request, Order $order): OrderResource
    {
        $this->orders->update($order, $request->validated());

        return OrderResource::make($order->fresh(['vehicle', 'driver']))->additional(['message' => 'Order updated.']);
    }

    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): OrderResource
    {
        $this->orders->changeStatus($order, OrderStatus::from($request->input('status')), $request->input('reason'));

        return OrderResource::make($order->fresh(['vehicle', 'driver']))->additional(['message' => 'Order status updated.']);
    }

    public function destroy(Order $order): JsonResponse
    {
        $this->orders->delete($order);

        return response()->json(['message' => "Order {$order->order_number} deleted."]);
    }
}
