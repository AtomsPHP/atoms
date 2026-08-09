CREATE TABLE counter_state (
    id INTEGER PRIMARY KEY,
    value INTEGER NOT NULL DEFAULT 0
);

INSERT INTO counter_state (id, value) VALUES (1, 0);

CREATE TABLE counter_activations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    atom_id TEXT NOT NULL,
    activated_at TEXT NOT NULL
);
