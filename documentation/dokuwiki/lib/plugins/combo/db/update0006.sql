
alter table PAGES add column DATE_MODIFIED TEXT;
alter table PAGES add column DATE_CREATED TEXT;
alter table PAGES add column DATE_PUBLISHED TEXT;
alter table PAGES add column PATH TEXT;
alter table PAGES add column NAME TEXT;
alter table PAGES add column TITLE TEXT;
alter table PAGES add column H1 TEXT;

create index if not exists PAGES_DATE_MODIFED ON PAGES (DATE_MODIFIED DESC);
create index if not exists PAGES_DATE_CREATED ON PAGES (DATE_CREATED DESC);
create index if not exists PAGES_DATE_PUBLISHED ON PAGES (DATE_CREATED DESC);
create index if not exists PAGES_PATH ON PAGES (PATH ASC);
create index if not exists PAGES_NAME ON PAGES (NAME ASC);





