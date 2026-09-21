CREATE DATABASE IF NOT EXISTS rental_truck
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE rental_truck;

CREATE TABLE IF NOT EXISTS register (
    Rid INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    fullname VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    mobileno VARCHAR(30) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_register_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS trucks (
    truck_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_name VARCHAR(150) NOT NULL,
    plate_number VARCHAR(50) NOT NULL,
    capacity VARCHAR(80) NOT NULL,
    fuel_type VARCHAR(50) NOT NULL,
    cargo_type VARCHAR(80) NOT NULL,
    price_per_km DECIMAL(10,2) NOT NULL DEFAULT 0,
    image_url VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_trucks_plate_number (plate_number),
    KEY idx_trucks_cargo_status (cargo_type, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS booking (
    booking_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    truck_id INT UNSIGNED NULL,
    truck_name VARCHAR(150) NULL,
    booking_status VARCHAR(20) NOT NULL DEFAULT 'active',
    fullname VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    trip_date DATE NOT NULL,
    pickup VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    distance DECIMAL(10,2) NOT NULL DEFAULT 0,
    original_cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    cost DECIMAL(10,2) NOT NULL DEFAULT 0,
    assigned_driver_name VARCHAR(120) NULL,
    assigned_driver_phone VARCHAR(30) NULL,
    assigned_driver_email VARCHAR(150) NULL,
    assigned_driver_license VARCHAR(80) NULL,
    assigned_driver_photo VARCHAR(255) NULL,
    pickup_lat DECIMAL(10,7) NULL,
    pickup_lng DECIMAL(10,7) NULL,
    destination_lat DECIMAL(10,7) NULL,
    destination_lng DECIMAL(10,7) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_booking_email (email),
    KEY idx_booking_truck_status (truck_id, booking_status),
    KEY idx_booking_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS payments (
    payment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NULL,
    fullname VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    payfor VARCHAR(80) NOT NULL DEFAULT 'Truck booking',
    method VARCHAR(40) NOT NULL,
    bankname VARCHAR(100) NULL,
    account_number VARCHAR(100) NULL,
    simcardprovider VARCHAR(50) NULL,
    phone_number VARCHAR(30) NULL,
    amount DECIMAL(10,2) NOT NULL DEFAULT 0,
    payment_reference VARCHAR(80) NOT NULL,
    payment_status VARCHAR(20) NOT NULL DEFAULT 'paid',
    paid_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_payment_reference (payment_reference),
    KEY idx_payments_booking (booking_id),
    KEY idx_payments_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS feedback (
    feedback_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    full_name VARCHAR(120) NULL,
    name VARCHAR(120) NULL,
    email VARCHAR(150) NULL,
    rating TINYINT UNSIGNED NOT NULL,
    message TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_feedback_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS admins (
    admin_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS drivers (
    driver_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    full_name VARCHAR(120) NOT NULL,
    phone VARCHAR(30) NOT NULL,
    email VARCHAR(150) NULL,
    license_number VARCHAR(80) NOT NULL,
    photo_url VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_drivers_email (email),
    UNIQUE KEY uq_drivers_license (license_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS truck_driver_assignments (
    assignment_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    booking_id INT UNSIGNED NOT NULL,
    truck_id INT UNSIGNED NOT NULL,
    driver_id INT UNSIGNED NOT NULL,
    assigned_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_assignment_booking (booking_id),
    KEY idx_assignment_driver (driver_id),
    KEY idx_assignment_truck (truck_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

