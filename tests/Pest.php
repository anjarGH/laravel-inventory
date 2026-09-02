<?php

use ESolution\Inventory\Tests\ManufacturingTestCase;
use ESolution\Inventory\Tests\RetailTestCase;
use ESolution\Inventory\Tests\TestCase;
use ESolution\Inventory\Tests\WmsTestCase;

uses(TestCase::class)->in('Feature');
uses(RetailTestCase::class)->in('RetailFeature');
uses(ManufacturingTestCase::class)->in('ManufacturingFeature');
uses(WmsTestCase::class)->in('WmsFeature');
