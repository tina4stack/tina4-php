# Strap Web Template

> Volatile WebSite Generator based on Easy Markup File, Bootstrap Styled, and the power of a Wiki Platform

![ComboStrap - Easy Markup WebSite Generator](https://raw.githubusercontent.com/ComboStrap/combo/main/resources/images/banner-combostrap.png "combostrap website bootstrap dokuwiki")

[![Build Status](https://travis-ci.com/ComboStrap/dokuwiki-template-strap.svg?branch=main)](https://travis-ci.com/ComboStrap/dokuwiki-template-strap)

## About

`Strap` is the template of [ComboStrap](https://combostrap.com/)


## Features


  * [bootstrap 5 integrated](https://combostrap.com/bootstrap)
  * [Menubar (Top Fixed or not)](http://combostrap.com/menubar)
  * Dynamic [Layout](http://combostrap.com/layout):
    * `holy grail`
    * `median`
    * `landing`
  * Dynamic template with [slots](http://combostrap.com/slot):
    * `Footer` slot
    * `header` slot
    * `Side` slot
    * `sidekick` slot
  * [Rail bar](http://combostrap.com/railbar) for the actions
  * [Fast as hell](http://combostrap.com/performance):
    * One Stylesheet dependency available globally from CDN
    * All Javascript are served asynchronously
    * Lazy image loading


## Version

  * Support php version 5.6, 7.0, 7.1, 7.2, 7.3
  * Bootstrap 4, 5.


## Dev

For developers

The function `tpl_strap_meta_header` found in the file [tpl_lib_strap](class/TplUtility.php)

  * control the headers and is call via the registration of the event `TPL_METAHEADER_OUTPUT`
  * control the Jquery version. Not logged in, Bootstrap, logged in Dokuwiki (with ui,..)

Ter info, the template file are:
  * [main.php](./main.php): The main page. [Doc](https://www.dokuwiki.org/devel:templates:main.php)
  * [detail.php](./detail.php): The template to show the detail of an image. [Doc](https://www.dokuwiki.org/devel:templates:detail.php)
  * [mediamanager.php](./mediamanager.php): The template to show the media manager.

## Release

### Current

  * Forked from the [Bootie template](https://github.com/gerardnico/dokuwiki-template-bootie)
  * A [test](./_test/php.test.php) was introduced to check the php version.
  * Difference with the Bootie template
     * a new sidebar `sidekickbar` was introduced.
     * the grid is 3 columns with at:
        * the left: the sidebar,
        * the center: the page
        * the right: the sidekick bar
     * the grid is a custom bootstrap grid of 1280 large and 16 columns which means that the bootstrap CSS is served locally
     * the sidebar is now on the left side
     * the footer is now a bootstrap container


## Note

  * The site tool and the page tool are in the same menu called Tools.

  * There is two entry points:

     * the file [main.php](main.php) - that shows the page
     * the file [detail.php](detail.php) - that shows the image

## Todo ?

  * [JqueryUI Bootstrap](https://cdn.rawgit.com/arschmitz/jqueryui-bootstrap-adapter/v0.3.0/index.html)

## Dev Note

  * To beat the CSS cache, just open the file `local.php` and modify it
