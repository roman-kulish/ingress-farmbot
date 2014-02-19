CREATE TABLE inventory(
  guid TEXT NOT NULL PRIMARY KEY,
  type TEXT NOT NULL,
  timestamp INT NOT NULL,
  level INT DEFAULT NULL,
  rarity TEXT DEFAULT NULL
);

CREATE TABLE portals (
  guid TEXT NOT NULL PRIMARY KEY,
  name TEXT NOT NULL,
  faction INT NOT NULL,
  lat REAL NOT NULL,
  lng REAL NOT NULL,
  level INT NOT NULL,
  timestamp INT NOT NULL,
  last_hack_time INT DEFAULT NULL,
  burnt_out_time INT DEFAULT NULL
);

CREATE TABLE energy (
  guid TEXT NOT NULL PRIMARY KEY,
  lat REAL NOT NULL,
  lng REAL NOT NULL,
  amount INT NOT NULL
);

CREATE INDEX idx_inventory_type ON inventory(type);

CREATE INDEX idx_portals_faction ON portals(faction);
CREATE INDEX idx_portals_last_hack_time ON portals(last_hack_time);
CREATE INDEX idx_portals_burnt_out ON portals(burnt_out_time);