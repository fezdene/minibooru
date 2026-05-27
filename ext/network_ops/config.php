<?php

declare(strict_types=1);

namespace Shimmie2;

/**
 * Stores the paired Mirror Node hostname/IP in Shimmie2's config table
 * (the same SQLite-backed key-value store used by all core settings).
 *
 * Using a ConfigGroup subclass with #[ConfigMeta] attributes means the value
 * also appears automatically in the Shimmie2 Settings panel as a backup UI,
 * and the type system knows it is a STRING.
 */
final class NetworkOpsConfig extends ConfigGroup
{
    public const KEY = 'network_ops';

    #[ConfigMeta(
        label: "Mirror Node IP / Hostname",
        type: ConfigType::STRING,
        default: '',
        help: "The IP address or hostname of the RSYNC Mirror Node. Set via the Network Operations Dashboard."
    )]
    public const MIRROR_IP = 'network_ops_mirror_ip';
}
