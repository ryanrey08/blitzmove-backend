<?php

namespace App\Services;

use App\Enums\DriverStatus;
use App\Enums\TripStatus;
use App\Enums\VehicleStatus;
use App\Models\Driver;
use App\Models\DriverDocument;
use App\Models\Trip;
use App\Models\Vehicle;
use App\Models\VehicleDocument;

class MonitoringService
{
    public function __construct(private DashboardService $dashboard) {}

    public function overview(): array
    {
        $soon = now()->addDays(VehicleDocument::EXPIRING_SOON_DAYS)->toDateString();

        return [
            'vehicle_status' => $this->dashboard->distribution(Vehicle::query(), VehicleStatus::class),
            'driver_status' => $this->dashboard->distribution(Driver::query(), DriverStatus::class),
            'utilization' => [
                'vehicles_total' => Vehicle::count(),
                'vehicles_available' => Vehicle::where('status', VehicleStatus::Available)->count(),
                'vehicles_on_trip' => Vehicle::where('status', VehicleStatus::OnTrip)->count(),
                'drivers_total' => Driver::count(),
                'drivers_available' => Driver::where('status', DriverStatus::Available)->count(),
                'drivers_on_trip' => Driver::where('status', DriverStatus::OnTrip)->count(),
            ],
            'active_assignments' => Trip::with(['vehicle', 'driver', 'order'])
                ->whereIn('status', TripStatus::active())
                ->orderBy('departure_at')->get(),
            'upcoming_trips' => Trip::with(['vehicle', 'driver', 'order'])
                ->where('status', TripStatus::Scheduled)
                ->where('departure_at', '>=', now()->startOfDay())
                ->orderBy('departure_at')->limit(10)->get(),
            'vehicles' => Vehicle::with(['assignedDriver', 'activeTrips'])->orderBy('plate_number')->get(),
            'expiring_vehicle_documents' => VehicleDocument::with('vehicle')
                ->whereNotNull('expires_at')->whereDate('expires_at', '<=', $soon)
                ->orderBy('expires_at')->limit(20)->get(),
            'expiring_driver_documents' => DriverDocument::with('driver')
                ->whereNotNull('expires_at')->whereDate('expires_at', '<=', $soon)
                ->orderBy('expires_at')->limit(20)->get(),
            'expiring_licenses' => Driver::whereDate('license_expiry', '<=', $soon)
                ->orderBy('license_expiry')->limit(20)->get(),
        ];
    }
}
