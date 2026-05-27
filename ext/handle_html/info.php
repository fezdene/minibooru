<?php

declare(strict_types=1);

namespace Shimmie2;

final class HandleHtmlInfo extends ExtensionInfo
{
    public const KEY = "handle_html";

    public string $name = "Handle HTML";
    public string $description = "Store and display self-contained HTML webpages archived by SingleFile";
    public ExtensionCategory $category = ExtensionCategory::FILE_HANDLING;
    public array $authors = ["FYP" => "exoticbrownspice@gmail.com"];
}
