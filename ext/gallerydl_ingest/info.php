<?php

declare(strict_types=1);

namespace Shimmie2;

final class GalleryDlIngestInfo extends ExtensionInfo
{
    public const KEY = "gallerydl_ingest";

    public string $name = "Multiplatform Ingest";
    public string $description = "Batch-ingest media and webpages from any URL via gallery-dl, yt-dlp, and SingleFile";
    public ExtensionCategory $category = ExtensionCategory::ADMIN;
    public array $authors = ["FYP" => "exoticbrownspice@gmail.com"];
}
