<?php

declare(strict_types=1);

namespace Shimmie2;

final class NetworkOpsInfo extends ExtensionInfo
{
    public const KEY = "network_ops";

    public string $name = "Network Operations Dashboard";
    public string $description = "Mirror node pairing, live status monitor, force re-sync, and RSYNC audit log";
    public ExtensionCategory $category = ExtensionCategory::ADMIN;
    public array $authors = ["FYP" => "exoticbrownspice@gmail.com"];
}
