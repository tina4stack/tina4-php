# Readme
## Tina4 Php Database Core

The Core Database Module for Tina4

### Install the module using composer

```bash
composer require tina4stack/tina4php-database
```

### Extend the Database Interface for your own database drivers

```php
<?php
/**
* Example database implementation
*/
class DataMyDb implements DataBase
{
    use DataBaseCore;
    
}    
```
