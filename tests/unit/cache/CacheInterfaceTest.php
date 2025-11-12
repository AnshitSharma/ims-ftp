<?php
/**
 * CacheInterface Tests
 *
 * Tests that CacheInterface is properly defined with all required methods
 */

require_once __DIR__ . '/../../../includes/cache/CacheInterface.php';

class CacheInterfaceTest extends \PHPUnit\Framework\TestCase {

    /**
     * Test that interface exists
     */
    public function testInterfaceExists() {
        $this->assertTrue(interface_exists('CacheInterface'));
    }

    /**
     * Test that interface has all required methods
     */
    public function testInterfaceHasAllMethods() {
        $methods = [
            'set',
            'get',
            'has',
            'delete',
            'clear',
            'deletePattern',
            'getStats'
        ];

        $reflection = new ReflectionClass('CacheInterface');
        foreach ($methods as $method) {
            $this->assertTrue(
                $reflection->hasMethod($method),
                "Interface should have method: $method"
            );
        }
    }
}
