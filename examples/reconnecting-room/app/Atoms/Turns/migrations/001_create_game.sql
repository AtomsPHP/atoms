CREATE TABLE players (
    seat INTEGER PRIMARY KEY CHECK (seat IN (1, 2)),
    player TEXT NOT NULL UNIQUE,
    socket TEXT
);

CREATE TABLE game (revision INTEGER NOT NULL, last_move TEXT);
INSERT INTO game VALUES (0, NULL);
