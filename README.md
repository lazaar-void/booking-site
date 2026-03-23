# Appointment Booking Module

The **Appointment** module provides a professional, end-to-end appointment booking system for Drupal 10/11. It allows customers to book appointments with specialized advisers at different branch locations (agencies) through a modern, interactive multi-step wizard.

## Key Features

- **6-Step Booking Wizard**: A guided user experience at `/book-an-appointment`.
- **FullCalendar Integration**: Interactive date and time slot selection with real-time availability.
- **Agency Management**: Custom entities to manage branch locations, contact info, and operating hours.
- **Adviser Profiles**: Extended user profiles with agency assignment, working hours, and specializations.
- **CSV Import Tool**: Bulk import agencies and advisers at `/admin/config/appointment/import`.
- **Admin Dashboard**: Centralized management at `/admin/structure/appointment` with list, settings, and field management tabs.
- **Transactional Emails**: Automatic notifications for booking confirmation, modification, and cancellation via Queue API.

## Requirements

- Drupal 10.x or 11.x
- PHP 8.1+
- Core modules: `datetime`, `telephone`, `taxonomy`, `user`, `file`
- Vendor libraries: `league/csv`

## Installation

1. Copy the `appointment` module into your `modules/custom` directory.
2. Enable the module via Drush: `drush en appointment` or through the Extend menu.
3. The module automatically creates two entities (`appointment_agency` and `appointment`) and adds fields to the User entity.

## Configuration

1. **Taxonomy**: Add terms to the `appointment_type` vocabulary (e.g., Financial Advice, Legal Consultation).
2. **Agencies**: Create your branch locations at `/agency/add` or use the CSV Import tool.
3. **Advisers**: Assign the `adviser` role to relevant users and configure their working hours and agency in their user profile.
4. **Settings**: Configure the default slot duration (e.g., 30 minutes) at `/admin/structure/appointment/settings`.

## Usage

- **Customers**: Visit `/book-an-appointment` to start a booking.
- **Logged-in Users**: View their personal bookings at `/my-appointments`.
- **Administrators**: Manage all bookings and system settings at `/admin/structure/appointment`.

## Support

For technical details, see the `DOCUMENTATION.md` and `IMPLEMENTATION_DOC.md` files in the module directory.
