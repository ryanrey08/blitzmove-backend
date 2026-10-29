<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\UserRole;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListTest extends TestCase
{
    use RefreshDatabase;

    public function test_amount_and_payment_terms_are_saved_and_validated(): void
    {
        $this->actingAsRole(UserRole::Dispatcher);
        $payload = [
            'customer_name' => 'Mega Plastics',
            'customer_contact' => '0917 555 1234',
            'pickup_address' => '123 Industrial Ave, Tanauan, Batangas',
            'delivery_address' => '456 Logistics St, Quezon City',
            'scheduled_pickup_at' => now()->addDay()->toIso8601String(),
            'scheduled_delivery_at' => now()->addDays(2)->toIso8601String(),
            'cargo_description' => 'Plastic drums',
            'quantity' => 20,
            'amount' => 15200,
            'payment_terms' => 'Net 30',
        ];

        $this->postJson($this->api('orders'), $payload)->assertCreated()
            ->assertJsonPath('data.amount', 15200)
            ->assertJsonPath('data.payment_terms', 'Net 30')
            ->assertJsonPath('data.is_delayed', false);

        $this->postJson($this->api('orders'), [...$payload, 'amount' => -1])->assertStatus(422)->assertJsonValidationErrors('amount');
    }

    public function test_list_filters_by_customer_destination_order_date_and_delay(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        $late = Order::factory()->create(['customer_name' => 'Mega Plastics', 'delivery_address' => '456 Logistics St, Quezon City', 'scheduled_pickup_at' => now()->subDays(2), 'scheduled_delivery_at' => now()->subDay()]);
        Order::factory()->create(['customer_name' => 'Oxychem Corp.', 'delivery_address' => 'Calamba, Laguna']);
        Order::factory()->create(['customer_name' => 'Old Co.', 'status' => OrderStatus::Delivered, 'scheduled_delivery_at' => now()->subDay(), 'created_at' => now()->subMonths(2)]);

        $this->getJson($this->api('orders?customer=Mega+Plastics'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $late->id);
        $this->getJson($this->api('orders?destination='.urlencode('Quezon City')))->assertJsonCount(1, 'data');
        $this->getJson($this->api('orders?delayed=1'))->assertJsonCount(1, 'data')->assertJsonPath('data.0.is_delayed', true);
        $this->getJson($this->api('orders?created_from='.now()->subWeek()->toDateString()))->assertJsonCount(2, 'data');

        $this->getJson($this->api('orders/filter-options'))->assertOk()
            ->assertJsonPath('data.customers', ['Mega Plastics', 'Old Co.', 'Oxychem Corp.'])
            ->assertJsonFragment(['Calamba, Laguna'])
            ->assertJsonFragment(['Quezon City']);
    }

    public function test_summary_includes_monthly_bookings_and_delayed_count(): void
    {
        $this->actingAsRole(UserRole::Viewer);
        Order::factory()->count(3)->create();
        Order::factory()->create(['created_at' => now()->startOfMonth()->subDays(3)]);
        Order::factory()->create(['scheduled_pickup_at' => now()->subDays(2), 'scheduled_delivery_at' => now()->subDay()]);

        $pending = collect($this->getJson($this->api('orders/summary'))->assertOk()->assertJsonPath('meta.delayed', 1)->json('data'))->firstWhere('status', 'pending');

        $this->assertSame(5, $pending['count']);
        $this->assertSame(4, $pending['this_month']);
        $this->assertSame(1, $pending['last_month']);
    }
}
