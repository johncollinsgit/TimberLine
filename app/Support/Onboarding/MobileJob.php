<?php

namespace App\Support\Onboarding;

enum MobileJob: string
{
    case OwnerSnapshot = 'owner_snapshot';
    case AlertsNotifications = 'alerts_notifications';
    case CustomerLookup = 'customer_lookup';
    case CustomerCrud = 'customer_crud';
    case Messaging = 'messaging';
    case MarketingCustomers = 'marketing_customers';
    case OrderViewing = 'order_viewing';
    case OrderCrud = 'order_crud';
    case SendToProduction = 'send_to_production';
    case PrioritizeWork = 'prioritize_work';
    case UpdateProductionProgress = 'update_production_progress';
    case SendToShipping = 'send_to_shipping';
    case QuickNotes = 'quick_notes';
    case PhotosUploads = 'photos_uploads';
    case ChecklistCompletion = 'checklist_completion';
    case GeolocationCapture = 'geolocation_capture';
}

