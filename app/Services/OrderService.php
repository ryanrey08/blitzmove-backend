<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Exceptions\BusinessRuleException;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class OrderService
{
    public function __construct(private NumberGenerator $numbers) {}

    public function create(array $data, User $user): Order
    {
        return DB::transaction(function () use ($data, $user) {
            return Order::create([
                ...$data,
                'order_number' => $this->numbers->next(Order::class, 'order_number', 'ORD'),
                'status' => OrderStatus::Pending,
                'created_by' => $user->id,
            ]);
        });
    }

    public function update(Order $order, array $data): Order
    {
        if (in_array($order->status, [OrderStatus::Delivered, OrderStatus::Cancelled], true)) {
            throw new BusinessRuleException("A {$order->status->label()} order can no longer be edited.");
        }

        $order->update($data);

        return $order;
    }

    public function changeStatus(Order $order, OrderStatus $next, ?string $reason = null): Order
    {
        if ($next === OrderStatus::Cancelled) {
            return $this->cancel($order, $reason);
        }

        if (! $order->status->canTransitionTo($next)) {
            throw BusinessRuleException::withField(
                'status',
                "Cannot change order status from {$order->status->label()} to {$next->label()}.",
            );
        }

        $order->status = $next;
        $order->delivered_at = $next === OrderStatus::Delivered ? now() : null;

        if ($next === OrderStatus::Pending) {
            $order->cancelled_at = null;
            $order->cancellation_reason = null;
        }

        $order->save();

        return $order;
    }

    /**
     * Cancel an order that has not left the depot. Any scheduled trip carrying
     * it is cancelled too; dispatched or running trips block cancellation.
     */
    public function cancel(Order $order, ?string $reason = null): Order
    {
        if (! $order->status->canTransitionTo(OrderStatus::Cancelled)) {
            throw new BusinessRuleException("A {$order->status->label()} order cannot be cancelled.");
        }

        if ($order->trips()->whereIn('status', TripStatus::active())->exists()) {
            throw new BusinessRuleException('This order is on a dispatched trip. Cancel or complete the trip first.');
        }

        return DB::transaction(function () use ($order, $reason) {
            $order->trips()->where('status', TripStatus::Scheduled)->get()
                ->each(fn ($trip) => app(TripService::class)->cancel($trip, 'Order cancelled'));

            $order->update([
                'status' => OrderStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $order;
        });
    }

    /** Only orders that never went on the road may be removed. */
    public function delete(Order $order): void
    {
        if (! in_array($order->status, [OrderStatus::Pending, OrderStatus::Cancelled], true)) {
            throw new BusinessRuleException('Only pending or cancelled orders can be deleted. Cancel the order first.');
        }

        if ($order->trips()->whereIn('status', TripStatus::blocking())->exists()) {
            throw new BusinessRuleException('This order still has an open trip.');
        }

        $order->delete();
    }
}
