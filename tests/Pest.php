<?php

use ESolution\Inventory\Tests\RetailTestCase;
use ESolution\Inventory\Tests\TestCase;
use ESolution\Inventory\Tests\WmsTestCase;

uses(TestCase::class)->in('Feature');
uses(RetailTestCase::class)->in('RetailFeature');
uses(WmsTestCase::class)->in('WmsFeature');
