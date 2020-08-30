<?php
namespace Tina4;

if (!defined("TINA4_SUPPRESS")) define ("TINA4_SUPPRESS", false);

//CSFR
if (!defined("TINA4_SECURE")) define ("TINA4_SECURE", true); //turn off on localhost for development, defaults to secure on webserver

//Debug Types
if(!defined("DEBUG_CONSOLE")) define("DEBUG_CONSOLE", 9001);
if(!defined("DEBUG_SCREEN"))define("DEBUG_SCREEN", 9002);
if(!defined("DEBUG_ALL"))define("DEBUG_ALL", 9003);
if(!defined("DEBUG_NONE")) define("DEBUG_NONE", 9004);

if (!defined("HTTP_OK")) define("HTTP_OK", 200);
if (!defined("HTTP_CREATED")) define("HTTP_CREATED", 201);
if (!defined("HTTP_NO_CONTENT")) define("HTTP_NO_CONTENT", 204);
if (!defined("HTTP_BAD_REQUEST")) define("HTTP_BAD_REQUEST", 400);
if (!defined("HTTP_UNAUTHORIZED")) define("HTTP_UNAUTHORIZED", 401);
if (!defined("HTTP_FORBIDDEN")) define("HTTP_FORBIDDEN", 403);
if (!defined("HTTP_NOT_FOUND")) define("HTTP_NOT_FOUND", 404);
if (!defined("HTTP_METHOD_NOT_ALLOWED")) define("HTTP_METHOD_NOT_ALLOWED", 405);
if (!defined("HTTP_RESOURCE_CONFLICT")) define("HTTP_RESOURCE_CONFLICT", 409);
if (!defined("HTTP_MOVED_PERMANENTLY")) define("HTTP_MOVED_PERMANENTLY", 301);
if (!defined("HTTP_INTERNAL_SERVER_ERROR")) define("HTTP_INTERNAL_SERVER_ERROR", 500);
if (!defined("HTTP_NOT_IMPLEMENTED")) define("HTTP_NOT_IMPLEMENTED", 501);


if (!defined("TINA4_POST")) define("TINA4_POST", "POST");
if (!defined("TINA4_GET")) define("TINA4_GET", "GET");
if (!defined("TINA4_ANY")) define("TINA4_ANY", "ANY");
if (!defined("TINA4_PUT")) define("TINA4_PUT", "PUT");
if (!defined("TINA4_PATCH")) define("TINA4_PATCH", "PATCH");
if (!defined("TINA4_DELETE")) define("TINA4_DELETE", "DELETE");
if (!defined("TINA4_DOCUMENT_ROOT")) define ("TINA4_DOCUMENT_ROOT", null);


if (!defined("TEXT_HTML")) define("TEXT_HTML", "text/html");
if (!defined("TEXT_PLAIN")) define("TEXT_PLAIN", "text/plain");
if (!defined("APPLICATION_JSON")) define("APPLICATION_JSON", "application/json");
if (!defined("APPLICATION_XML")) define("APPLICATION_XML", "application/xml");

if (!defined("DATA_ARRAY")) define("DATA_ARRAY", 0);
if (!defined("DATA_OBJECT")) define("DATA_OBJECT", 1);
if (!defined("DATA_NUMERIC")) define("DATA_NUMERIC", 2);
if (!defined("DATA_TYPE_TEXT")) define("DATA_TYPE_TEXT", 0);
if (!defined("DATA_TYPE_NUMERIC")) define("DATA_TYPE_NUMERIC", 1);
if (!defined("DATA_TYPE_BINARY")) define("DATA_TYPE_BINARY", 2);
if (!defined("DATA_ALIGN_LEFT")) define("DATA_ALIGN_LEFT", 0);
if (!defined("DATA_ALIGN_RIGHT")) define("DATA_ALIGN_RIGHT", 1);
if (!defined("DATA_CASE_UPPER")) define("DATA_CASE_UPPER", 1);
if (!defined("DATA_NO_SQL"))  define("DATA_NO_SQL", "ERR001");
