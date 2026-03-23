# Appointment Booking Module — Technical Documentation

**Drupal version:** 10 / 11
**Module Name:** `appointment`
**Date:** March 18, 2026

---

## 1. Project Overview
The `appointment` module is a comprehensive booking system for Drupal. It allows users to book appointments with specialised advisers at specific agencies through a guided 6-step wizard. It features a robust slot-based availability engine, calendar integration, and background email processing.

---

## 2. Architecture

### 2.1 Entity Types
The module defines two custom content entities:
- **Agency (`appointment_agency`)**: Represents physical locations (banks, clinics, etc.) with addresses, contact info, and operating hours.
- **Appointment (`appointment`)**: Represents a booking. Stores references to the agency, adviser, appointment type, customer data, and status.

### 2.2 User Entity Extensions
The standard Drupal User entity is extended via `hook_entity_base_field_info()` to support the **Adviser** role:
- `adviser_agency`: Reference to the assigned agency.
- `adviser_hours`: JSON-based weekly schedule.
- `adviser_specializations`: Taxonomy references to service types.

### 2.3 Service Layer
- **`AppointmentManagerService`**: The core engine. Calculates available slots, validates double-booking, and handles entity creation.
- **`EmailService`**: Handles the logic for constructing and enqueuing transactional emails.
- **`CsvImporter`**: A dedicated service for bulk importing Agencies and Advisers from CSV files.

---

## 3. Key Features

### 3.1 6-Step Booking Wizard
Accessible at `/book-an-appointment`, the wizard uses Drupal's Form API and `PrivateTempStore` for state persistence.
1. **Agency Selection**: Choose the branch.
2. **Type Selection**: Choose the service (e.g., Financial Advice).
3. **Adviser Selection**: Choose an adviser (filtered by agency and specialization).
4. **Date & Time Selection**: **Integrated with FullCalendar v6**. Users select an interactive slot.
5. **Personal Info**: Name, Email, Phone (with validation).
6. **Confirmation**: Final summary before entity creation.

### 3.2 FullCalendar Integration
Step 4 utilizes **FullCalendar v6** (TimeGrid view) to display real-time availability. 
- **Dynamic Loading**: JavaScript calls `/api/appointment/slots-range/{adviser_id}` to fetch slots as the user navigates the calendar.
- **Responsive Slots**: Slots are rendered as solid blocks with proper durations based on system configuration.
- **Validation**: Prevents the selection of past times or already-booked slots.

### 3.3 Background Email Notifications (Queue API)
To ensure high performance and reliability, emails are processed in the background using the **Drupal Queue API**.
- **Enqueuing**: On appointment creation, modification, or cancellation, an item is added to the `appointment_email_queue`.
- **Worker**: `AppointmentEmailWorker` (QueueWorker plugin) processes these items during Cron runs, handling the actual mail delivery.
- **Templates**: Integrated with `hook_mail` for customizable subjects and bodies.

### 3.4 CSV Import Tool
Located at `/admin/config/appointment/import`, this tool allows administrators to bulk-import:
- **Agencies**: Bulk create/update agency entities from a CSV.
- **Advisers**: Create/update users with the `adviser` role, mapping them to agencies and specialisations.

---

## 4. Administrative Features
- **Admin Dashboard**: Located at `/admin/structure/appointment`.
- **Tabbed Interface**: The dashboard is organized into tabs:
  - **List**: The administrative View of all appointments.
  - **Time Slot Configuration**: Manage the system-wide appointment slot duration.
  - **Manage fields/form/display**: Standard Field UI tabs for the Appointment entity.
- **Filters**: Advanced filtering by Agency and Adviser (dropdowns) and Date (HTML5 Date Picker).
- **Settings**: System-wide configuration for slot duration and default hours.

---

## 5. Technical Implementation Details

### 5.1 JSON API Endpoints
- `/api/appointment/slots/{adviser_id}/{date}`: Returns H:i slots for a single day.
- `/api/appointment/slots-range/{adviser_id}?start=...&end=...`: Returns FullCalendar-compatible event objects for a date range.

### 5.2 Slot Calculation Logic
The availability engine:
1. Loads the adviser's JSON schedule.
2. Filters out dates in the past.
3. Subtracts existing appointments (status != 'cancelled').
4. Generates slots based on the `slot_duration_minutes` configuration.

---

## 6. Installation & Configuration

1. **Enable the module**: `drush en appointment`
2. **Configure Taxonomy**: Add terms to the `appointment_type` vocabulary.
3. **Set up Agencies**: Create agencies at `/agency/add`.
4. **Create Advisers**: Assign the `adviser` role to users and fill in their Agency, Hours, and Specializations.
5. **Run Cron**: Ensure Drupal Cron is configured to process the email queue.

---

## 7. Future Enhancements (Phase 4)
- [ ] Export appointments to CSV using Batch API.
- [ ] Full French translation (`.po` files).
- [ ] SMS notifications for reminders.
- [ ] Advanced role-based access for Agencies (Branch Managers).
