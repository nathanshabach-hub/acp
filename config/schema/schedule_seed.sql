ALTER TABLE schedulings
ADD COLUMN schedule_seed INT NULL
AFTER schedule_band_mode;
