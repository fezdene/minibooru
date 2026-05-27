<?php

declare(strict_types=1);

namespace Shimmie2;

final class Sha256CheckInfo extends ExtensionInfo
{
    public const KEY = 'sha256_check';
    public string $name = 'SHA-256 Integrity Check';
    public string $description = 'Verify post file integrity against the stored SHA-256 hash (admin only).';
    public ExtensionCategory $category = ExtensionCategory::ADMIN;
    public array $authors = ['FYP' => 'exoticbrownspice@gmail.com'];
}
