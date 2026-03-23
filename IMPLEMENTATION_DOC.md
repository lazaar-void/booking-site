# Appointment Booking Module — Implementation Document

**Drupal version:** 10 / 11
**Module Name:** `appointment`
**Author:** Hamza Bahlaouane
**Last Updated:** March 24, 2026

---

## 1. Project Overview

The `appointment` module is a comprehensive booking system for Drupal. It allows users to book appointments with specialised advisers at specific agencies through a guided 6-step wizard. It features a robust slot-based availability engine, FullCalendar integration, background email processing, and a complete appointment lifecycle management system (create, modify, cancel).

---

## 2. Architecture

### 2.1 High-Level Architecture Diagram

```
┌──────────────────────────────────────────────────────────┐
│                      FRONT-END                            │
│  /book-an-appointment  →  AppointmentSubmitForm (6-step)  │
│  /my-appointments      →  AppointmentController           │
│  /appointment/modify   →  Lookup → Verify → Edit          │
│  /api/slots/...        →  JSON APIs (AJAX + FullCalendar) │
└─────────────┬────────────────────────────────────────────┘
              │ calls
┌─────────────▼────────────────────────────────────────────┐
│                    SERVICE LAYER                           │
│  AppointmentManagerService     (slot engine, entity ops)   │
│  EmailService                  (queue-based dual emails)   │
│  CsvImporter                   (bulk import from CSV)      │
└─────────────┬────────────────────────────────────────────┘
              │ reads / writes
┌─────────────▼────────────────────────────────────────────┐
│                 ENTITY LAYER (Drupal ORM)                  │
│  appointment_agency  ←──┐                                 │
│  appointment         ───┘  (entity_reference)              │
│  user (extended)     ───── adviser_agency / hours          │
│  taxonomy_term       ───── appointment_type vocab          │
└──────────────────────────────────────────────────────────┘
```

### 2.2 Entity Types

#### Agency (`appointment_agency`)
- **Class:** `Drupal\appointment\Entity\Agency`
- Custom content entity representing physical branch locations
- Fields: `label`, `address`, `phone`, `email`, `operating_hours` (JSON)
- Revisionable and publishable via `EditorialContentEntityBase`

#### Appointment (`appointment`)
- **Class:** `Drupal\appointment\Entity\Appointment`
- Custom content entity representing a booking
- Fields: `label` (auto-generated reference), `appointment_date`, `appointment_status`, `agency`, `adviser`, `appointment_type`, `customer_name`, `customer_email`, `customer_phone`, `notes`
- Status values: `pending`, `confirmed`, `cancelled`

### 2.3 User Entity Extensions

Via `hook_entity_base_field_info()`, three fields are added to the User entity for users with the `adviser` role:

| Field | Machine Name | Type | Purpose |
|-------|-------------|------|---------|
| Agency | `adviser_agency` | `entity_reference` → `appointment_agency` | Branch assignment |
| Working Hours | `adviser_hours` | `string_long` | JSON schedule `{"mon":["09:00","17:00"]}` |
| Specialisations | `adviser_specializations` | `entity_reference` → `taxonomy_term` | Service types offered |

---

## 3. Service Layer

### 3.1 AppointmentManagerService (`appointment.manager`)

The **core engine** of the module. Handles:

**Slot Calculation** (`getAvailableSlots`):
1. Loads adviser's `adviser_hours` JSON field
2. Maps the requested day to the JSON key (`mon`, `tue`, etc.)
3. Generates all theoretical slots at `slot_duration_minutes` intervals
4. Filters out past time slots
5. Queries existing non-cancelled appointments
6. Returns only free slots

**Double-Booking Prevention** (`isSlotAvailable`):
- Called during wizard validation (step 6) and in `AppointmentForm` for admin edits
- Accepts optional `$excludeId` parameter to allow editing an existing appointment's time

**Entity Operations**:
- `createAppointment()` — Creates the entity, generates reference code, validates slot availability
- `cancelAppointment()` — Soft-deletes by changing status to `cancelled`
- `findByReferenceAndEmail()` — Secure lookup for the modification flow

### 3.2 EmailService (`appointment.email`)

Handles **dual-recipient email notifications** (both customer and adviser receive each email):

| Event | Customer Key | Adviser Key |
|-------|-------------|-------------|
| Confirmation | `booking_confirm_customer` | `booking_confirm_adviser` |
| Modification | `booking_modified_customer` | `booking_modified_adviser` |
| Cancellation | `booking_cancelled_customer` | `booking_cancelled_adviser` |

**Hybrid Queue Approach:**
1. Emails are enqueued to `appointment_email_queue`
2. `_appointment_trigger_email_cron()` processes the queue **immediately** (instant delivery)
3. `AppointmentEmailWorker` (QueueWorker plugin) handles items during Cron as fallback
4. Failed items are automatically retried on next Cron run

### 3.3 CsvImporter (`appointment.csv_importer`)

Bulk import service using `league/csv`:
- **Agency import**: Creates/updates `appointment_agency` entities from CSV
- **Adviser import**: Creates/updates user accounts with `adviser` role, maps to agencies by name, auto-creates taxonomy terms for specialisations

---

## 4. Forms

### 4.1 Booking Wizard (`AppointmentSubmitForm`)

A **6-step FAPI wizard** powered by `PrivateTempStore` and AJAX:

| Step | Title | Key Logic |
|------|-------|-----------|
| 1 | Agency | Radio cards from `getAgencyOptions()` |
| 2 | Type | Radio cards from `getTypeOptions()` |
| 3 | Adviser | Filtered radios from `getAdviserOptions(agencyId, typeId)` |
| 4 | Date & Time | **FullCalendar v6** TimeGrid — fetches from `/api/appointment/slots-range/{adviser_id}` |
| 5 | Personal Info | Name, email, phone (validated with regex) |
| 6 | Confirmation | Read-only summary → `createAppointment()` on submit |

**Technical Notes:**
- AJAX navigation with `#ajax` callbacks and `fade` effect
- Race condition prevention: `isSlotAvailable()` rechecked at submit time
- TempStore keys cleared on success
- Anonymous users prompted to create an account after booking

### 4.2 Agency Form (`AgencyForm`)

Replaces the raw JSON `operating_hours` textarea with a **structured widget**: per-day checkbox (open/closed) + start/end time selects in 30-minute increments. On save, serialises back to JSON format.

### 4.3 Appointment Modification Flow

Three-form sequence for email-based appointment modification:

```
/appointment/modify         → AppointmentLookupForm  (enter reference)
/appointment/modify/verify  → AppointmentVerifyForm  (verify email)
/appointment/modify/edit    → AppointmentModifyForm  (change details)
```

### 4.4 Cancellation Form (`AppointmentCancelForm`)

- Extends `ConfirmFormBase`
- Validates ownership (only the creator can cancel)
- Checks appointment isn't already cancelled
- Delegates to `AppointmentManagerService::cancelAppointment()`

---

## 5. Controller & API Endpoints

**Class:** `AppointmentController` extends `ControllerBase`

| Endpoint | Method | Purpose |
|----------|--------|---------|
| `/book-an-appointment` | `bookingWizard()` | Renders the wizard form |
| `/my-appointments` | `myAppointments()` | User dashboard (with anonymous login prompt) |
| `/api/appointment/slots/{id}/{date}` | `slotsJson()` | Single-day slots for legacy JS |
| `/api/appointment/slots-range/{id}` | `slotsRangeJson()` | Date range slots for FullCalendar |

---

## 6. FullCalendar Integration

Step 4 of the wizard uses **FullCalendar v6** (TimeGrid view) loaded from CDN:

- **Library:** `appointment/fullcalendar` (external CDN resources)
- **JS:** `appointment-calendar.js` initialises the calendar
- **Event Source:** `/api/appointment/slots-range/{adviser_id}?start=...&end=...`
- Available slots render as green blocks with proper duration
- Clicking a slot populates hidden `#selected-date` and `#selected-time` form fields
- Past times are automatically disabled

---

## 7. Hooks Implementation

All hooks are in `appointment.module`:

| Category | Hooks |
|----------|-------|
| **Theme** | `hook_theme()`, `template_preprocess_appointment()`, `template_preprocess_appointment_agency()` |
| **Entity** | `hook_entity_base_field_info()`, `hook_appointment_insert()`, `hook_appointment_update()` |
| **Mail** | `hook_mail()` — 6 templates (confirm/modify/cancel × customer/adviser) |
| **Form Alter** | `hook_form_user_form_alter()` — adviser hours widget |
| **Views** | `hook_form_views_exposed_form_alter()` — dropdown filters + date pickers |
| **User Lifecycle** | `hook_user_cancel()`, `hook_user_predelete()` |

---

## 8. Access Control

Two access control handlers enforce permissions per entity type:
- **`AgencyAccessControlHandler`** — checks agency-specific permissions
- **`AppointmentAccessControlHandler`** — checks appointment-specific permissions

Both use a `match` expression to map operations (view, update, delete, revisions) to granular permissions defined in `appointment.permissions.yml`.

---

## 9. Plugins

### 9.1 AppointmentBlock (`@Block`)

A "Book an Appointment" CTA block that can be placed anywhere on the site via the Block Layout UI.

### 9.2 AppointmentEmailWorker (`@QueueWorker`)

Processes the `appointment_email_queue` during Cron. Handles failed items gracefully with retry logic.

---

## 10. Database Schema

Update hooks in `appointment.install`:

| Hook | Entity | Fields Added |
|------|--------|-------------|
| `appointment_update_10001` | `appointment` | `appointment_date`, `appointment_status`, `customer_name`, `customer_email`, `customer_phone`, `notes`, `agency`, `adviser`, `appointment_type` |
| `appointment_update_10002` | `appointment_agency` | `address`, `phone`, `email`, `operating_hours` |
| `appointment_update_10003` | `user` | `adviser_agency`, `adviser_hours`, `adviser_specializations` |

---

## 11. Assets

| Library | Files | Purpose |
|---------|-------|---------|
| `appointment/booking-wizard` | `css/appointment.css`, `js/appointment.js` | Wizard styles, legacy slot loader |
| `appointment/fullcalendar` | CDN resources | FullCalendar v6 core + TimeGrid |
| `appointment/booking-calendar` | `js/appointment-calendar.js` | FullCalendar integration logic |

---

## 12. Installation & Configuration

```bash
# Install
composer require league/csv
drush en appointment -y
drush updb -y
drush cr

# Initial setup
# 1. Create 'adviser' role
# 2. Add terms to 'appointment_type' vocabulary
# 3. Create agencies (or CSV import)
# 4. Create adviser users with role + hours + agency
# 5. Configure slot duration at /admin/structure/appointment/settings
```

---

## 13. Testing & Performance

A performance testing script is included at `scripts/generate_appointments.php`:

```bash
drush scr web/modules/custom/appointment/scripts/generate_appointments.php
```

- Generates **1000 appointments** with randomised data
- Temporarily disables email sending to avoid SMTP overload
- Reports creation time for benchmarking

Sample CSV data for import testing is included in `sample_data/`.

---

## 14. Development Status

| Phase | Status | Highlights |
|-------|--------|------------|
| Phase 1 — Foundation | ✅ Complete | Entities, fields, permissions, CRUD |
| Phase 2 — Core | ✅ Complete | Wizard, slot engine, emails, dashboard |
| Phase 3 — Advanced | ✅ Complete | FullCalendar, CSV import, queue emails, modify/cancel, CTA block |
| Phase 4 — Polish | 🔲 Pending | CSV export, translations, SMS, advanced roles |
