CREATE TABLE counter_stats (
    id INTEGER PRIMARY KEY,
    stat_key TEXT NOT NULL UNIQUE,
    stat_value TEXT,
    recorded_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
