-- No affinity on value: SQLite must receive the bound value as an integer.
CREATE TABLE probe_rows (key TEXT PRIMARY KEY, value NOT NULL CHECK (typeof(value) = 'integer'));
CREATE TABLE probe_columns AS SELECT 'another unread row' AS marker;
