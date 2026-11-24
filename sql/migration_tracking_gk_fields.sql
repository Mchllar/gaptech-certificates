-- Government (GK) fleet sheets carry a civilian plate and the tracker's SIM card as well.
-- Both are kept on the record and shown in the system, not printed. Safe to run more than once.
ALTER TABLE tracking_certificates ADD COLUMN IF NOT EXISTS civilian_plate text NOT NULL DEFAULT '';
ALTER TABLE tracking_certificates ADD COLUMN IF NOT EXISTS sim_card       text NOT NULL DEFAULT '';
