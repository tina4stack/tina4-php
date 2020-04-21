### Tina4 Class and Function Overview

The purpose of this document is to outline what Tina4 capabilities exist, and what is available for use in projects. As this is a working document, parts of this may be old or incomplete. Each section shows the release version at which the documentation was correct.

#### Class ORM (v0.0.60)

function __construct(params): The ORM constructor

function create(params): Allows ORM to be created.

function getFieldName(params): Gets the required field name from the database table.

function getObjectName(params): Gets the correct object name.

function generateInsertSQL(params): Generates an insert SQL given the table data and table Name.

function generateUpdateSQL(params): Generates an update SQL given the table data and table Name with an update filter.

function generateDeleteSQL(params): Generates a delete SQL given the table name and delete filter.

function getTableData(params): Gets the information to generate a REST friendly object containing all the table data.

function getRecords(params): Gets records from a table

function checkDBConnection(): Checks if there is a valid database connection.

function getPrimaryCheck(params): Helper function to get the filter for the primary key.

function getTableName(params): Works out the table name from the class name.

function save(params): Saves the object data into the database, selecting if it should use an Insert or Update method, depending on the check if the record already exists in the database.

function load(params): Loads a record from the database into the object

function delete(params): Deletes a record from the database

function find(params): An alias for load.

function __toString(): returns a JSON string of the table structure

function jsonSerialize(): makes a neat Json response. Seemingly empty function still.

function select(params): Selects a set of data records from the Database. 

function hasMany(): Empty function to be used for foreign key relationships

function hasOne(): Empty function to be used for foreign key relationships

function belongsTo(): Empty function to be used for foreign key relationships

function generateCRUD(params): Possibly generates a CRUD template for Tina4

#### Class Response (v0.0.60)

The class consists of a single function, which returns an HTTP response. If ```$content``` is empty it will return ```TEXT_HTML```. Alternatively it will return an ```APPLICATION_JSON``` or ```APPLICATION_XML``` response.

```
function __invoke($content, $httpCode, $contentType)
```
```$contentType```, if empty, will try to get it from the ```$_SERVER``` variable, if set, alternatively it will set it to ```TEXT_HTML```.

```$httpCode``` will default to ```200, HTTP_OK``` if not set.

```$content``` can be anything. If it is ```APPLICATION_XML```, it is returned as received. However the ```$content``` of any other ```$contentType``` will be returned in JSON, and the ```$contentType``` will be set to ```APPLICATION_JSON```.