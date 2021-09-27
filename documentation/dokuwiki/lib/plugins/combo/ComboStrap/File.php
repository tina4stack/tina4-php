<?php
/**
 * Copyright (c) 2021. ComboStrap, Inc. and its affiliates. All Rights Reserved.
 *
 * This source code is licensed under the GPL license found in the
 * COPYING  file in the root directory of this source tree.
 *
 * @license  GPL 3 (https://www.gnu.org/licenses/gpl-3.0.en.html)
 * @author   ComboStrap <support@combostrap.com>
 *
 */

namespace ComboStrap;


class File
{

    private $path;


    /**
     * File constructor.
     * @param $absolutePath
     */
    protected function __construct($absolutePath)
    {
        $this->path = $absolutePath;
    }

    /**
     * @return mixed
     */
    public function getFileSystemPath()
    {
        return $this->path;
    }

    /**
     * @return false|int
     */
    public function getSize()
    {
        return filesize($this->path);
    }

    public function exists()
    {
        return file_exists($this->path);
    }

    public function __toString()
    {
        return $this->path;
    }

    /**
     * @return false|int - unix time stamp
     */
    public function getModifiedTime()
    {
        return filemtime($this->path);
    }

    /**
     * @return string the last part of the path without the extension
     */
    public function getBaseNameWithoutExtension()
    {

        return pathinfo($this->path, PATHINFO_FILENAME);
    }

    public function getExtension()
    {
        return pathinfo($this->path, PATHINFO_EXTENSION);
    }


    /**
     * @return array|string|string[] the last part of the path (ie name + extension)
     */
    public function getBaseName()
    {
        return pathinfo($this->path, PATHINFO_BASENAME);
    }

    public function isImage()
    {
        return substr($this->getMime(), 0, 5) == 'image';
    }

    public function getMime()
    {
        return mimetype($this->getBaseName(), false)[1];
    }

    public function getKnownMime()
    {
        return mimetype($this->getBaseName(), true)[1];
    }

    public function getContent()
    {
        return file_get_contents($this->getFileSystemPath());
    }

    public function remove()
    {
        unlink($this->getFileSystemPath());
    }

    public function getParent()
    {
        return new File(pathinfo($this->path, PATHINFO_DIRNAME));
    }

    public function createAsDirectory()
    {

        return mkdir($this->getFileSystemPath(), $mode = 0770, $recursive = true);
    }

    public static function createFromPath($path)
    {
        return new File($path);

    }





}
