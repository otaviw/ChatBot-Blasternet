<?php

namespace Tests\Unit\Support;

use App\Support\LogSanitizer;
use PHPUnit\Framework\TestCase;

class LogSanitizerTest extends TestCase
{
    public function test_mask_phone_keeps_only_last_four_digits(): void
    {
        $masked = LogSanitizer::maskPhone('+55 (11) 98765-4321');

        $this->assertSame('*********4321', $masked);
    }

    public function test_mask_token_hides_middle_chars(): void
    {
        $masked = LogSanitizer::maskToken('EAAB1234567890TOKEN');

        $this->assertSame('EAAB***********OKEN', $masked);
    }

    public function test_mask_authorization_hides_bearer_token(): void
    {
        $masked = LogSanitizer::maskAuthorization('Bearer EAAB1234567890TOKEN');

        $this->assertSame('Bearer EAAB***********OKEN', $masked);
    }

    public function test_truncate_text_limits_length(): void
    {
        $text = str_repeat('a', 100);
        $truncated = LogSanitizer::truncateText($text, 10);

        $this->assertSame('aaaaaaaaaa...', $truncated);
    }
}
