<?php
/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

/**
 * CURD generator for ORM objects
 */
class ORMCRUDGenerator
{
    use ORMUtility;

    private $ORM;

    function __construct($ORM)
    {
        $this->ORM = $ORM;
    }

    /**
     * Generates CRUD
     * @param string $path
     */
    final public function generateCRUD(string $path = "", bool $return=false): ?string
    {
        $className = get_class($this->ORM);
        if (empty($path)) {
            $callingCode = '(new ' . $className . '())->generateCRUD();';
        } else {
            if ($return) {
                $callingCode = '(new ' . $className . '())->generateCRUD("' . $path . '", true);';
            } else {
                $callingCode = '(new ' . $className . '())->generateCRUD("' . $path . '");';
            }

        }

        if (empty($path)) {
            $backtrace = debug_backtrace();
            $path = $backtrace[1]["args"][0];

            $path = str_replace(array(getcwd(), DIRECTORY_SEPARATOR . "src", ".php", DIRECTORY_SEPARATOR), array("", "", "", "/"), $path);
        }

        $backTrace = debug_backtrace()[1];


        $fileName = ($backTrace["file"]);

        $line = $backTrace["line"];

        if (empty($path)) {
           $path = str_replace(array(".php", $_SERVER["DOCUMENT_ROOT"]), "", realpath($fileName));
        }

        $template = <<<'EOT'
/**
 * CRUD Prototype [OBJECT] Modify as needed
 * Creates  GET @ /path, /path/{id}, - fetch,form for whole or for single
            POST @ /path, /path/{id} - create & update
            DELETE @ /path/{id} - delete for single
 */
\Tina4\Crud::route ("[PATH]", new [OBJECT](), function ($action, [OBJECT] $[OBJECT_NAME], $filter, \Tina4\Request $request) {
    switch ($action) {
       case "form":
       case "fetch":
            //Return back a form to be submitted to the create
             
            if ($action == "form") {
                $title = "Add [OBJECT]";
                $savePath =  TINA4_SUB_FOLDER . "[PATH]";
                $content = \Tina4\renderTemplate("[TEMPLATE_PATH]/form.twig", []);
            } else {
                $title = "Edit [OBJECT]";
                $savePath =  TINA4_SUB_FOLDER . "[PATH]/".$[OBJECT_NAME]->[PRIMARY_KEY];
                $content = \Tina4\renderTemplate("[TEMPLATE_PATH]/form.twig", ["data" => $[OBJECT_NAME]]);
            }

            return \Tina4\renderTemplate("components/modalForm.twig", ["title" => $title, "onclick" => "if ( $('#[OBJECT_NAME]Form').valid() ) { saveForm('[OBJECT_NAME]Form', '" .$savePath."', 'message'); $('#formModal').modal('hide');}", "content" => $content]);
       break;
       case "read":
            //Return a dataset to be consumed by the grid with a filter
            $where = "";
            if (!empty($filter["where"])) {
                $where = "{$filter["where"]}";
            }
        
            return   $[OBJECT_NAME]->select ("*", $filter["length"], $filter["start"])
                ->where("{$where}")
                ->orderBy($filter["orderBy"])
                ->asResult();
        break;
        case "create":
            //Manipulate the $object here
        break;
        case "afterCreate":
           //return needed 
           return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Created');</script>"];
        break;
        case "update":
            //Manipulate the $object here
        break;    
        case "afterUpdate":
           //return needed 
           return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Updated');</script>"];
        break;   
        case "delete":
            //Manipulate the $object here
        break;
        case "afterDelete":
            //return needed 
            return (object)["httpCode" => 200, "message" => "<script>[GRID_ID]Grid.ajax.reload(null, false); showMessage ('[OBJECT] Deleted');</script>"];
        break;
    }
});
EOT;
        $template = str_replace(array("[PATH]", "[OBJECT]", "[OBJECT_NAME]", "[PRIMARY_KEY]", "[GRID_ID]", "[TEMPLATE_PATH]"), array($path, $className, $this->camelCase($className), $this->ORM->primaryKey, $this->camelCase($className), str_replace(DIRECTORY_SEPARATOR, "/", $path)), $template);

        $content = file_get_contents($fileName);

        //create a crud grid and form
        $formData = $this->ORM->getObjectData();

        $tableColumns = [];
        $tableColumnMappings = [];
        $tableFields = [];
        foreach ($formData as $columnName => $value) {
            $tableColumns[] = $this->ORM->getTableColumnName($columnName);
            $tableColumnMappings[] = $columnName;
            $tableFields[] = ["fieldName" => $columnName, "fieldLabel" => $this->ORM->getTableColumnName($columnName)];
        }

        if (!defined("TINA4_DOCUMENT_ROOT")) {
            define("TINA4_DOCUMENT_ROOT", "./");
        }

        if (!defined("TINA4_BASE_URL")) {
            define("TINA4_BASE_URL", "/");
        }

        $componentPath = TINA4_DOCUMENT_ROOT . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "templates" . str_replace("/", DIRECTORY_SEPARATOR, $path);

        if (!file_exists($componentPath) && !mkdir($componentPath, 0755, true) && !is_dir($componentPath)) {
            throw new \RuntimeException(sprintf('Directory "%s" was not created', $componentPath));
        }

        $gridFilePath = $componentPath . DIRECTORY_SEPARATOR . "grid.twig";

        $formFilePath = $componentPath . DIRECTORY_SEPARATOR . "form.twig";

        //create the grid
        $gridHtml = $this->renderTemplate("@__main__/components/grid.twig", ["gridTitle" => $className, "gridId" => $this->camelCase($className), "primaryKey" => $this->ORM->primaryKey, "tableColumns" => $tableColumns, "tableColumnMappings" => $tableColumnMappings, "apiPath" => $path, "baseUrl" => TINA4_BASE_URL]);

        file_put_contents($gridFilePath, $gridHtml);

        //create the form
        $formHtml = $this->renderTemplate("@__main__/components/form.twig", ["formId" => $this->camelCase($className), "primaryKey" => $this->ORM->primaryKey, "tableFields" => $tableFields, "baseUrl" => TINA4_BASE_URL]);

        $formHtml = str_replace("&quot;", '"', $formHtml);
        file_put_contents($formFilePath, $formHtml);

        $gridRouterCode = '
\Tina4\Get::add("' . $path . '/landing", function (\Tina4\Response $response){
    return $response (\Tina4\renderTemplate("' . $path . '/grid.twig"), HTTP_OK, TEXT_HTML);
});
        ';

        $content = str_replace($callingCode, $gridRouterCode . PHP_EOL . $template, $content);

        if (!$return) {
            file_put_contents($fileName, $content);
            return null;
        }

        return $content;
    }

    /**
     * Render a twig file or string
     * @param string $templateName
     * @param array $data
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     */
    public static function renderTemplate(string $templateName, array $data): string
    {
        $loader = new \Twig\Loader\FilesystemLoader(__DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'public');
        $twig = new \Twig\Environment($loader);
        return $twig->render($templateName, $data);
    }
}