<?php

declare(strict_types=1);

namespace Shimmie2;

final class MassRemoverInfo extends ExtensionInfo
{
    public const KEY = 'mass_remover';
    public string $name = 'Mass Action';
    public string $description = 'Bulk-delete or bulk-tag posts from the post listing (admin only).';
    public ExtensionCategory $category = ExtensionCategory::ADMIN;
    public array $authors = ['Christian Walde' => 'walde.christian@googlemail.com'];
}
