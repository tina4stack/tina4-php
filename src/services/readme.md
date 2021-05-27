#Services

```php
class TestService extends \Tina4\Process implements \Tina4\ProcessInterface
{
    public string $name = "My Service";

    public function canRun(): bool
    {
        // TODO: Implement canRun() method.
        return true;
    }

    public function run()
    {
        echo "I'm walking here!";
        // TODO: Implement run() method.
    }
}


\Tina4\Service
```
