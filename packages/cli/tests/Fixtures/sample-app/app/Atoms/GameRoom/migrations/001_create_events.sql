CREATE TABLE game_room_events (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    payload    TEXT    NOT NULL,
    created_at TEXT    NOT NULL DEFAULT (datetime('now'))
);
