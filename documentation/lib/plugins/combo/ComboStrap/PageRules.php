<?php

namespace ComboStrap;

/**
 * The manager that handles the redirection metadata
 *
 */

class PageRules
{

    // Name of the column
    // Used also in the HTML form as name
    const ID_NAME = 'ID';
    const PRIORITY_NAME = 'PRIORITY';
    const MATCHER_NAME = 'MATCHER';
    const TARGET_NAME = 'TARGET';
    const TIMESTAMP_NAME = 'TIMESTAMP';





    /**
     * Delete Redirection
     * @param string $ruleId
     */
    function deleteRule($ruleId)
    {

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query('delete from PAGE_RULES where id = ?', $ruleId);
        if (!$res) {
            LogUtility::msg("Something went wrong when deleting the redirections");
        }
        $sqlite->res_close($res);


    }


    /**
     * Is Redirection of a page Id Present
     * @param integer $id
     * @return boolean
     */
    function ruleExists($id)
    {
        $id = strtolower($id);


        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT count(*) FROM PAGE_RULES where ID = ?", $id);
        $exists = null;
        if ($sqlite->res2single($res) == 1) {
            $exists = true;
        } else {
            $exists = false;
        }
        $sqlite->res_close($res);
        return $exists;


    }

    /**
     * Is Redirection of a page Id Present
     * @param integer $pattern
     * @return boolean
     */
    function patternExists($pattern)
    {


        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT count(*) FROM PAGE_RULES where MATCHER = ?", $pattern);
        $exists = null;
        if ($sqlite->res2single($res) == 1) {
            $exists = true;
        } else {
            $exists = false;
        }
        $sqlite->res_close($res);
        return $exists;


    }


    /**
     * @param $sourcePageId
     * @param $targetPageId
     * @param $priority
     * @return int - the rule id
     */
    function addRule($sourcePageId, $targetPageId, $priority)
    {
        $currentDate = date("c");
        return $this->addRuleWithDate($sourcePageId, $targetPageId, $priority, $currentDate);
    }

    /**
     * Add Redirection
     * This function was needed to migrate the date of the file conf store
     * You would use normally the function addRedirection
     * @param string $matcher
     * @param string $target
     * @param $priority
     * @param $creationDate
     * @return int - the last id
     */
    function addRuleWithDate($matcher, $target, $priority, $creationDate)
    {

        $entry = array(
            'target' => $target,
            'timestamp' => $creationDate,
            'matcher' => $matcher,
            'priority' => $priority
        );

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->storeEntry('PAGE_RULES', $entry);
        if (!$res) {
            LogUtility::msg("There was a problem during insertion");
        }
        $lastInsertId = $sqlite->getAdapter()->getDb()->lastInsertId();
        $sqlite->res_close($res);
        return $lastInsertId;

    }

    function updateRule($id, $matcher, $target, $priority)
    {
        $updateDate = date("c");

        $entry = array(
            'matcher' => $matcher,
            'target' => $target,
            'priority' => $priority,
            'timestamp' => $updateDate,
            'íd' => $id
        );

        $statement = 'update PAGE_RULES set matcher = ?, target = ?, priority = ?, timestamp = ? where id = ?';
        $res = $this->sqlite->query($statement, $entry);
        if (!$res) {
            LogUtility::msg("There was a problem during the update");
        }
        $this->sqlite->res_close($res);

    }


    /**
     * Delete all rules
     * Use with caution
     */
    function deleteAll()
    {

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("delete from PAGE_RULES");
        if (!$res) {
            LogUtility::msg('Errors during delete of all redirections');
        }
        $sqlite->res_close($res);

    }

    /**
     * Return the number of page rules
     * @return integer
     */
    function count()
    {

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("select count(1) from PAGE_RULES");
        if (!$res) {
            LogUtility::msg('Errors during delete of all redirections');
        }
        $value = $sqlite->res2single($res);
        $sqlite->res_close($res);
        return $value;

    }


    /**
     * @return array
     */
    function getRules()
    {

        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("select * from PAGE_RULES order by PRIORITY asc");
        if (!$res) {
            throw new \RuntimeException('Errors during select of all redirections');
        }
        return $sqlite->res2arr($res);


    }

    public function getRule($id)
    {
        $sqlite = Sqlite::getSqlite();
        $res = $sqlite->query("SELECT * FROM PAGE_RULES where ID = ?", $id);

        $array = $sqlite->res2row($res);
        $sqlite->res_close($res);
        return $array;

    }




}
