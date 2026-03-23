# Appointment Booking Module — Technical Documentation

> **Module:** `appointment`
> **Drupal version:** 10 / 11
> **Author:** Hamza Bahlaouane
> **Date:** March 2025

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Architecture Overview](#2-architecture-overview)
3. [Module Structure](#3-module-structure)
4. [Entity Types](#4-entity-types)
   - 4.1 [Agency (`appointment_agency`)](#41-agency-appointment_agency)
   - 4.2 [Appointment (`appointment`)](#42-appointment-appointment)
5. [User Entity Extensions (Adviser Fields)](#5-user-entity-extensions-adviser-fields)
6. [Services](#6-services)
   - 6.1 [AppointmentManagerService](#61-appointmentmanagerservice)
   - 6.2 [EmailService](#62-emailservice)
   - 6.3 [CsvImporter](#63-csvimporter)
7. [Forms](#7-forms)
   - 7.1 [AppointmentSubmitForm — 6-Step Wizard](#71-appointmentsubmitform--6-step-wizard)
   - 7.2 [AgencyForm / AppointmentForm](#72-agencyform--appointmentform)
   - 7.3 [Settings Forms](#73-settings-forms)
   - 7.4 [ImportCsvForm](#74-importcsvform)
8. [Controller](#8-controller)
9. [Routing](#9-routing)
10. [Permissions](#10-permissions)
11. [Hooks](#11-hooks)
12. [Email Notifications](#12-email-notifications)
13. [Assets (CSS / JS)](#13-assets-css--js)
14. [Templates](#14-templates)
15. [Database Schema (Update Hooks)](#15-database-schema-update-hooks)
16. [Installation Guide](#16-installation-guide)
17. [Development Phases — Progress](#17-development-phases--progress)

---

## 1. Project Overview

The `appointment` module provides a complete **appointment booking system** for a Drupal 10/11 site. It allows end-users to book appointments with specialised advisers at specific agencies through a guided multi-step form, and provides administrators with full management capabilities.

### Key Features

- **Multi-step booking wizard** (6 steps) at `/book-an-appointment`
- **Two custom content entities**: Agency and Appointment
- **Adviser role** with extended user fields (agency, working hours, specialisations)
- **Slot-based availability engine** driven by adviser working-hours (JSON) and existing bookings
- **Double-booking prevention** enforced both at query time and at save time
- **Transactional email notifications** for booking confirmation, modification, and cancellation
- **User appointment dashboard** at `/my-appointments`
- **Admin CRUD interface** at `/admin/structure/appointment` and `/admin/content/agency`
- **CSV Import functionality** for Agencies and Advisers at `/admin/config/appointment/import`
- **JSON API endpoint** for dynamic time-slot loading via JavaScript (`/api/appointment/slots/{adviser_id}/{date}`)

---

## 2. Architecture Overview

```
┌─────────────────────────────────────────────────────┐
│                    FRONT-END                         │
│  /book-an-appointment  →  AppointmentSubmitForm      │
│  /my-appointments      →  AppointmentController      │
│  /api/appointment/slots/{id}/{date}  →  JSON API     │
└───────────┬─────────────────────────────────────────┘
            │ calls
┌───────────▼─────────────────────────────────────────┐
│               SERVICE LAYER                          │
│  AppointmentManagerService                           │
│   ├─ getAvailableSlots(adviserId, date)              │
│   └─ ...                                             │
│                                                      │
│  EmailService                                        │
│   └─ ...                                             │
│                                                      │
│  CsvImporter                                         │
│   ├─ importAgencies(filePath)                        │
│   └─ importAdvisers(filePath)                        │
└───────────┬─────────────────────────────────────────┘
            │ reads / writes
┌───────────▼─────────────────────────────────────────┐
│              ENTITY LAYER (Drupal ORM)               │
│  appointment_agency  ←──┐                           │
│  appointment         ───┘  (entity_reference)        │
│  user (extended)     ───── adviser_agency / hours    │
│  taxonomy_term       ───── appointment_type vocab    │
└─────────────────────────────────────────────────────┘
```

**Key design decisions:**

| Decision | Rationale |
|----------|-----------|
| Single `appointment` module | Both `Agency` and `Appointment` entities live in one module to avoid circular dependencies and simplify deployment |
| `EditorialContentEntityBase` | Gives revisions, publishing status, and translation support out of the box |
| `PrivateTempStore` for wizard state | Survives AJAX rebuilds without cookies or hidden fields; automatically scoped per user/session |
| JSON working-hours field | Flexible format `{"mon":["09:00","17:00"]}` avoids a dedicated entity for time ranges |
| `hook_entity_base_field_info()` for adviser fields | Keeps user entity extension inside the module without requiring Field UI configuration |
| English source strings + `t()` | Strings are in English; French translation is applied via `.po` files / Translation UI |

---

## 3. Module Structure

```
web/modules/custom/appointment/
├── config/
│   └── install/
│       ├── system.action.appointment_agency_delete_action.yml
│       ├── system.action.appointment_agency_save_action.yml
│       ├── system.action.appointment_delete_action.yml
│       └── system.action.appointment_save_action.yml
├── css/
│   └── appointment.css
├── js/
│   └── appointment.js
├── src/
│   ├── Controller/
│   │   └── AppointmentController.php
│   ├── Entity/
│   │   ├── Agency.php
│   │   └── Appointment.php
│   ├── Form/
│   │   ├── AgencyForm.php
│   │   ├── AgencySettingsForm.php
│   │   ├── AppointmentForm.php
│   │   ├── AppointmentSettingsForm.php
│   │   ├── AppointmentSubmitForm.php
│   │   └── ImportCsvForm.php           ← CSV import UI
│   └── Service/
│       ├── AppointmentManagerService.php
│       ├── CsvImporter.php             ← CSV parsing logic
│       └── EmailService.php
├── templates/
│   ├── appointment.html.twig
│   ├── appointment-agency.html.twig
│   └── appointment-my-appointments.html.twig
├── appointment.info.yml
├── appointment.install
├── appointment.libraries.yml
├── appointment.links.action.yml
├── appointment.links.contextual.yml
├── appointment.links.menu.yml
├── appointment.links.task.yml
├── appointment.module
├── appointment.permissions.yml
├── appointment.routing.yml
└── appointment.services.yml
```

---

## 4. Entity Types

### 4.1 Agency (`appointment_agency`)

**Class:** `Drupal\appointment\Entity\Agency`
**Base table:** `appointment_agency`
**Interfaces:** `EditorialContentEntityBase`, `AgencyInterface`, `EntityOwnerInterface`, `EntityChangedInterface`

| Field | Machine name | Type | Required | Notes |
|-------|-------------|------|----------|-------|
| Agency name | `label` | `string` | ✅ | Entity label key |
| Published | `status` | `boolean` | — | Editorial status |
| Address | `address` | `string_long` | ✅ | Free-text physical address |
| Phone | `phone` | `telephone` | ✅ | Rendered as click-to-call |
| Email | `email` | `email` | ✅ | Rendered as `mailto:` link |
| Operating hours | `operating_hours` | `string_long` | — | JSON schedule, e.g. `{"mon":["09:00","17:00"]}` |
| Author | `uid` | `entity_reference` → `user` | — | Entity owner |
| Created | `created` | `created` | — | Auto-set on insert |
| Changed | `changed` | `changed` | — | Auto-updated on save |

**Interface methods defined on `Agency.php`:**
`getAddress()`, `setAddress()`, `getPhone()`, `setPhone()`, `getEmail()`, `setEmail()`, `getOperatingHours()`, `setOperatingHours()`

**Admin routes** (auto-generated by `AdminHtmlRouteProvider`):

| Action | Path |
|--------|------|
| List | `/admin/content/agency` |
| Add | `/agency/add` |
| View | `/agency/{appointment_agency}` |
| Edit | `/agency/{appointment_agency}/edit` |
| Delete | `/agency/{appointment_agency}/delete` |

---

### 4.2 Appointment (`appointment`)

**Class:** `Drupal\appointment\Entity\Appointment`
**Base table:** `appointment`
**Interfaces:** `EditorialContentEntityBase`, `AppointmentInterface`, `EntityOwnerInterface`, `EntityChangedInterface`

| Field | Machine name | Type | Required | Notes |
|-------|-------------|------|----------|-------|
| Reference | `label` | `string` | ✅ | Auto-generated, e.g. `APP-20250317-A3F9C1` |
| Published | `status` | `boolean` | — | Editorial status |
| Appointment date | `appointment_date` | `datetime` | ✅ | UTC, format `Y-m-d\TH:i:s` |
| Appointment status | `appointment_status` | `list_string` | ✅ | `pending`, `confirmed`, `cancelled` |
| Agency | `agency` | `entity_reference` → `appointment_agency` | ✅ | — |
| Adviser | `adviser` | `entity_reference` → `user` (role: adviser) | ✅ | — |
| Appointment type | `appointment_type` | `entity_reference` → `taxonomy_term` | ✅ | Vocab: `appointment_type` |
| Customer name | `customer_name` | `string` | ✅ | — |
| Customer email | `customer_email` | `email` | ✅ | Used for notifications |
| Customer phone | `customer_phone` | `telephone` | ✅ | — |
| Notes | `notes` | `text_long` | — | Internal adviser/admin notes |
| Author | `uid` | `entity_reference` → `user` | — | Booking creator |
| Created | `created` | `created` | — | Auto-set on insert |
| Changed | `changed` | `changed` | — | Auto-updated on save |

**Interface methods defined on `Appointment.php`:**
`getAppointmentDate()`, `setAppointmentDate()`, `getAppointmentStatus()`, `setAppointmentStatus()`, `getCustomerName()`, `setCustomerName()`, `getCustomerEmail()`, `setCustomerEmail()`, `getCustomerPhone()`, `setCustomerPhone()`, `getNotes()`, `setNotes()`

**Admin routes** (auto-generated):

| Action | Path |
|--------|------|
| List | `/admin/content/appointment` |
| Add | `/appointment/add` |
| View | `/appointment/{appointment}` |
| Edit | `/appointment/{appointment}/edit` |
| Delete | `/appointment/{appointment}/delete` |

**Admin View Integration:**
The main administrative interface for appointments is located at `/admin/structure/appointment`. This page uses a **Local Task (Tab)** system to group related functionality:
- **List**: The administrative View of all appointments.
- **Time Slot Configuration**: The system-wide settings for slot duration.
- **Manage fields/form/display**: Standard Drupal Field UI tabs (attached via `field_ui_base_route`).

---

## 5. User Entity Extensions (Adviser Fields)

Added via `hook_entity_base_field_info()` in `appointment.module`. These fields appear on the standard user edit form for users with the `adviser` role.

| Field | Machine name | Type | Cardinality | Notes |
|-------|-------------|------|-------------|-------|
| Agency | `adviser_agency` | `entity_reference` → `appointment_agency` | 1 | Which branch the adviser works at |
| Working hours | `adviser_hours` | `string_long` | 1 | JSON: `{"mon":["09:00","17:00"],"tue":...}` |
| Specialisations | `adviser_specializations` | `entity_reference` → `taxonomy_term` | Unlimited | Filters adviser availability per appointment type |

**Schema registered via:** `appointment_update_10003()` in `appointment.install`

---

## 6. Services

Registered in `appointment.services.yml`. All services use **constructor dependency injection**.

### 6.1 AppointmentManagerService

**Service ID:** `appointment.manager`
**Class:** `Drupal\appointment\Service\AppointmentManagerService`

**Dependencies injected:**
- `entity_type.manager`
- `tempstore.private`
- `current_user`
- `config.factory`
- `logger.channel.appointment`

**Public API:**

```php
// Option arrays for wizard form elements
getAgencyOptions(): array           // Published agencies, sorted A→Z
getTypeOptions(): array             // appointment_type taxonomy terms
getAdviserOptions(int $agencyId, int $typeTermId): array  // Filtered by agency + specialisation

// Slot engine
getAvailableSlots(int $adviserId, string $date): string[] // Returns ['09:00','09:30',...]
isSlotAvailable(int $adviserId, DateTimeImmutable $slot): bool

// Entity mutations
createAppointment(array $data): object  // Throws RuntimeException on collision
cancelAppointment(object $appointment): void
generateReference(): string             // APP-YYYYMMDD-XXXXXX
```

**Slot engine logic:**

1. Load adviser's `adviser_hours` JSON field.
2. Map PHP day name (`Mon`, `Tue`, …) to JSON key (`mon`, `tue`, …).
3. Parse `[startTime, endTime]` for the requested day.
4. Generate all theoretical slots at `slot_duration_minutes` intervals.
5. Query existing non-cancelled appointments for that adviser + date.
6. Return the difference (free slots only).

### 6.2 EmailService

**Service ID:** `appointment.email`
**Class:** `Drupal\appointment\Service\EmailService`

**Dependencies injected:**
- `plugin.manager.mail`
- `config.factory`
- `logger.channel.appointment`
- `language_manager`

**Public API:**

```php
sendConfirmation(object $appointment): void
sendModification(object $appointment): void
sendCancellation(object $appointment): void
```

All three methods resolve to `hook_mail()` keys `booking_confirm`, `booking_modified`, `booking_cancelled` respectively. Token params built automatically from the appointment entity (loads agency/adviser/type labels). Success and failure are logged to the `appointment` channel.

### 6.3 CsvImporter

**Service ID:** `appointment.csv_importer`
**Class:** `Drupal\appointment\Service\CsvImporter`

**Dependencies injected:**
- `entity_type.manager`
- `logger.channel.appointment`
- `messenger`
- `file_system`

**Public API:**
- `importAgencies(string $filePath)`: Parses CSV and creates/updates `appointment_agency` entities.
- `importAdvisers(string $filePath)`: Parses CSV and creates/updates `user` entities with the `adviser` role. Automatically links to agencies by name and creates taxonomy terms for specialisations if they don't exist.

---

## 7. Forms

### 7.1 AppointmentSubmitForm — 6-Step Wizard

**Class:** `Drupal\appointment\Form\AppointmentSubmitForm`
**Form ID:** `appointment_submit_form`
**Route:** `/book-an-appointment`
**Services used:** `appointment.manager`, `tempstore.private`

#### Step flow

| Step | Title | Field(s) | Persisted to TempStore |
|------|-------|----------|----------------------|
| 1 | Agency | `agency_id` (radios) | `agency_id` |
| 2 | Appointment type | `type_id` (radios) | `type_id` |
| 3 | Adviser | `adviser_id` (radios, filtered) | `adviser_id` |
| 4 | Date & time | `date` (date picker), `time` (radios) | `date`, `time` |
| 5 | Personal info | `customer_name`, `customer_email`, `customer_phone` | all three |
| 6 | Summary | Read-only confirmation table | — (submits) |

#### Key implementation details

- **State persistence:** `PrivateTempStore` collection `appointment_wizard` — survives AJAX rebuilds, scoped per user session.
- **AJAX navigation:** Back/Next buttons use `#ajax` callbacks with `fade` effect targeting `#appointment-wizard-wrapper`.
- **Validation:**
  - Step 5: Phone number validated against regex `^[+]?[0-9\s\-\(\)]{7,20}$`
  - Step 6 (`validateForm`): `isSlotAvailable()` rechecked immediately before entity creation to prevent race conditions.
- **On double-booking collision:** `RuntimeException` caught in `submitForm()` → error message shown → user redirected back to step 4.
- **On success:** TempStore keys cleared, success message with reference shown, redirect to `/my-appointments`.
- **Progress bar:** Rendered as `<ol class="appointment-wizard-steps">` with CSS classes `step--done`, `step--active`, `step--pending`.

### 7.2 AgencyForm / AppointmentForm

Standard entity forms generated by the Drupal entity scaffolding system. Provide basic CRUD for admin users. Used by the admin interface at `/admin/content/agency` and `/admin/content/appointment`.

### 7.3 Settings Forms

| Form | Class | Route | Config object |
|------|-------|-------|---------------|
| Appointment settings | `AppointmentSettingsForm` | `/admin/structure/appointment/settings` | `appointment.settings` |
| Agency settings | `AgencySettingsForm` | `/admin/structure/appointment-agency` | `appointment_agency.settings` |

Note: The Appointment settings form is also exposed as the **Time Slot Configuration** tab on the main appointment management page (`/admin/structure/appointment`).

### 7.4 ImportCsvForm

**Class:** `Drupal\appointment\Form\ImportCsvForm`
**Route:** `/admin/config/appointment/import`

Provides a unified interface for uploading CSV files. Supports two import modes:
1. **Agencies**: Expects `Name, Address, Phone, Email, Operating Hours`.
2. **Advisers**: Expects `Username, Email, Password, Agency Name, Working Hours, Specializations`.

Uses the `FileExtension` constraint (Drupal 11 compatible) to ensure only `.csv` files are uploaded.

---

## 8. Controller

**Class:** `Drupal\appointment\Controller\AppointmentController`
**Extends:** `ControllerBase`
**Service injected:** `appointment.manager`

| Method | Route | Returns |
|--------|-------|---------|
| `bookingWizard()` | `GET /book-an-appointment` | Render array containing the wizard form |
| `slotsJson(int $adviser_id, string $date)` | `GET /api/appointment/slots/{adviser_id}/{date}` | `JsonResponse` — `{"slots":["09:00","09:30",…]}` |
| `myAppointments()` | `GET /my-appointments` | Render array using `appointment_my_appointments` theme hook |

---

## 9. Routing

**File:** `appointment.routing.yml`

| Route name | Path | Controller / Form | Permission |
|-----------|------|-------------------|------------|
| `entity.appointment.settings` | `/admin/structure/appointment` | `AppointmentSettingsForm` | `administer appointment` |
| `entity.appointment_agency.settings` | `/admin/structure/appointment-agency` | `AgencySettingsForm` | `administer appointment_agency` |
| `appointment.booking_wizard` | `/book-an-appointment` | `AppointmentController::bookingWizard` | `access content` |
| `appointment.slots_json` | `/api/appointment/slots/{adviser_id}/{date}` | `AppointmentController::slotsJson` | `access content` |
| `appointment.my_appointments` | `/my-appointments` | `AppointmentController::myAppointments` | `view own appointment` |

> Additional CRUD routes (add, edit, delete, canonical, collection, revisions) are **auto-generated** by `AdminHtmlRouteProvider` and `RevisionHtmlRouteProvider` for both entity types.

---

## 10. Permissions

**File:** `appointment.permissions.yml`

| Permission | Description |
|-----------|-------------|
| `administer appointment` | Full control over appointments (restricted) |
| `view appointment` | View any appointment entity |
| `view own appointment` | View dashboard of own appointments |
| `edit appointment` | Edit appointment entities |
| `delete appointment` | Delete appointment entities |
| `create appointment` | Create new appointments |
| `cancel own appointment` | Cancel own appointment |
| `view appointment revision` | View revision history |
| `revert appointment revision` | Revert to a previous revision |
| `delete appointment revision` | Delete a revision |
| `administer appointment_agency` | Full control over agencies (restricted) |
| `view appointment_agency` | View agency entities |
| `edit appointment_agency` | Edit agencies |
| `delete appointment_agency` | Delete agencies |
| `create appointment_agency` | Create agencies |

---

## 11. Hooks

All hooks are implemented in `appointment.module`.

| Hook | Purpose |
|------|---------|
| `appointment_theme()` | Registers `appointment`, `appointment_agency`, and `appointment_my_appointments` theme hooks |
| `template_preprocess_appointment()` | Prepares variables for `appointment.html.twig` |
| `template_preprocess_appointment_agency()` | Prepares variables for `appointment-agency.html.twig` |
| `appointment_entity_base_field_info()` | Adds `adviser_agency`, `adviser_hours`, `adviser_specializations` to the User entity |
| `appointment_mail()` | Defines email templates for `booking_confirm`, `booking_modified`, `booking_cancelled` |
| `appointment_appointment_insert()` | Fires `EmailService::sendConfirmation()` and processes the queue immediately for instant delivery |
| `appointment_appointment_update()` | Fires `sendCancellation()` or `sendModification()` and processes the queue immediately |
| `appointment_user_cancel()` | Unpublishes or anonymises appointments and agencies when a user account is cancelled |
| `appointment_user_predelete()` | Deletes all appointments, agencies, and their revisions when a user account is deleted |

---

## 12. Email Notifications

All emails are dispatched through Drupal's standard mail system (`plugin.manager.mail`). Three email keys are defined in `hook_mail()`:

| Key | Trigger | Subject template |
|-----|---------|-----------------|
| `booking_confirm` | New appointment inserted with status `pending`/`confirmed` | `Appointment confirmed [@reference]` |
| `booking_modified` | `appointment_status` changes to any non-cancelled value | `Appointment updated [@reference]` |
| `booking_cancelled` | `appointment_status` changes to `cancelled` | `Appointment cancelled [@reference]` |

**Available body tokens:** `@name`, `@reference`, `@date`, `@agency`, `@adviser`, `@type`

### 12.1 Immediate Queue Processing

To ensure a seamless user experience, the module uses a **Hybrid Queue Approach**:
1. **Enqueuing:** Emails are first added to the `appointment_email_queue`.
2. **Immediate Execution:** In `appointment.module`, the `_appointment_trigger_email_cron()` helper is called during entity insert/update. This manually claims and processes queue items **immediately** before the request ends.
3. **Cron Fallback:** If the mail server is down or a timeout occurs, items remain in the queue and will be retried automatically by the standard Drupal Cron or `ultimate_cron`.

> For local development, configure **Mailhog** as the SMTP backend to capture outgoing emails without sending them.

---

## 13. Assets (CSS / JS)

**Library:** `appointment/booking-wizard` (defined in `appointment.libraries.yml`)

### CSS (`css/appointment.css`)

| Component | Selector | Description |
|-----------|----------|-------------|
| Progress bar | `.appointment-wizard-steps` | Flexbox step indicator with connecting lines |
| Step states | `.step--done`, `.step--active`, `.step--pending` | Green / blue / grey colour coding |
| Step title | `.wizard-step-title` | H2 with bottom border |
| Radio card grid | `.wizard-radios` | CSS grid of card-style radio buttons |
| Time slot chips | `.wizard-slots` | Flex-wrap inline chips for time selection |
| Action buttons | `.btn-primary`, `.btn-back` | Primary (blue) and secondary (outlined) buttons |
| Summary table | `.appointment-summary-table` | Two-column label/value table |
| Dashboard cards | `.appointment-card`, `.status--*` | Per-appointment card with status badge |

### JS (`js/appointment.js`)

Implements a `Drupal.behaviors.appointmentWizard` that:

1. Listens for changes on `#appointment-date-picker` (the date field in step 4).
2. Reads the currently selected `adviser_id` value.
3. Calls the JSON endpoint `/api/appointment/slots/{adviser_id}/{date}`.
4. Rebuilds the `#time-slots-wrapper` radio list with the returned slots — **without a full page reload**.

---

## 14. Templates

| Template file | Theme hook | Variables |
|--------------|-----------|-----------|
| `appointment.html.twig` | `appointment` | `view_mode`, `content` |
| `appointment-agency.html.twig` | `appointment_agency` | `view_mode`, `content` |
| `appointment-my-appointments.html.twig` | `appointment_my_appointments` | `appointments` (array of entities) |

The dashboard template renders a card per appointment showing the reference code, formatted date, and a colour-coded status badge. A "Book your first appointment" CTA is shown when the list is empty.

---

## 15. Database Schema (Update Hooks)

**File:** `appointment.install`

| Hook | Applies to | Fields added |
|------|-----------|-------------|
| `appointment_update_10001` | `appointment` entity | `appointment_date`, `appointment_status`, `customer_name`, `customer_email`, `customer_phone`, `notes`, `agency`, `adviser`, `appointment_type` |
| `appointment_update_10002` | `appointment_agency` entity | `address`, `phone`, `email`, `operating_hours` |
| `appointment_update_10003` | `user` entity | `adviser_agency`, `adviser_hours`, `adviser_specializations` |

Run pending updates with:

```bash
vendor/bin/drush updb -y
vendor/bin/drush cr
```

---

## 16. Installation Guide

### Prerequisites

- Drupal 10 or 11 site installed and running
- MySQL / MariaDB database accessible
- Drush installed (`vendor/bin/drush`)
- `telephone` core module (or contrib equivalent) available

### Steps

1. **Copy the module** into `web/modules/custom/appointment/`.

2. **Enable the module:**

   ```bash
   vendor/bin/drush en appointment -y
   ```

3. **Run the database update hooks** (only needed if the module was previously installed without the Phase 1 fields):

   ```bash
   vendor/bin/drush updb -y
   ```

4. **Rebuild the cache:**

   ```bash
   vendor/bin/drush cr
   ```

5. **Create the `appointment_type` taxonomy vocabulary** in the Drupal UI at `/admin/structure/taxonomy` and add terms (e.g. *Career Counseling*, *Financial Advice*, *Legal Consultation*).

6. **Create at least one Agency** at `/agency/add`.

7. **Create an adviser user:**
   - Go to `/admin/people/create`
   - Assign the `adviser` role
   - Fill in **Agency**, **Working hours** (JSON), and **Specialisations**

8. **Visit `/book-an-appointment`** to test the booking wizard end-to-end.

### Working hours JSON format

```json
{
  "mon": ["09:00", "17:00"],
  "tue": ["09:00", "17:00"],
  "wed": ["09:00", "12:00"],
  "thu": ["09:00", "17:00"],
  "fri": ["09:00", "16:00"]
}
```

Keys: `mon`, `tue`, `wed`, `thu`, `fri`, `sat`, `sun`. Value is `[startTime, endTime]` in `H:i` format.

---

## 17. Development Phases — Progress

### ✅ Phase 1 — Foundation

| Task | Status |
|------|--------|
| Module structure and scaffolding | ✅ Done |
| `appointment_agency` custom entity with all booking fields | ✅ Done |
| `appointment` custom entity with all booking fields | ✅ Done |
| Adviser fields on User entity via `hook_entity_base_field_info()` | ✅ Done |
| Database update hooks (`appointment_update_10001/10002/10003`) | ✅ Done |
| Permissions file | ✅ Done |
| Basic CRUD admin interface for both entities | ✅ Done |
| English source strings (French via translation mechanism) | ✅ Done |

### ✅ Phase 2 — Core Functionality

| Task | Status |
|------|--------|
| `AppointmentManagerService` (slot engine, double-booking prevention, entity creation) | ✅ Done |
| `EmailService` (confirm / modify / cancel) | ✅ Done |
| `AppointmentSubmitForm` — 6-step FAPI wizard with TempStore + AJAX | ✅ Done |
| `AppointmentController` (wizard page, JSON slots API, user dashboard) | ✅ Done |
| Front-end routes (`/book-an-appointment`, `/my-appointments`, `/api/…`) | ✅ Done |
| `hook_mail()` — three email templates | ✅ Done |
| Entity insert/update hooks → trigger emails | ✅ Done |
| CSS (wizard progress bar, cards, slots, buttons) | ✅ Done |
| JS (dynamic slot loading on date change) | ✅ Done |
| User dashboard Twig template | ✅ Done |
| Cache rebuild confirmed — all routes live | ✅ Done |

### ✅ Phase 3 — Advanced Features

| Task | Status |
|------|--------|
| FullCalendar.io v6 integration for date selection | ✅ Done |
| Admin appointment list View with filters (date, agency, adviser) | ✅ Done |
| CSV export with Drupal Batch API | ⬜ Pending |
| CSV import for Agencies and Advisers | ✅ Done |
| Email notifications via Hybrid Queue (Immediate + Cron fallback) | ✅ Done |
| Appointment modification form (`AppointmentModifyForm`) | ✅ Done |
| Appointment cancellation form with confirmation | ✅ Done |
| Module settings form (`appointment.settings` config) | ✅ Done |

### 🔲 Phase 4 — Polish & Delivery (Pending)

| Task | Status |
|------|--------|
| Input sanitisation review | ⬜ Pending |
| CSRF and access control audit | ⬜ Pending |
| PHPDoc / coding standards review | ⬜ Pending |
| French `.po` translation file | ⬜ Pending |
| Default content / CSV seed data for demo | ⬜ Pending |
| Final README and installation guide | ⬜ Pending |

---

*Generated: March 17, 2025*
