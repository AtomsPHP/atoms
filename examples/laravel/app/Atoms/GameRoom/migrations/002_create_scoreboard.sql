CREATE TABLE scoreboard (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    home INTEGER NOT NULL DEFAULT 0 CHECK (home >= 0),
    away INTEGER NOT NULL DEFAULT 0 CHECK (away >= 0),
    previous_home INTEGER,
    previous_away INTEGER,
    clear_id TEXT
);

INSERT INTO scoreboard (id) VALUES (1);
