# Services

```php
class TestProcess extends \Tina4\Process implements \Tina4\ProcessInterface
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

//Add this to a router to add to service runner example /start-service
$service = new \Tina4\Service();
$service->addProcess(new TestProcess("My Process"));

//Add this to a router to remove from service runner /stop-service/{processName}
$service->removeProcess("My Process");

```
