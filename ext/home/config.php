<?php

declare(strict_types=1);

namespace Shimmie2;

final class HomeConfig extends ConfigGroup
{
    public const KEY = "home";
    public ?string $title = "Home Page";

    #[ConfigMeta("Page links", ConfigType::STRING, input: ConfigInput::TEXTAREA, help: "Use BBCode, leave blank for defaults")]
    public const LINKS = 'home_links';

    #[ConfigMeta("Page text", ConfigType::STRING, input: ConfigInput::TEXTAREA)]
    public const TEXT = 'home_text';

    #[ConfigMeta("Counter", ConfigType::STRING, default: "default", options: "Shimmie2\HomeConfig::get_counter_options")]
    public const COUNTER = 'home_counter';

    // ── Standalone hero customisation ────────────────────────────────────────

    #[ConfigMeta("Hero tagline", ConfigType::STRING, default: "", help: "Big bold headline. Leave blank to use the site title.")]
    public const TAGLINE = 'home_tagline';

    #[ConfigMeta("Hero subtitle", ConfigType::STRING, default: "A curated digital media archive.")]
    public const SUBTITLE = 'home_subtitle';

    #[ConfigMeta("Background: dark base", ConfigType::STRING, default: "#050a1a", help: "CSS colour, e.g. #050a1a")]
    public const GRAD_DARK = 'home_grad_dark';

    #[ConfigMeta("Background: mid tone", ConfigType::STRING, default: "#0d1b3e", help: "CSS colour, e.g. #0d1b3e")]
    public const GRAD_MID = 'home_grad_mid';

    #[ConfigMeta("Background: glow accent", ConfigType::STRING, default: "#1d6ae5", help: "CSS colour for the radial glow, e.g. #1d6ae5")]
    public const GRAD_GLOW = 'home_grad_glow';

    #[ConfigMeta("Search button label", ConfigType::STRING, default: "Search")]
    public const CTA_TEXT = 'home_cta_text';

    #[ConfigMeta("Show recent-posts grid", ConfigType::BOOL, default: true)]
    public const SHOW_RECENT = 'home_show_recent';

    /**
     * @return array<string, string>
     */
    public static function get_counter_options(): array
    {
        $counters = [];
        $counters["None"] = "none";
        $counters["Text-only"] = "text-only";
        foreach (\Safe\glob("ext/home/counters/*") as $counter_dirname) {
            $name = str_replace("ext/home/counters/", "", $counter_dirname);
            $counters[ucfirst($name)] = $name;
        }
        return $counters;
    }
}
