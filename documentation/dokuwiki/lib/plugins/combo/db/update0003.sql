-- Add the type (ie http or id)
alter table REDIRECTIONS_LOG add column METHOD TEXT;

-- Rename redirection to page rules
create table PAGE_RULES_tmp
(
    ID                 INTEGER PRIMARY KEY,
    MATCHER            TEXT unique NOT NULL, -- the matcher pattern
    TARGET             TEXT NOT NULL,        -- the target
    PRIORITY           INTEGER NOT NULL,     -- the priority in which the match must be performed
    TIMESTAMP          TIMESTAMP  NOT NULL  -- a update/create timestamp
);

insert into PAGE_RULES_tmp(ID, MATCHER, TARGET, PRIORITY, TIMESTAMP)
select NULL, SOURCE, TARGET, 1, CREATION_TIMESTAMP
from REDIRECTIONS;
drop table REDIRECTIONS;
alter table PAGE_RULES_tmp rename to PAGE_RULES;


