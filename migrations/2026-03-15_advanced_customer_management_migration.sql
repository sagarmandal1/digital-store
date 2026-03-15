-- Migration script for advanced customer management

-- Create table for customer tags
CREATE TABLE IF NOT EXISTS customer_tags (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- Create table for customer attachments
CREATE TABLE IF NOT EXISTS customer_attachments (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers(id),
    file_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for customer activity log
CREATE TABLE IF NOT EXISTS customer_activity_log (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers(id),
    activity_type VARCHAR(255) NOT NULL,
    activity_detail TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for customer contact history
CREATE TABLE IF NOT EXISTS customer_contact_history (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers(id),
    contact_method VARCHAR(255) NOT NULL,
    contact_detail TEXT,
    contacted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for customer notes
CREATE TABLE IF NOT EXISTS customer_notes (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers(id),
    note TEXT NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for extension sessions
CREATE TABLE IF NOT EXISTS extension_sessions (
    id SERIAL PRIMARY KEY,
    customer_id INT REFERENCES customers(id),
    session_data JSONB NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Create table for bulk contact queue
CREATE TABLE IF NOT EXISTS bulk_contact_queue (
    id SERIAL PRIMARY KEY,
    contact_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Add any necessary indexes or constraints
