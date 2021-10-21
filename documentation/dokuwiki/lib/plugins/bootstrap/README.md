# Bootstrap

## About

This directory contains all `Bootstrap` related resources such as Javascript and Css files used by [ComboStrap](https://combostrap.com)

If you want to bring your own custom Css file, check the [custom Bootstrap article](https://combostrap.com/custom/bootrstap)

In a nutshell, if you already have your CSS file:

  * Copy your CSS file into the sub version directory (`4.4.1`, `4.5.0`, ...)
  * Copy the [bootstrapCustomCss.json](./bootstrapStylesheet.json) to `bootstrapLocalCss.json`
  * And adapt the file by changing or adding your values


## Files

  * [bootstrap.json] is a metadata file with all official bootstrap information
  * [bootstrapCustom.json] is a metadata file with the [ComboStrap](https://combostrap.com) 16 grid theme.
  * There is one subdirectory by `Bootstrap` release such as [4.5.0](./4.5.0)

## Jquery

Jquery must not be slim because the `post` http method is needed for the search box (`qsearch`)
