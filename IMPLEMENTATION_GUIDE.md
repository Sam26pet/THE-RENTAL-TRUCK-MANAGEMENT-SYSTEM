# Rental Truck System

## Booking flow

1. A logged-in customer selects one available truck.
2. The customer enters pickup, destination, trip date, and cargo details.
3. The map calculates the driving route and distance.
4. The system calculates the full trip price using the truck rate.
5. Eligible customers receive the  discount after five completed bookings.
6. Payment creates one booking for one customer and reserves the truck.
7. The booking, route, payment status, truck, and assigned driver appear in the customer and admin dashboards.

## Main features

- Truck registration, search, status management, and image uploads.
- Customer booking history with route details and saved coordinates.
- Leaflet route maps for new bookings and saved bookings.
- Bank and mobile payment flows with receipts and reference lookup.
- Loyalty discount: 10% after five completed paid bookings.
- Admin management for bookings, trucks, customers, payments, feedback, and drivers.
- Driver assignment and truck release controls.
- Feedback and SMS notification support where enabled by the application.

## Database

Run `database_schema.sql` on a new installation. Existing installations are migrated by the application to remove legacy shared-booking structures and keep the single-customer booking schema.

## Testing

- Log in as a customer and book an available truck.
- Confirm that the route and distance are calculated before payment.
- Complete payment and verify the receipt reference.
- Open the customer dashboard and confirm the booking, route, payment, truck, and driver information.
- Open the admin dashboard and confirm the customer's name and booking details are visible.
- After five completed paid bookings, verify that the 10% loyalty discount is shown and applied.
- Release the booking from the admin dashboard and confirm the truck becomes available again.
