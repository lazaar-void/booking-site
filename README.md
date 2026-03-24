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

## Installation

```bash
#clone inside modules/custom/appointment/
cd /path/to/site/web/modules/custom/appointment/
git clone https://github.com/lazaar-void/booking-site.git

# 2. Install the dependency
composer require league/csv

# 3. Enable the module
drush en appointment -y

# 5. Clear cache
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
├── config/install/          # Bulk action configs (auto-imported on install)
├── css/appointment.css      # Wizard, dashboard, and hours widget styles
├── js/
│   ├── appointment.js       # Legacy slot loader (fallback)
│   └── appointment-calendar.js  # FullCalendar integration
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

## Documentation

- **[DOCUMENTATION.md](DOCUMENTATION.md)** — Full technical reference (entities, services, hooks, routing, permissions, templates)
- **[IMPLEMENTATION_DOC.md](IMPLEMENTATION_DOC.md)** — Architecture overview and implementation details
