<?php

/**
 * Tina4 - This is not a 4ramework.
 * Copy-right 2007 - current Tina4
 * License: MIT https://opensource.org/licenses/MIT
 */

namespace Tina4;

class Report extends \Tina4\Data
{

    public $delimiter = "\t";
    public $data;
    public $html;
    public $tableHeader;
    public $currentGroupBy;
    public $calculatedValues = [];
    public $calculatedValuesGlobal = [];
    public $header;
    public $footer;
    public $csv;

    /**
     * Returns back a CSV after generating the report
     * @return mixed
     */
    public function asCSV($fileName="") {
        if (!empty($fileName)) {
            file_put_contents($fileName, $this->csv);
        }

        return $this->csv;
    }

    /**
     * @param string $html
     * @return string
     */
    public function asHTML($html="") {
        if (!empty($html)) {
            $this->html = $html;

            return _style($this->getStyleSheet()).$this->html;
        } else {
            return _style($this->getStyleSheet()).$this->html;
        }

    }

    /**
     * @param string $fileName
     * @param string $orientation
     * @param string $html
     * @return string
     * @throws \Mpdf\MpdfException
     */
    public function asPDF($fileName="", $orientation="P" , $html="") {
        $mpdf = new \Mpdf\Mpdf(['orientation' => $orientation, 'setAutoBottomMargin' => 'stretch', 'margin_top' => 20, 'margin_left' => 5, 'margin_right' => 5, 'margin_bottom' => 5, 'margin_header' => 2, "margin_footer" => 5]);

        $mpdf->SetHTMLHeader($this->header, "", true);

        $mpdf->SetHTMLFooter($this->footer);

        $mpdf->WriteHTML(str_replace(".report ", "", $this->getStyleSheet()),\Mpdf\HTMLParserMode::HEADER_CSS);

        $mpdf->WriteHTML($this->asHTML($html));

        return $mpdf->Output($fileName);
    }

    /**
     * Get the style sheet for the display
     * @return string
     */
    public function getStyleSheet() {
        return "
            .report {
                font-family: Courier; 
                font-size: 10px;
            }
            
            .report body {
                font-family: Courier; 
                font-size: 10px;
            }
           
            .report table {
                border: 1px solid black;
                width: 100%;
                border-spacing: 0px;
            }
            
            .report table th {
                border-bottom: 1px solid black;
                padding: 2px;
            }
            
            .report table td {
                padding: 2px;
            }
            
            .report table .footer-th {
                border-top: 1px solid black;
            }
            
            .page-break {
                page-break-after:always;
            } 
        ";
    }

    /**
     * @param $header
     * @return $this
     */
    public function setHeader($header)
    {
        $this->header = $header;

        return $this;
    }

    /**
     * @param $footer
     * @return $this
     */
    public function setFooter($footer)
    {
        $footer = "Page {PAGENO} of {nb}". $footer;

        $this->footer = $footer;

        return $this;
    }

    /**
     * Gets the header
     * @param $record
     * @param $groupBy
     * @param $columnCount
     * @param $fields
     * @param $calculation
     * @param $excludedFields
     * @return string
     */
    public function getHeader($record, $groupBy, $columnCount, $fields, $calculation, $excludedFields)
    {
        $newGroup = "";
        if (!empty($groupBy)) {
            foreach ($groupBy as $column => $function) {
                $newGroup .= $record[$column];
            }
        }

        $html = "";

        if($this->currentGroupBy !== $newGroup) {
            if (!empty($this->currentGroupBy)) {
                $html .= $this->getFooter($fields, $calculation, $excludedFields);
            }

            $this->currentGroupBy = $newGroup;
            //add footer - calculated fields

            //add header
            if (!empty($groupBy)) {
                foreach ($groupBy as $column => $function) {
                    if (!empty($function)) {
                        $data = $function ($record);

                        if (!empty($data)) {
                            $html .= _tr(_th(["style" => "text-align: left", "colspan" => $columnCount - count($excludedFields)], $data));
                        }
                    }
                }
            }

            $html .= $this->tableHeader;
        }

        return $html;
    }

    /**
     * Gets the footer
     * @param $fields
     * @param $calculation
     * @param $excludedFields
     * @param false $global
     * @return string
     */
    public function getFooter($fields, $calculation, $excludedFields, $global = false)
    {
        $html = "";

        $footerInfo = [];

        $csvFooter = [];

        foreach ($fields as $id => $field)
        {

            if (is_array($excludedFields) && in_array($field->fieldAlias, $excludedFields, true)) {
                continue;
            }

            $align = "left";
            if (strpos($field->dataType, "NUMERIC") !== false )
            {
                $align = "right";
            }

            $value = null;
            if (isset($calculation[$field->fieldAlias]))
            {
                $value = $this->getCalculatedValue($field->fieldAlias, $global);
            }


            $footerInfo[] = _th(["class" => "footer-th", "style" => "text-align: {$align}"], $value);

            $csvFooter[] = $value;
        }

        $html .= _tr($footerInfo);

        $this->csv .= join($this->delimiter, $csvFooter).PHP_EOL;

        return $html;
    }

    /**
     * Gets the calculated value
     * @param $fieldName
     * @param false $global
     * @return string
     */
    public function getCalculatedValue($fieldName, $global=false)
    {
        if (isset($this->calculatedValues[$fieldName])) {
            $value = $this->calculatedValues[$fieldName];
        } else {
            $value = 0.00;
        }

        if ($global && isset($this->calculatedValuesGlobal[$fieldName]))
        {
            if (is_string($this->calculatedValuesGlobal[$fieldName]))
            {
                return $this->calculatedValuesGlobal[$fieldName];
            } else {
                return number_format($this->calculatedValuesGlobal[$fieldName],  2);
            }
        }

        if (is_string($value)) {
            $this->calculatedValuesGlobal[$fieldName] = null;

            return $value;
        } else {
            if (!isset($this->calculatedValuesGlobal[$fieldName] ))
            {
                $this->calculatedValuesGlobal[$fieldName]  = 0.00;
            }

            $this->calculatedValuesGlobal[$fieldName] += $value;

            $this->calculatedValues[$fieldName] = 0.00;

            return number_format($value,  2);
        }
    }

    /**
     * Gets the caption based on the database field
     * @param $caption
     * @return string
     */
    public function getCaption($caption)
    {
        $caption = str_replace("_", " ", $caption);

        $caption = strtolower($caption);

        return ucwords($caption);
    }

    /**
     * Gets the table header
     * @param $fields
     * @param $excludedFields
     * @return string
     */
    public function getTableHeader($fields, $excludedFields)
    {
        $header = [];

        $csvHeader = [];

        foreach ($fields as $id => $field) {
            if (is_array($excludedFields) && in_array($field->fieldAlias, $excludedFields, true)) {
                continue;
            }

            if (strpos($field->dataType, "NUMERIC") !== false )
            {
                $align = "right";
            } else {
                $align = "left";
            }

            $header[] = _th(["style" => "text-align: {$align}"], $this->getCaption($field->fieldAlias));

            $csvHeader[] = '"'.$this->getCaption($field->fieldAlias).'"';
        }

        $this->tableHeader .= _tr($header);

        $this->csv .= join($this->delimiter, $csvHeader).PHP_EOL;

        return $this->tableHeader;
    }

    /**
     * Gets a row for the report to output
     * @param $record
     * @param $groupBy
     * @param $lookup
     * @param $calculation
     * @param $fields
     * @param $excludedFields
     * @return \Tina4\HTMLElement
     */
    public function getRow($record, $groupBy, $lookup, $calculation, $fields, $excludedFields)
    {
        $result = [];

        $csvRow = [];

        $count = -1;

        foreach ($record as $column => $value) {
            $count++;

            if (is_array($excludedFields) && in_array($fields[$count]->fieldAlias, $excludedFields,  true)) continue;

            $align = "left";

            if (strpos($fields[$count]->dataType, "NUMERIC") !== false )
            {
                $align = "right";
            }

            if (isset($lookup[$fields[$count]->fieldAlias])) {
                $value = $lookup[$fields[$count]->fieldAlias]($value);
            }

            if (isset($calculation[$fields[$count]->fieldAlias])) {
                if (!isset($this->calculatedValues[$fields[$count]->fieldAlias]))
                {
                    $this->calculatedValues[$fields[$count]->fieldAlias] = 0.00;
                }

                $this->calculatedValues[$fields[$count]->fieldAlias] = $calculation[$fields[$count]->fieldAlias]($value, $this->calculatedValues[$fields[$count]->fieldAlias]);
            }

            $result[] = _td(["style" => "text-align: {$align}"], $value);

            if (is_string($value)) {
                $csvRow[] = '"'.str_replace("\n", " ", $value).'"';
            } else {
                $csvRow[] = number_format($value,2, ".", "");
            }
        }

        $this->csv .= join($this->delimiter, $csvRow).PHP_EOL;

        return _tr($result);
    }


    /**
     * Generates a report
     * @param $sql
     * @param $groupBy
     * @param $lookup
     * @param $calculation
     * @param $excludedFields
     * @return $this
     */
    public function generate($sql, $groupBy=null, $lookup=null, $calculation=null, $excludedFields=null, $limit=500) {
        $data = $this->DBA->fetch($sql, $limit);

        $fields = $data->fields;

        $records = $data->asArray(true);

        $columnCount = count($fields);

        $this->csv = "";

        $this->getTableHeader($fields, $excludedFields);

        $html = "";

        foreach ($records as $id => $record) {
            $html .= $this->getHeader($record, $groupBy, $columnCount, $fields, $calculation, $excludedFields);

            $html .= $this->getRow($record, $groupBy, $lookup, $calculation, $fields, $excludedFields);
        }

        $html .= $this->getFooter($fields, $calculation, $excludedFields);

        $html .= $this->getFooter($fields, $calculation, $excludedFields, true);

        $this->html =  _div(["class" => "report"], _table(["border" => 0],$html));

        return $this;
    }

}
