<?php

declare(strict_types=1);

namespace Shimmie2;

final class StatsInfo extends ExtensionInfo
{
    public const KEY = "stats";
    public string $name = "Archive Stats";
    public string $description = "Interactive analytics dashboard for the archive";
    public ExtensionCategory $category = ExtensionCategory::ADMIN;
    public array $authors = ["FYP" => "exoticbrownspice@gmail.com"];
}
