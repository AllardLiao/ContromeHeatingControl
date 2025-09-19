<?php
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../ContromeGateway/module.php';

// Testbare Subklasse, die protected -> public macht
class TestableContromeGateway extends ContromeGateway
{
    public function __construct()
    {
        // Dummy-Parent-Call (IPS braucht normalerweise eine InstanceID)
        // Du kannst hier 0 oder eine Fake-ID nehmen
        parent::__construct(0);
    }

    public function callWrapReturn(bool $success, string $msg, $payload = null): string
    {
        return $this->wrapReturn($success, $msg, $payload);
    }

    public function callIsSuccess(string $result, int $errType = 0, string $msg = ""): bool
    {
        return $this->isSuccess($result, $errType, $msg);
    }

    public function callIsError(string $result): bool
    {
        return $this->isError($result);
    }
}

class WrapperTest extends TestCase
{
    private $wrapper;

    protected function setUp(): void
    {
        $this->wrapper = new TestableContromeGateway();
    }

    public function testWrapReturnSuccess()
    {
        $json = $this->wrapper->callWrapReturn(true, "OK", ["foo" => "bar"]);
        $this->assertTrue($this->wrapper->callIsSuccess($json));
        $this->assertFalse($this->wrapper->callIsError($json));

        $data = json_decode($json, true);
        $this->assertEquals("OK", $data['message']);
        $this->assertEquals(["foo" => "bar"], $data['payload']);
    }

    public function testWrapReturnError()
    {
        $json = $this->wrapper->callWrapReturn(false, "Fehler");
        $this->assertFalse($this->wrapper->callIsSuccess($json));
        $this->assertTrue($this->wrapper->callIsError($json));
    }

    public function testInvalidJson()
    {
        $this->assertFalse($this->wrapper->isSuccess("{not valid}"));
        $this->assertFalse($this->wrapper->isError("{not valid}"));
    }
}
