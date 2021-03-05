<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4 (Andre van Zuydam)
 * License: MIT https://opensource.org/licenses/MIT
 */
require "vendor/autoload.php";
require "Tina4Php.php";
require "Tina4/Core/Config.php";

define("TINA4_TEMPLATE_LOCATIONS", ["templates", "assets", "templates/snippets"]);
define("TINA4_ROUTE_LOCATIONS", ["api", "routes"]);
define("TINA4_INCLUDE_LOCATIONS", ["app", "objects", "services"]);
define("TINA4_SCSS_LOCATIONS", ["scss"]);

//Add an alias to ORM for legacy code
class_alias("\Tina4\ORM", "Tina4Object");
//define ("TINA4_GIT_MESSAGE", "Some useful text here");
//define ("TINA4_GIT_ENABLED", true);

$config = new \Tina4\Config();

//Use this if you are running a hosted app
//define("TINA4_APP", "/templates/index.html");

echo new \Tina4\Tina4Php($config);
//WARNING NO CODE BELOW THIS LINE WILL BE RUN
