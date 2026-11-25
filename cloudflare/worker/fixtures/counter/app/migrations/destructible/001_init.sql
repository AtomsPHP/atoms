CREATE TABLE destructible_state (
    id INTEGER PRIMARY KEY CHECK (id = 1),
    tag TEXT NOT NULL DEFAULT 'initial',
    counter INTEGER NOT NULL DEFAULT 0,
    deact_mode TEXT NOT NULL DEFAULT 'normal',
    audit_label TEXT NOT NULL DEFAULT ''
);

INSERT INTO destructible_state (id, tag, counter, deact_mode, audit_label)
VALUES (1, 'initial', 0, 'normal', '');

CREATE TABLE destructible_events (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,
    detail TEXT NOT NULL
);
