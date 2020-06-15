### Tina4 Class and Function Overview

The purpose of this document is to outline what Tina4 capabilities exist, and what is available for use in projects. As this is a working document, parts of this may be old or incomplete. Each section shows the release version at which the documentation was correct.

-----------------------------

#### Class ORM (v0.0.60)

This class ```implements JsonSerializable```, which allows it to redirect the PHP ```json_encode()``` to it's own ```jsonSerialize()```

**Object creation:** The  creation of the ORM object is handled by two functions together.

```function __construct($request, $fromDB, $tableName, $fieldMapping, $primaryKey, $tableFilter, $DBA)``` which is the PHP constructor used when an object is created. This is essentially empty, passing all the parameters through to the create funtion. 

```function create($request, $fromDB, $tableName, $fieldMapping, $primaryKey, $tableFilter, $DBA)``` does all the creation work. It populates the object with the ```$request``` variable, and can handle an array, object or JSON decodable string. If the ```$request``` is directly from the database, and ```$fromDB``` is true, then it will get the property names from the table field names, provided the ```$propertyName``` is ```property_name``` in the database table.

**Table Name resolution:** There is built in table name resolution for ```$tableName```, in the order of the passed variable, the class set variable, or from the class name. This is handled by ```function getTableName```.

**Field Mapping:** There is built in fieldMapping where 'objectName' => 'object_name' automatically without any declartion needed. Manual field mapping is possible by creating ```$fieldMapping = ['objectName' => 'databasename']``` in the object class where the ```databasename``` is written as per the database requirements. This is handled by ```function getFieldName()``` and ```function getObjectName()```.

**Entry Points:** With ORM naturally used as an extension to data objects, there are a number of natural entry points that can be used.

**```function save($tableName, $fieldMapping)```** saves the object data into the database. It will do an insert if the record does not exist, or update if a record is found. It then returns the newly updated object.

**```function load($filter="", $tableName="", $fieldMapping=[])```** loads a record from the database into the object. Especially useful for loading a single record, as it returns false if the record is not found. Multiple row datasets should rather use the ```select``` function. *Process:* ```function getTableName()``` resolves the table name. ```function checkDBConnection()``` will check the connection to the database, and checks if the table exists. If the table does not exist it will create a new table. ```$filter``` will set which record is retrieved from the table. If blank, it will return the records with a null primary key, which will usually yield an empty dataset. ```function getObjectData()``` will connect the incoming field to the correct data object parameter. The data object is loaded field by field.

function find(params): An alias for load.

function delete(params): Deletes a record from the database

**Helper Functions:** There are a number of functions that are not designed to be accessed directly, but to assist the entry point functions in their operations.





**```function getFieldName($name, $fieldMapping=[])```** given an object name trys to resolve a database name. This is the opposite of ```function getObjectName()```. 

**```function getObjectName($name, $dbResult=false)```** given a database name tries to resolve an object parameter name. This is the opposite of ```function getFieldName()```

function generateInsertSQL(params): Generates an insert SQL given the table data and table Name.

function generateUpdateSQL(params): Generates an update SQL given the table data and table Name with an update filter.

function generateDeleteSQL(params): Generates a delete SQL given the table name and delete filter.

function getTableData(params): Gets the information to generate a REST friendly object containing all the table data.

function getRecords(params): Gets records from a table

function checkDBConnection(): Checks if there is a valid database connection.

function getPrimaryCheck(params): Helper function to get the filter for the primary key.

function getTableName(params): Works out the table name from the class name.

function __toString(): returns a JSON string of the table structure

function jsonSerialize(): makes a neat Json response. Seemingly empty function still.

function select(params): Selects a set of data records from the Database. 

function hasMany(): Empty function to be used for foreign key relationships

function hasOne(): Empty function to be used for foreign key relationships

function belongsTo(): Empty function to be used for foreign key relationships

function generateCRUD(params): Possibly generates a CRUD template for Tina4

------------------------------

#### Class Response (v0.0.60)

The class consists of a single function, which returns an HTTP response. If ```$content``` is empty it will return ```TEXT_HTML```. Alternatively it will return an ```APPLICATION_JSON``` or ```APPLICATION_XML``` response.

```
function __invoke($content, $httpCode, $contentType)
```
```$contentType```, if empty, will try to get it from the ```$_SERVER``` variable, if set, alternatively it will set it to ```TEXT_HTML```.

```$httpCode``` will default to ```200, HTTP_OK``` if not set.

```$content``` can be anything. If it is ```APPLICATION_XML```, it is returned as received. However the ```$content``` of any other ```$contentType``` will be returned in JSON, and the ```$contentType``` will be set to ```APPLICATION_JSON```.