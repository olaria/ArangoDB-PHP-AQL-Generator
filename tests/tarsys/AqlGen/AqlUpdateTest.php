<?php

namespace tests\tarsys;

use tarsys\AqlGen\AqlUpdate;

class AqlUpdateTest extends \PHPUnit_Framework_TestCase
{
    public function testGet()
    {
        $aql = new AqlUpdate('u', 'users', ['key' => 'value']);
        $this->assertEquals("UPDATE u WITH {\"key\":\"value\"} IN users", $aql->get());
    }

}