<?php

declare(strict_types=1);

namespace Hftp\StatamicContentBackups\Tests;

use Hftp\StatamicContentBackups\Etag;

class EtagTest extends TestCase
{
    public function test_a_single_part_etag_matches_the_md5(): void
    {
        $md5 = md5('hello');

        $this->assertTrue(Etag::matchesContent($md5, $md5));
        $this->assertTrue(Etag::matchesContent($md5, strtoupper($md5)));
    }

    public function test_a_multipart_etag_does_not_match_the_md5(): void
    {
        $this->assertFalse(Etag::matchesContent(md5('hello'), md5('hello').'-2'));
        $this->assertFalse(Etag::matchesContent(md5('hello'), null));
        $this->assertFalse(Etag::matchesContent(md5('hello'), ''));
    }
}
