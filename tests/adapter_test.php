<?php

declare(strict_types=1);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . PHP_EOL);
        exit(1);
    }
}

$root = dirname(__DIR__);
$plugin = file_get_contents($root . "/plugin.yml");
$main = file_get_contents($root . "/src/Oomph.php");
$readme = file_get_contents($root . "/README.md");
$config = file_get_contents($root . "/resources/config.yml");

assertTrue(!str_contains($plugin, "depend: Spectrum"), "plugin.yml must not depend on Spectrum");
assertTrue(!str_contains($main, "cooldogedev\\Spectrum"), "Oomph.php must not import Spectrum");
assertTrue(!str_contains($main, "registerPacketDecode"), "Oomph.php must not configure Spectrum packet decoding");
assertTrue(str_contains($main, "DataPacketReceiveEvent"), "adapter must listen for ordinary PMMP packets");
assertTrue(str_contains($main, "ScriptMessagePacket"), "adapter must retain the Oomph event channel");
assertTrue(!str_contains(strtolower($readme), "spectrum"), "README must document the native integration without Spectrum");
assertTrue(!str_contains($config, "Allow-NonOomph-Conn"), "config must not expose obsolete transport admission settings");
assertTrue(str_contains($config, "Trusted-Proxy-Addresses"), "config must declare trusted native proxy addresses");
preg_match('/^version: ([^\r\n]+)$/m', $plugin, $pluginVersion);
preg_match('/^Version: "([^"]+)"$/m', $config, $configVersion);
assertTrue(($pluginVersion[1] ?? null) === ($configVersion[1] ?? null), "plugin and config versions must match");
assertTrue(str_contains($main, '!== "' . ($configVersion[1] ?? '') . '"'), "config migration must target the packaged version");
assertTrue(!str_contains($main, "unlink("), "config migration must preserve administrator settings");
assertTrue(str_contains($main, 'remove("Allow-NonOomph-Conn")'), "config migration must remove the obsolete admission key");
assertTrue(str_contains($main, 'get("Allowed-Connections"'), "config migration must preserve old trusted addresses");
assertTrue(str_contains($main, 'remove("Allowed-Connections")'), "config migration must rename the obsolete address key");
assertTrue(str_contains($main, 'in_array($address, $trusted, true)'), "event handling must enforce the trusted proxy boundary");
assertTrue(!str_contains($main, "PlayerAuthInputPacket"), "adapter packet listener must only handle Oomph events");

foreach ([
    "/src/session/OomphNetworkSession.php",
    "/src/session/OomphRakLibInterface.php",
    "/src/utils/ReflectionUtils.php",
] as $obsolete) {
    assertTrue(!file_exists($root . $obsolete), "$obsolete must be removed");
}

fwrite(STDOUT, "adapter contract OK" . PHP_EOL);
