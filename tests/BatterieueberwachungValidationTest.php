<?php

declare(strict_types=1);

include_once __DIR__ . '/stubs/Validator.php';

class BatterieueberwachungValidationTest extends TestCaseSymconValidation
{
    public function testValidateBatterieueberwachung(): void
    {
        $this->validateLibrary(__DIR__ . '/..');
    }

    public function testValidateBatterieueberwachungModule(): void
    {
        $this->validateModule(__DIR__ . '/../Batterieueberwachung');
    }
}