<?php

use ESolution\Inventory\Tests\FoodTestCase;
use ESolution\Inventory\Tests\HealthcareTestCase;
use ESolution\Inventory\Tests\ManufacturingTestCase;
use ESolution\Inventory\Tests\RetailTestCase;
use ESolution\Inventory\Tests\TestCase;
use ESolution\Inventory\Tests\WmsTestCase;

uses(TestCase::class)->in('Feature');
uses(FoodTestCase::class)->in('FoodFeature');
uses(RetailTestCase::class)->in('RetailFeature');
uses(ManufacturingTestCase::class)->in('ManufacturingFeature');
uses(HealthcareTestCase::class)->in('HealthcareFeature');
uses(WmsTestCase::class)->in('WmsFeature');
