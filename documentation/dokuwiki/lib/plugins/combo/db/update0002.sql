-- The pages meta
CREATE TABLE PAGES
(
    ID        TEXT NOT NULL, -- The page id
    CANONICAL TEXT NOT NULL  -- The canonical value should be unique
);
CREATE UNIQUE INDEX PAGES_UK ON PAGES (ID, CANONICAL);


-- one canonical, multiple page id alias
CREATE TABLE PAGES_ALIAS
(
    CANONICAL TEXT,  -- The canonical value
    ALIAS     TEXT   -- The alias value
);

CREATE UNIQUE INDEX PAGES_ALIAS_UK ON PAGES_ALIAS (CANONICAL, ALIAS);
