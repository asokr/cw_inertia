<?php

namespace Tests\Unit\Credits;

use App\Support\CreditsFormatter;
use Tests\TestCase;

class CreditsFormatterTest extends TestCase
{
    public function test_pluralization(): void
    {
        $this->assertSame('кредит', CreditsFormatter::word(1));
        $this->assertSame('кредита', CreditsFormatter::word(2));
        $this->assertSame('кредита', CreditsFormatter::word(4));
        $this->assertSame('кредитов', CreditsFormatter::word(5));
        $this->assertSame('кредитов', CreditsFormatter::word(11));
        $this->assertSame('кредитов', CreditsFormatter::word(14));
        $this->assertSame('кредит', CreditsFormatter::word(21));
        $this->assertSame('кредитов', CreditsFormatter::word(127));
        $this->assertSame('300 кредитов', CreditsFormatter::amount(300));
        $this->assertSame('Осталось 127 кредитов', CreditsFormatter::remaining(127));
    }
}
