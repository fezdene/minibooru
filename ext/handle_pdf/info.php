<?php

declare(strict_types=1);

namespace Shimmie2;

final class PDFFileHandlerInfo extends ExtensionInfo
{
    public const KEY = "handle_pdf";

    public string $name = "PDF Files";
    public string $description = "Handle PDF document uploads and display them with an embedded viewer";
    public ExtensionCategory $category = ExtensionCategory::FORMAT_SUPPORT;
    public array $authors = ["FYP" => "exoticbrownspice@gmail.com"];
}
