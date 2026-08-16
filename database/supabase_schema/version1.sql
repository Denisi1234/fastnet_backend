-- Version 1: Physical Room Details Schema Migration
-- Added room details: capacity, pricing overrides, amenities, photos, description, floor, beds, and sizing columns.
-- Target: Supabase / PostgreSQL Data Layer

ALTER TABLE rooms 
  ADD COLUMN IF NOT EXISTS room_type_id VARCHAR(255),
  ADD COLUMN IF NOT EXISTS price DECIMAL(12, 2) DEFAULT 0.00,
  ADD COLUMN IF NOT EXISTS capacity INTEGER DEFAULT 1,
  ADD COLUMN IF NOT EXISTS amenities TEXT, -- JSON array of room amenities (e.g. WiFi, AC)
  ADD COLUMN IF NOT EXISTS photos TEXT,    -- JSON array of room-specific image URLs
  ADD COLUMN IF NOT EXISTS description TEXT,
  ADD COLUMN IF NOT EXISTS floor VARCHAR(255),
  ADD COLUMN IF NOT EXISTS max_adults INTEGER DEFAULT 1,
  ADD COLUMN IF NOT EXISTS max_children INTEGER DEFAULT 0,
  ADD COLUMN IF NOT EXISTS bed_configuration VARCHAR(255),
  ADD COLUMN IF NOT EXISTS number_of_beds INTEGER DEFAULT 1,
  ADD COLUMN IF NOT EXISTS room_size VARCHAR(255);

COMMENT ON COLUMN rooms.amenities IS 'JSON array of room amenities (e.g. WiFi, AC)';
COMMENT ON COLUMN rooms.photos IS 'JSON array of room-specific image URLs';
