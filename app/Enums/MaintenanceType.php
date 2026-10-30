<?php

namespace App\Enums;

/** The service performed ("Service Type" in the UI). */
enum MaintenanceType: string
{
    use HasOptions;

    case PreventiveMaintenance = 'preventive_maintenance';
    case OilChange = 'oil_change';
    case TireService = 'tire_service';
    case BrakeService = 'brake_service';
    case EngineRepair = 'engine_repair';
    case TransmissionService = 'transmission_service';
    case Electrical = 'electrical';
    case AirconService = 'aircon_service';
    case Suspension = 'suspension';
    case BodyRepair = 'body_repair';
    case Inspection = 'inspection';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PreventiveMaintenance => 'Preventive Maintenance (PMS)',
            default => ucwords(str_replace('_', ' ', $this->value)),
        };
    }
}
