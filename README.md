# Appointment Booking Module

A comprehensive **appointment booking system** for Drupal 10/11. Customers book appointments with specialised advisers at agency branches through an interactive 6-step wizard powered by FullCalendar.

## Features

| Category | Details |
|----------|---------|
| **Booking Wizard** | 6-step guided form at `/book-an-appointment` with AJAX navigation and TempStore state |
| **Calendar** | FullCalendar v6 TimeGrid for interactive date/time slot selection with live availability |
| **Entities** | Two custom content entities: **Agency** and **Appointment** (revisionable, publishable) |
| **Adviser Profiles** | Extended User entity with agency assignment, JSON working hours, and taxonomy specialisations |
| **Slot Engine** | Calculates availability from adviser hours, subtracts existing bookings, prevents double-booking |
| **Email Notifications** | Confirmation, modification, and cancellation emails to both customer and adviser via Queue API |
| **User Dashboard** | `/my-appointments` — view, edit, and cancel bookings |
| **Admin Dashboard** | Views-based listing at `/admin/structure/appointment` with filters, bulk actions, and CSV export |
| **CSV Import** | Bulk import agencies and advisers at `/admin/config/appointment/import` |
| **CTA Block** | "Book an Appointment" block plugin for placement anywhere on the site |
| **Modification Flow** | 3-step lookup → verify → edit flow for appointment modification |
| **Cancellation** | Confirmation form with ownership check and soft-delete |

## Requirements

- **Drupal** 10.x or 11.x
- **PHP** 8.1+
- **Core modules:** `datetime`, `telephone`, `taxonomy`, `user`, `file`
- **Composer:** `league/csv` (`composer require league/csv`)
- **Migration (optional):** `migrate_plus`, `migrate_source_csv`, `migrate_tools`

## Installation

```bash
# 1. Navigate to the custom modules folder
cd /path/to/site/web/modules/custom/

# Clone the repository and force the folder to be named 'appointment'
git clone https://github.com/lazaar-void/booking-site.git appointment

# 2. Navigate back to your main Drupal project root
cd /path/to/site/

# Install the PHP library and Drupal dependencies
composer require league/csv drupal/csv_serialization drupal/views_data_export \
  drupal/migrate_plus drupal/migrate_source_csv drupal/migrate_tools

# 3. Enable your custom module
drush en appointment -y

# 4. Clear the cache to ensure all entity plugins and routing are registered
drush cr
```

## Initial Configuration

1. **Create the `adviser` role** at `/admin/people/roles` (if not auto-created)
2. **Add appointment types** at `/admin/structure/taxonomy/manage/appointment_type/overview`
   (e.g., Financial Advice, Legal Consultation, Career Counseling)
3. **Create agencies** at `/agency/add` or bulk import via `/admin/config/appointment/import`
4. **Create adviser users** — assign the `adviser` role, then set their agency, working hours, and specialisations on their user profile
5. **Configure slot duration** at `/admin/structure/appointment/settings` (default: 30 min)

## Quick Start

| Who | URL | What |
|-----|-----|------|
| Anyone | `/book-an-appointment` | Start the booking wizard |
| Logged-in user | `/my-appointments` | View/edit/cancel bookings |
| Anyone | `/appointment/modify` | Modify a booking by reference + email |
| Admin | `/admin/structure/appointment` | Manage all appointments |
| Admin | `/admin/content/agency` | Manage agencies |
| Admin | `/admin/config/appointment/import` | CSV import tool |

## Module Structure

```
appointment/
├── migrations/              # Migrate API YAML definitions
│   ├── appointment_agencies.yml
│   ├── appointment_advisers.yml
│   └── appointment_appointments.yml
├── config/install/          # Bulk action configs (auto-imported on install)
├── css/appointment.css      # Wizard, dashboard, and hours widget styles
├── js/
│   ├── appointment.js       # Legacy slot loader (fallback)
│   └── appointment-calendar.js  # FullCalendar integration
├── sample_data/             # Sample CSV files for migration/import
│   ├── agencies.csv
│   ├── advisers.csv
│   └── appointments.csv
├── scripts/
│   └── generate_appointments.php  # Performance test: generates 1000 appointments
├── src/
│   ├── Controller/AppointmentController.php
│   ├── Entity/
│   │   ├── Agency.php
│   │   └── Appointment.php
│   ├── Form/
│   │   ├── AgencyForm.php             # Operating hours widget
│   │   ├── AppointmentSubmitForm.php   # 6-step booking wizard
│   │   ├── AppointmentCancelForm.php   # Cancel confirmation
│   │   ├── AppointmentLookupForm.php   # Modification step 1
│   │   ├── AppointmentVerifyForm.php   # Modification step 2
│   │   ├── AppointmentModifyForm.php   # Modification step 3
│   │   └── ImportCsvForm.php           # CSV import UI
│   ├── Plugin/
│   │   ├── Block/AppointmentBlock.php  # CTA block
│   │   └── QueueWorker/AppointmentEmailWorker.php
│   └── Service/
│       ├── AppointmentManagerService.php  # Slot engine + entity ops
│       ├── CsvImporter.php                # CSV parsing
│       └── EmailService.php               # Email dispatch
├── templates/               # Twig templates
├── appointment.module       # Hooks (theme, mail, form alter, entity hooks)
├── appointment.routing.yml  # All routes
├── appointment.services.yml # DI service definitions
└── appointment.permissions.yml
```

## CSV Import Format

**Agencies** — `Name, Address, Phone, Email, Operating Hours`
```csv
"Central Branch","123 Main St, NYC","555-0101",central@example.com,"{""mon"":[""09:00"",""17:00""],""fri"":[""09:00"",""16:00""]}"
```

**Advisers** — `Username, Email, Password, Agency Name, Working Hours, Specializations`
```csv
"jdoe","jdoe@example.com","pass123","Central Branch","{""mon"":[""09:00"",""12:00""]}","Financial Advice, Tax Planning"
```

> **Import order:** Agencies first, then Advisers (advisers reference agencies by name).

## Data Migration (Migrate API)

The module includes 3 migration definitions in `migrations/` that can seed the site from CSV files in `sample_data/`:

| Migration | Source CSV | Creates | Depends on |
|-----------|-----------|---------|------------|
| `appointment_agencies` | `agencies.csv` | Agency entities | — |
| `appointment_advisers` | `advisers.csv` | User entities (adviser role) | agencies |
| `appointment_appointments` | `appointments.csv` | Appointment entities | agencies + advisers |

```bash
# Import all migrations (respects dependency order)
drush migrate:import --group=appointment

# Check status
drush migrate:status --group=appointment

# Rollback all (reverse order)
drush migrate:rollback --group=appointment
```

> **Advisers** are linked to agencies via `migration_lookup`. **Appointment types** (taxonomy terms) are created automatically if they don't exist via `entity_generate`.

## Documentation

- **[DOCUMENTATION.md](DOCUMENTATION.md)** — Full technical reference (entities, services, hooks, routing, permissions, templates)
- **[IMPLEMENTATION_DOC.md](IMPLEMENTATION_DOC.md)** — Architecture overview and implementation details
