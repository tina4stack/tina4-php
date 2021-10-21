-- The id that needs a refresh
create table ANALYTICS_TO_REFRESH (
     ID          TEXT NOT NULL PRIMARY KEY, -- The page id
     TIMESTAMP   TIMESTAMP NOT NULL, -- the timestamp
     REASON      TEXT NOT NULL -- the reason
);

