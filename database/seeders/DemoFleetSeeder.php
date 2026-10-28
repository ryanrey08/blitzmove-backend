<?php

namespace Database\Seeders;

use App\Enums\DriverDocumentType;
use App\Enums\DriverStatus;
use App\Enums\FuelType;
use App\Enums\LicenseType;
use App\Enums\OrderStatus;
use App\Enums\StopStatus;
use App\Enums\StopType;
use App\Enums\TripStatus;
use App\Enums\VehicleDocumentType;
use App\Enums\VehicleStatus;
use App\Enums\VehicleType;
use App\Models\DispatchAssignment;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\FuelRecord;
use App\Models\Order;
use App\Models\Trip;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Sample operating data for local development and demos: drivers, vehicles,
 * orders across every status, trips with stops and dispatch history, fuel
 * logs and document metadata. It does not create GPS positions; those only
 * come from a telematics integration.
 */
class DemoFleetSeeder extends Seeder
{
    private const CUSTOMERS = [
        ['Oxychem Corp.', '0917 801 2234', '123 Logistics Ave, Port Area, Manila'],
        ['ABC Chemicals', '0918 334 5521', 'Km 18 South Luzon Expressway, Muntinlupa'],
        ['XYZ Industrial', '0920 778 1100', 'Lot 4 Light Industry Park, Cabuyao, Laguna'],
        ['Mega Plastics', '0917 552 9001', '88 Mercedes Ave, Pasig City'],
        ['Sunrise Trading', '0939 210 4455', '25 Del Monte Ave, Quezon City'],
        ['Northpoint Foods', '0905 662 7788', 'Bldg 3 FTI Complex, Taguig City'],
    ];

    private const DESTINATIONS = [
        ['Calamba, Laguna', 'Lot 12 Calamba Premiere Industrial Park, Calamba, Laguna'],
        ['Batangas City', 'Batangas Port Zone, Sta. Clara, Batangas City'],
        ['Tanauan, Batangas', 'First Industrial Township, Tanauan, Batangas'],
        ['Cavite', 'Cavite Economic Zone, Rosario, Cavite'],
        ['Antipolo, Rizal', 'Marcos Hwy, Mayamot, Antipolo, Rizal'],
        ['Makati City', '6750 Ayala Ave, Makati City'],
        ['Quezon City', 'Mindanao Ave, Novaliches, Quezon City'],
        ['San Fernando, Pampanga', 'Jose Abad Santos Ave, San Fernando, Pampanga'],
    ];

    private const CARGO = [
        ['Industrial chemicals (drums)', 20, 4000],
        ['Plastic resin (bags)', 120, 3000],
        ['Packaged food products', 80, 1800],
        ['Construction materials', 40, 6500],
        ['Electronics (boxed)', 60, 900],
        ['Beverage cases', 200, 5200],
    ];

    public function run(): void
    {
        if (Vehicle::withTrashed()->exists() || Order::withTrashed()->exists()) {
            $this->command?->info('Fleet or order data already exists; skipping demo data.');

            return;
        }

        mt_srand(2026);
        $admin = User::where('role', 'admin')->first();

        $drivers = $this->drivers();
        $vehicles = $this->vehicles($drivers);

        $this->operations($vehicles, $drivers, $admin);
        $this->fuel($vehicles, $admin);
        $this->documents($vehicles, $drivers, $admin);
    }

    /** @return Collection<int, Driver> */
    private function drivers(): Collection
    {
        $rows = [
            ['Juan Cruz', 'N01-23-456789', DriverStatus::Available, '+2 years'],
            ['Mark Reyes', 'N02-21-987654', DriverStatus::Available, '+1 year'],
            ['Luis Garcia', 'N03-20-123456', DriverStatus::Available, '+20 days'],
            ['Antonio Dela Paz', 'N04-19-654321', DriverStatus::OnLeave, '+1 year'],
            ['Carl Lim', 'N05-22-987123', DriverStatus::Available, '+3 years'],
            ['Kevin Villanueva', 'N06-21-321987', DriverStatus::Available, '+2 years'],
            ['Daniel Cortez', 'N07-20-456123', DriverStatus::Available, '+18 months'],
            ['Erwin Mendoza', 'N08-18-789654', DriverStatus::Suspended, '+6 months'],
            ['Rogelio Santos', 'N09-19-852369', DriverStatus::Available, '+2 years', LicenseType::NonProfessional],
            ['Ramon Torres', 'N10-21-963258', DriverStatus::Available, '+2 years'],
            ['Ramon Santos', 'N11-23-456790', DriverStatus::Available, '+4 years'],
            ['Paolo Navarro', 'N12-22-741852', DriverStatus::Available, '+2 years'],
        ];

        return collect($rows)->values()->map(function (array $row, int $i) {
            [$name, $license, $status, $expiry] = $row;
            $slug = strtolower(str_replace(' ', '.', $name));

            return Driver::create([
                'employee_code' => sprintf('DRV-%04d', $i + 1),
                'full_name' => $name,
                'phone' => sprintf('09%02d %03d %04d', 17 + $i % 5, 100 + $i * 37, 1000 + $i * 911),
                'email' => "{$slug}@blitzmove.example",
                'address' => self::DESTINATIONS[$i % count(self::DESTINATIONS)][0],
                'license_number' => $license,
                'license_type' => $row[4] ?? LicenseType::Professional,
                'license_expiry' => Carbon::parse($expiry)->toDateString(),
                'date_hired' => now()->subMonths(6 + $i * 3)->toDateString(),
                'status' => $status,
            ]);
        });
    }

    /** @return Collection<int, Vehicle> */
    private function vehicles(Collection $drivers): Collection
    {
        $rows = [
            ['GHI-9012', VehicleType::ClosedVan, 'Mitsubishi Fuso', 'Canter', 2022, 4500, VehicleStatus::Available],
            ['DEF-5678', VehicleType::WingVan, 'Hino', '500 Series', 2021, 10000, VehicleStatus::Available],
            ['MNO-7890', VehicleType::ClosedVan, 'Hino', '300 Series', 2023, 4000, VehicleStatus::Available],
            ['JKL-3456', VehicleType::CargoTruck, 'Isuzu', 'NPR', 2020, 5000, VehicleStatus::Available],
            ['STU-4455', VehicleType::WingVan, 'Mitsubishi Fuso', 'Fighter', 2022, 9000, VehicleStatus::Available],
            ['VVX-7788', VehicleType::RefrigeratedTruck, 'Hino', '500 Series', 2024, 8000, VehicleStatus::Available],
            ['YZA-9901', VehicleType::Tanker, 'UD Trucks', 'Quon', 2021, 15000, VehicleStatus::Available],
            ['BCD-2233', VehicleType::CargoTruck, 'Isuzu', 'Elf', 2019, 3500, VehicleStatus::Maintenance],
            ['ABC-1234', VehicleType::CargoTruck, 'Isuzu', 'FVM 240', 2023, 15000, VehicleStatus::Available],
            ['PQR-1122', VehicleType::Flatbed, 'Isuzu', 'Forward', 2020, 12000, VehicleStatus::Available],
            ['LMN-3344', VehicleType::ClosedVan, 'Mitsubishi', 'L300', 2018, 1500, VehicleStatus::Inactive],
            ['WXY-5566', VehicleType::ClosedVan, 'Toyota', 'Hiace Cargo', 2024, 1200, VehicleStatus::Available],
        ];

        $assignable = $drivers->filter(fn (Driver $d) => $d->status === DriverStatus::Available)->values();

        return collect($rows)->values()->map(function (array $row, int $i) use ($assignable) {
            [$plate, $type, $make, $model, $year, $capacity, $status] = $row;

            return Vehicle::create([
                'plate_number' => $plate,
                'vehicle_type' => $type,
                'make' => $make,
                'model' => $model,
                'year' => $year,
                'capacity_kg' => $capacity,
                'fuel_type' => FuelType::Diesel,
                'vin' => sprintf('JAL%s%07d', strtoupper(substr($make, 0, 3)), 4500000 + $i * 7919),
                'status' => $status,
                'assigned_driver_id' => $status === VehicleStatus::Available ? $assignable[$i % $assignable->count()]?->id : null,
            ]);
        });
    }

    private function operations(Collection $vehicles, Collection $drivers, ?User $admin): void
    {
        $fleet = $vehicles->filter(fn (Vehicle $v) => $v->status === VehicleStatus::Available)->values();
        $crew = $drivers->filter(fn (Driver $d) => $d->status === DriverStatus::Available)->values();
        $orderNo = 0;
        $tripNo = 0;

        $plan = [];
        // Delivered orders: completed trips over the last 13 days
        foreach (range(13, 1) as $daysAgo) {
            foreach (range(1, $daysAgo % 3 + 1) as $_) {
                $plan[] = ['status' => TripStatus::Completed, 'day' => -$daysAgo];
            }
        }
        // On the road now, released to drivers, and booked ahead
        $plan = [...$plan,
            ...array_fill(0, 3, ['status' => TripStatus::InProgress, 'day' => 0]),
            ...array_fill(0, 2, ['status' => TripStatus::Dispatched, 'day' => 0]),
            ...array_fill(0, 4, ['status' => TripStatus::Scheduled, 'day' => 1]),
            ...array_fill(0, 3, ['status' => TripStatus::Scheduled, 'day' => 2]),
        ];

        $slotByDay = [];
        foreach ($plan as $item) {
            $day = $item['day'];
            $slot = $slotByDay[$day] = ($slotByDay[$day] ?? -1) + 1;
            $vehicle = $fleet[$slot % $fleet->count()];
            $driver = $crew[$slot % $crew->count()];

            $departure = match ($item['status']) {
                TripStatus::InProgress => now()->subHours(2)->startOfHour(),
                TripStatus::Dispatched => now()->addHours(2)->startOfHour(),
                default => now()->addDays($day)->setTime(6 + ($slot % 3) * 2, 0),
            };
            $hours = 3 + mt_rand(0, 3);
            $arrival = $departure->copy()->addHours($hours);

            [$customer, $contact, $pickup] = self::CUSTOMERS[mt_rand(0, count(self::CUSTOMERS) - 1)];
            [$destName, $destAddress] = self::DESTINATIONS[mt_rand(0, count(self::DESTINATIONS) - 1)];
            [$cargo, $qty, $weight] = self::CARGO[mt_rand(0, count(self::CARGO) - 1)];

            $orderStatus = match ($item['status']) {
                TripStatus::Completed => OrderStatus::Delivered,
                TripStatus::InProgress => OrderStatus::InTransit,
                default => OrderStatus::Scheduled,
            };

            $order = Order::create([
                'order_number' => sprintf('ORD-%s-%04d', now()->year, ++$orderNo),
                'customer_name' => $customer,
                'customer_contact' => $contact,
                'pickup_address' => $pickup,
                'delivery_address' => $destAddress,
                'scheduled_pickup_at' => $departure,
                'scheduled_delivery_at' => $arrival,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'cargo_description' => $cargo,
                'quantity' => $qty,
                'weight_kg' => $weight,
                'delivery_instructions' => 'Call receiving dock 30 minutes before arrival.',
                'status' => $orderStatus,
                'delivered_at' => $orderStatus === OrderStatus::Delivered ? $arrival : null,
                'created_by' => $admin?->id,
            ]);
            $order->forceFill(['created_at' => $departure->copy()->subDays(1)->min(now())])->saveQuietly();

            $origin = explode(',', $pickup);
            $trip = Trip::create([
                'trip_number' => sprintf('TRIP-%s-%04d', now()->year, ++$tripNo),
                'order_id' => $order->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'origin' => trim(end($origin)),
                'destination' => $destName,
                'departure_at' => $departure,
                'estimated_arrival_at' => $arrival,
                'actual_departure_at' => in_array($item['status'], [TripStatus::Completed, TripStatus::InProgress], true) ? $departure : null,
                'actual_arrival_at' => $item['status'] === TripStatus::Completed ? $arrival->copy()->addMinutes(mt_rand(-20, 40)) : null,
                'status' => $item['status'],
                'dispatched_at' => $item['status'] !== TripStatus::Scheduled ? $departure->copy()->subHour()->min(now()) : null,
                'dispatched_by' => $item['status'] !== TripStatus::Scheduled ? $admin?->id : null,
                'created_by' => $admin?->id,
            ]);

            $this->stops($trip, $order, $item['status']);

            DispatchAssignment::create([
                'trip_id' => $trip->id,
                'vehicle_id' => $vehicle->id,
                'driver_id' => $driver->id,
                'assigned_by' => $admin?->id,
                'assigned_at' => $order->created_at,
                'released_at' => $item['status'] === TripStatus::Completed ? $trip->actual_arrival_at : null,
                'reason' => 'Initial assignment',
            ]);

            if ($item['status'] === TripStatus::InProgress) {
                $vehicle->update(['status' => VehicleStatus::OnTrip]);
                $driver->update(['status' => DriverStatus::OnTrip]);
            }
        }

        // Orders waiting for a trip, and a couple that were called off
        foreach (range(1, 6) as $i) {
            [$customer, $contact, $pickup] = self::CUSTOMERS[$i % count(self::CUSTOMERS)];
            [, $destAddress] = self::DESTINATIONS[($i * 3) % count(self::DESTINATIONS)];
            [$cargo, $qty, $weight] = self::CARGO[$i % count(self::CARGO)];
            $pickupAt = now()->addDays(2 + $i % 4)->setTime(7 + $i % 4, 0);
            $cancelled = $i > 4;

            Order::create([
                'order_number' => sprintf('ORD-%s-%04d', now()->year, ++$orderNo),
                'customer_name' => $customer,
                'customer_contact' => $contact,
                'pickup_address' => $pickup,
                'delivery_address' => $destAddress,
                'scheduled_pickup_at' => $pickupAt,
                'scheduled_delivery_at' => $pickupAt->copy()->addHours(5),
                'cargo_description' => $cargo,
                'quantity' => $qty,
                'weight_kg' => $weight,
                'status' => $cancelled ? OrderStatus::Cancelled : OrderStatus::Pending,
                'cancelled_at' => $cancelled ? now()->subHours($i) : null,
                'cancellation_reason' => $cancelled ? 'Customer rescheduled shipment' : null,
                'created_by' => $admin?->id,
            ]);
        }
    }

    private function stops(Trip $trip, Order $order, TripStatus $status): void
    {
        $midpoints = ['Calamba Toll Plaza', 'Sto. Tomas Rest Area', 'Lipa Distribution Hub', 'SLEX Petron Station'];
        $intermediate = mt_rand(0, 2);
        $rows = [['type' => StopType::Pickup, 'location_name' => $trip->origin, 'address' => $order->pickup_address, 'eta' => $trip->departure_at]];

        for ($i = 1; $i <= $intermediate; $i++) {
            $rows[] = [
                'type' => StopType::Stop,
                'location_name' => $midpoints[($trip->id + $i) % count($midpoints)],
                'address' => null,
                'eta' => $trip->departure_at->copy()->addMinutes((int) ($trip->departure_at->diffInMinutes($trip->estimated_arrival_at) * $i / ($intermediate + 1))),
            ];
        }
        $rows[] = ['type' => StopType::Dropoff, 'location_name' => $trip->destination, 'address' => $order->delivery_address, 'eta' => $trip->estimated_arrival_at];

        foreach ($rows as $index => $row) {
            $done = $status === TripStatus::Completed || ($status === TripStatus::InProgress && $index === 0);
            $trip->stops()->create([
                'sequence' => $index + 1,
                'type' => $row['type'],
                'location_name' => $row['location_name'],
                'address' => $row['address'],
                'order_id' => $row['type'] === StopType::Stop ? null : $order->id,
                'estimated_arrival_at' => $row['eta'],
                'actual_arrival_at' => $done ? $row['eta'] : null,
                'status' => $done ? StopStatus::Completed : StopStatus::Pending,
            ]);
        }
    }

    private function fuel(Collection $vehicles, ?User $admin): void
    {
        $stations = ['Petron SLEX Southbound', 'Shell Calamba', 'Caltex Batangas', 'Phoenix Sta. Rosa', 'Seaoil Pasig'];
        $active = $vehicles->filter(fn (Vehicle $v) => $v->status !== VehicleStatus::Inactive)->values();

        foreach (range(0, 39) as $i) {
            $vehicle = $active[$i % $active->count()];
            $liters = mt_rand(3000, 12000) / 100;
            $price = mt_rand(5800, 6600) / 100;

            FuelRecord::create([
                'vehicle_id' => $vehicle->id,
                'driver_id' => $vehicle->assigned_driver_id,
                'fueled_at' => now()->subDays(30 - (int) floor($i * 0.75))->setTime(mt_rand(6, 18), mt_rand(0, 59)),
                'fuel_type' => $vehicle->fuel_type,
                'liters' => $liters,
                'price_per_liter' => $price,
                'total_cost' => round($liters * $price, 2),
                'odometer_km' => 40000 + $vehicle->id * 5000 + $i * 180,
                'station' => $stations[$i % count($stations)],
                'receipt_number' => sprintf('OR-%06d', 100200 + $i * 17),
                'created_by' => $admin?->id,
            ]);
        }
    }

    private function documents(Collection $vehicles, Collection $drivers, ?User $admin): void
    {
        foreach ($vehicles as $i => $vehicle) {
            VehicleDocument::create([
                'vehicle_id' => $vehicle->id,
                'document_type' => VehicleDocumentType::Registration,
                'title' => "LTO Certificate of Registration – {$vehicle->plate_number}",
                'document_number' => sprintf('CR-%08d', 21000000 + $i * 731),
                'issued_at' => now()->subMonths(10)->toDateString(),
                'expires_at' => now()->addDays(match ($i % 4) {
                    0 => -5, 1 => 14, default => 120 + $i * 10
                })->toDateString(),
                'uploaded_by' => $admin?->id,
            ]);
            VehicleDocument::create([
                'vehicle_id' => $vehicle->id,
                'document_type' => VehicleDocumentType::Insurance,
                'title' => "Comprehensive insurance – {$vehicle->plate_number}",
                'document_number' => sprintf('POL-%07d', 880000 + $i * 113),
                'issued_at' => now()->subMonths(4)->toDateString(),
                'expires_at' => now()->addMonths(8)->toDateString(),
                'uploaded_by' => $admin?->id,
            ]);
        }

        foreach ($drivers as $driver) {
            DriverDocument::create([
                'driver_id' => $driver->id,
                'document_type' => DriverDocumentType::License,
                'title' => "Driver's license – {$driver->full_name}",
                'document_number' => $driver->license_number,
                'expires_at' => $driver->license_expiry->toDateString(),
                'uploaded_by' => $admin?->id,
            ]);
            DriverDocument::create([
                'driver_id' => $driver->id,
                'document_type' => DriverDocumentType::NbiClearance,
                'title' => "NBI clearance – {$driver->full_name}",
                'issued_at' => now()->subMonths(3)->toDateString(),
                'expires_at' => now()->addMonths(9)->toDateString(),
                'uploaded_by' => $admin?->id,
            ]);
        }
    }
}
