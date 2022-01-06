# Tin4php ORM #

```
composer require tina4stack/tina4php-orm
```

## Run test database

```
docker run -d --platform linux/x86_64 -p 33050:3050 -e ISC_PASSWORD=pass1234 -e FIREBIRD_DATABASE=TINA4.FDB -e FIREBIRD_USER=firebird jacobalberty/firebird:3.0
```
