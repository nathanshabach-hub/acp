-- Scheduling Wizard day strategy: 'earliest', 'round_robin' or 'balanced'.
-- NULL keeps the old behaviour (round_robin_day_distribution_yes_no = 1 -> round_robin, otherwise earliest).
ALTER TABLE schedulings
    ADD COLUMN schedule_band_mode VARCHAR(20) NULL DEFAULT NULL
    AFTER round_robin_day_distribution_yes_no;
