<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\TripStatus;
use App\Enums\UserRole;
use App\Models\Order;
use App\Models\Trip;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(array $overrides = []): array
    {
        return [
            'customer_name' => 'Oxychem Corp.',
            'customer_contact' => '0917 123 4567',
            'pickup_address' => '123 Logistics Ave, Manila',
            'delivery_address' => 'Calamba, Laguna',
            'scheduled_pickup_at' => now()->addDay()->setTime(8, 0)->toIso8601String(),
            'scheduled_delivery_at' => now()->addDay()->setTime(14, 0)->toIso8601String(),
            'cargo_description' => '20 drums',
            'quantity' => 20,
            'weight_kg' => 4000,
            ...$overrides,
        ];
    }

    public function test_dispatcher_can_create_an_order_with_a_generated_number(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);

        $first = $this->postJson($this->api('orders'), $this->payload())->assertCreated()
            ->assertJsonPath('data.status', 'pending')->json('data.order_number');
        $second = $this->postJson($this->api('orders'), $this->payload())->json('data.order_number');

        $this->assertSame('ORD-'.now()->year.'-0001', $first);
        $this->assertSame('ORD-'.now()->year.'-0002', $second);
    }

    public function test_order_validation(): void
    {
        $this->actingAsRole();

        $this->postJson($this->api('orders'), $this->payload([
            'customer_name' => '', 'quantity' => 0,
            'scheduled_delivery_at' => now()->toIso8601String(),
        ]))->assertStatus(422)->assertJsonValidationErrors(['customer_name', 'quantity', 'scheduled_delivery_at']);
    }

    public function test_orders_can_be_searched_filtered_sorted_and_paginated(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Order::factory()->count(12)->create();
        Order::factory()->create(['customer_name' => 'Mega Plastics', 'status' => OrderStatus::Delivered]);

        $this->getJson($this->api('orders?per_page=5'))
            ->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 13)->assertJsonPath('meta.last_page', 3);

        $this->getJson($this->api('orders?search=mega%20plastics'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.customer_name', 'Mega Plastics');
        $this->getJson($this->api('orders?status=delivered'))->assertJsonCount(1, 'data');
        $this->getJson($this->api('orders?sort=customer_name&per_page=100'))->assertOk();
    }

    public function test_order_can_be_shown_and_updated(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create();

        $this->getJson($this->api("orders/{$order->id}"))->assertOk()->assertJsonPath('data.order_number', $order->order_number);
        $this->putJson($this->api("orders/{$order->id}"), $this->payload(['customer_name' => 'ABC Chemicals']))
            ->assertOk()->assertJsonPath('data.customer_name', 'ABC Chemicals');
        $this->getJson($this->api('orders/999999'))->assertNotFound()->assertJsonPath('message', 'The requested record was not found.');
    }

    public function test_delivered_orders_cannot_be_edited_or_deleted(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create(['status' => OrderStatus::Delivered]);

        $this->putJson($this->api("orders/{$order->id}"), $this->payload())->assertStatus(422);
        $this->deleteJson($this->api("orders/{$order->id}"))->assertStatus(422);
        $this->assertNotSoftDeleted($order);
    }

    public function test_status_transitions_follow_the_workflow(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create();

        $this->patchJson($this->api("orders/{$order->id}/status"), ['status' => 'delivered'])
            ->assertStatus(422)->assertJsonValidationErrors('status');
        $this->patchJson($this->api("orders/{$order->id}/status"), ['status' => 'scheduled'])
            ->assertOk()->assertJsonPath('data.status', 'scheduled');
        $this->patchJson($this->api("orders/{$order->id}/status"), ['status' => 'cancelled', 'reason' => 'Customer request'])
            ->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Customer request');
    }

    public function test_cancelling_an_order_cancels_its_scheduled_trip_but_not_a_dispatched_one(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create(['status' => OrderStatus::Scheduled]);
        $trip = Trip::factory()->create(['order_id' => $order->id]);

        $this->patchJson($this->api("orders/{$order->id}/status"), ['status' => 'cancelled'])->assertOk();
        $this->assertSame(TripStatus::Cancelled, $trip->fresh()->status);

        $busy = Order::factory()->create(['status' => OrderStatus::Scheduled]);
        Trip::factory()->create(['order_id' => $busy->id, 'status' => TripStatus::Dispatched]);
        $this->patchJson($this->api("orders/{$busy->id}/status"), ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_pending_orders_can_be_deleted(): void
    {
        $this->actingAsRole();
        $order = Order::factory()->create();

        $this->deleteJson($this->api("orders/{$order->id}"))->assertOk();
        $this->assertSoftDeleted($order);
    }

    public function test_viewers_cannot_modify_orders(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $order = Order::factory()->create();

        $this->postJson($this->api('orders'), $this->payload())->assertForbidden();
        $this->putJson($this->api("orders/{$order->id}"), $this->payload())->assertForbidden();
        $this->deleteJson($this->api("orders/{$order->id}"))->assertForbidden();
    }
}
