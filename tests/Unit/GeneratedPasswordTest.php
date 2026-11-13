<?php

namespace Tests\Unit;

use App\Support\GeneratedPassword;
use PHPUnit\Framework\TestCase;

class GeneratedPasswordTest extends TestCase
{
    public function test_passwords_are_twelve_letters_of_both_cases_with_three_digits_and_no_look_alikes()
    {
        $passwords = [];

        for ($i = 0; $i < 500; $i++) {
            $password = GeneratedPassword::make();
            $passwords[$password] = true;

            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9]{12}$/', $password);
            $this->assertMatchesRegularExpression('/[A-Z]/', $password);
            $this->assertMatchesRegularExpression('/[a-z]/', $password);
            $this->assertSame(3, preg_match_all('/\d/', $password));
        }

        $this->assertCount(500, $passwords);
    }
}
