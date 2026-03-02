<?php

namespace ethaniccc\Oomph;

use cooldogedev\Spectrum\Spectrum;
use ethaniccc\Oomph\event\OomphPunishmentEvent;
use ethaniccc\Oomph\event\OomphViolationEvent;
use ethaniccc\Oomph\session\LoggedData;
use ethaniccc\Oomph\session\OomphSession;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

class Oomph extends PluginBase implements Listener {

    private const VALID_EVENTS = [
        "oomph:flagged" => true,
    ];

	private const DEFAULT_CHECK_SETTINGS = [
		"enabled" => true,
		"max_violations" => 1,
		"punishment" => "none",
	];

	private const OOMPH_PACKET_DECODE = [
		ProtocolInfo::ADD_ACTOR_PACKET,
		ProtocolInfo::ADD_PLAYER_PACKET,
		ProtocolInfo::CHUNK_RADIUS_UPDATED_PACKET,
		ProtocolInfo::INVENTORY_SLOT_PACKET,
		ProtocolInfo::INVENTORY_CONTENT_PACKET,
		ProtocolInfo::ITEM_STACK_RESPONSE_PACKET,
		ProtocolInfo::LEVEL_CHUNK_PACKET ,
		ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET ,
		ProtocolInfo::MOB_EFFECT_PACKET ,
		ProtocolInfo::MOVE_ACTOR_ABSOLUTE_PACKET ,
		ProtocolInfo::MOVE_PLAYER_PACKET ,
		ProtocolInfo::REMOVE_ACTOR_PACKET ,
		ProtocolInfo::SET_ACTOR_DATA_PACKET ,
		ProtocolInfo::SET_ACTOR_MOTION_PACKET ,
		ProtocolInfo::SET_PLAYER_GAME_TYPE_PACKET,
		ProtocolInfo::SUB_CHUNK_PACKET ,
		ProtocolInfo::UPDATE_ABILITIES_PACKET ,
		ProtocolInfo::UPDATE_ATTRIBUTES_PACKET ,
		ProtocolInfo::UPDATE_BLOCK_PACKET ,
		ProtocolInfo::UPDATE_PLAYER_GAME_TYPE_PACKET,

		ProtocolInfo::START_GAME_PACKET,
	];

	private const MULTIVERSION_PACKET_DECODE = [
		ProtocolInfo::CAMERA_AIM_ASSIST_PACKET,
		ProtocolInfo::CAMERA_PRESETS_PACKET,
		ProtocolInfo::CONTAINER_REGISTRY_CLEANUP_PACKET,
		ProtocolInfo::EMOTE_PACKET,
		ProtocolInfo::INVENTORY_CONTENT_PACKET,
		ProtocolInfo::INVENTORY_SLOT_PACKET,
		ProtocolInfo::ITEM_STACK_RESPONSE_PACKET,
		ProtocolInfo::MOB_EFFECT_PACKET,
		ProtocolInfo::PLAYER_AUTH_INPUT_PACKET,
		ProtocolInfo::PLAYER_LIST_PACKET,
		ProtocolInfo::RESOURCE_PACK_STACK_PACKET,
		ProtocolInfo::RESOURCE_PACKS_INFO_PACKET,
		ProtocolInfo::TRANSFER_PACKET,
		ProtocolInfo::UPDATE_ATTRIBUTES_PACKET,
		ProtocolInfo::ADD_PLAYER_PACKET,
		ProtocolInfo::ADD_ACTOR_PACKET,
		ProtocolInfo::SET_ACTOR_LINK_PACKET,
		ProtocolInfo::CAMERA_INSTRUCTION_PACKET,
		ProtocolInfo::CHANGE_DIMENSION_PACKET,
		ProtocolInfo::CORRECT_PLAYER_MOVE_PREDICTION_PACKET,
		ProtocolInfo::DISCONNECT_PACKET,
		ProtocolInfo::EDITOR_NETWORK_PACKET,
		ProtocolInfo::LEVEL_CHUNK_PACKET,
		ProtocolInfo::MOB_ARMOR_EQUIPMENT_PACKET,
		ProtocolInfo::PLAYER_ARMOR_DAMAGE_PACKET,
		ProtocolInfo::SET_TITLE_PACKET,
		ProtocolInfo::STOP_SOUND_PACKET,
		ProtocolInfo::INVENTORY_TRANSACTION_PACKET,
		ProtocolInfo::ITEM_STACK_REQUEST_PACKET,
		ProtocolInfo::CRAFTING_DATA_PACKET,
		ProtocolInfo::CONTAINER_CLOSE_PACKET,
		ProtocolInfo::TEXT_PACKET,
		ProtocolInfo::START_GAME_PACKET,
		ProtocolInfo::CODE_BUILDER_SOURCE_PACKET,
		ProtocolInfo::ITEM_REGISTRY_PACKET,
		ProtocolInfo::STRUCTURE_BLOCK_UPDATE_PACKET,
		ProtocolInfo::AVAILABLE_COMMANDS_PACKET,
		ProtocolInfo::BOSS_EVENT_PACKET,
		ProtocolInfo::CAMERA_AIM_ASSIST_PRESETS_PACKET,
		ProtocolInfo::COMMAND_BLOCK_UPDATE_PACKET,
		ProtocolInfo::CREATIVE_CONTENT_PACKET,
		ProtocolInfo::UPDATE_ABILITIES_PACKET,
		ProtocolInfo::REQUEST_ABILITY_PACKET,
	];

	private static Oomph $instance;

	public string $alertPermission;
	public string $logPermission;

	/** @var OomphSession[] */
	private array $alerted = [];

	public function onEnable(): void {
		$spectrum = $this->getServer()->getPluginManager()->getPlugin("Spectrum");
		if (!$spectrum instanceof Spectrum) {
			throw new \RuntimeException("Oomph-PM requires Spectrum to run.");
		}

		for ($pkId = 0; $pkId <= 1000; $pkId++) {
			$spectrum->registerPacketDecode($pkId, true);
		}

		self::$instance = $this;

		if ($this->getConfig()->get("Version", "n/a") !== "1.0.1") {
			@unlink($this->getDataFolder() . "config.yml");
			$this->reloadConfig();
		}
		$this->getLogger()->info("hello!");

		$this->alertPermission = $this->getConfig()->get("Alert-Permission", "Oomph.Alerts");
		$this->logPermission = $this->getConfig()->get("Logs-Permission", "Oomph.Logs");

		if (!$this->getConfig()->get("Enabled", true)) {
			$this->getLogger()->warning("Oomph set to disabled in config");
			return;
		}

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
			$this->alerted = [];
			foreach ($this->getServer()->getOnlinePlayers() as $player) {
				$session = OomphSession::get($player);
				if ($session === null) {
					continue;
				}
				if (!$player->hasPermission($this->alertPermission)) {
					$session->authorized = false;
					$session->alertsEnabled = false;
					continue;
				}
				if (!$session->authorized && $player->hasPermission($this->alertPermission)) {
					$session->authorized = true;
					$session->alertsEnabled = true;
				}
				if (!$session->alertsEnabled || microtime(true) - $session->lastAlert < $session->alertDelay) {
					continue;
				}
				$this->alerted[] = $session;
			}
		}), 1);

		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        switch ($command->getName()) {
            case "oalerts":
            case "odelay":
                if (!$sender instanceof Player) {
                    return false;
                }

                if (!$sender->hasPermission($this->alertPermission)) {
                    $sender->sendMessage(TextFormat::RED . "Insufficient permissions");
                    return true;
                }

                $session = OomphSession::get($sender);
                if ($session === null) {
                    $sender->sendMessage(TextFormat::RED . "Unexpected null session.");
                    return true;
                }

                if ($command->getName() === "oalerts") {
                    $session->alertsEnabled = !$session->alertsEnabled;
                    if ($session->alertsEnabled) {
                        $sender->sendMessage(TextFormat::GREEN . "Alerts enabled.");
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Alerts disabled.");
                    }
                } else {
                    $delay = max((float) ($args[0] ?? 3), 0.0001);
                    $session->alertDelay = $delay;
                    $sender->sendMessage(TextFormat::GREEN . "Alert delay set to $delay seconds");
                }

                return true;
            case "ologs":
                if (!$sender->hasPermission($this->logPermission)) {
                    $sender->sendMessage(TextFormat::RED . "Insufficient permissions.");
                    return true;
                }

                $arg = $args[0] ?? null;
                if ($arg === null) {
                    $sender->sendMessage(TextFormat::RED . "Please specify a player to obtain their logs.");
                    return true;
                }

                $target = $this->getServer()->getPlayerByPrefix($arg);
                if ($target === null) {
                    $sender->sendMessage(TextFormat::RED . "Player not found.");
                    return true;
                }

                $data = LoggedData::getInstance()->get($target->getName());
                if (count($data) === 0) {
                    $sender->sendMessage(str_replace(
                        ["{prefix}", "{player}"],
                        [$this->getConfig()->get("Prefix", "§7§l[§eoomph§7]§r"), $target->getName()],
                        $this->getConfig()->get("NoLogMessage", "{prefix} §a{player} has no existing logs.")
                    ));
                    return true;
                }

                $message = str_replace(
                        ["{prefix}", "{player}"],
                        [$this->getConfig()->get("Prefix", "§7§l[§eoomph§7]§r"), $target->getName()],
                        $this->getConfig()->get("StartLogMessage", "{prefix} §5Log summary for §d{player}:")
                    ) . PHP_EOL;
                foreach ($data as $k => $datum) {
                    $message .= str_replace(
                        ["{check_main}", "{check_sub}", "{violations}"],
                        [$datum["check_main"], $datum["check_sub"], var_export((float) $datum["violations"], true)],
                        $this->getConfig()->get("LogMessage", "§5{check_main}§7<§d{check_main}§7> §cx{violations}")
                    );

                    if ($k !== count($data) - 1) {
                        $message .= PHP_EOL;
                    }
                }
                $sender->sendMessage($message);

                return true;
        }

        return false;
    }

	public static function getInstance(): Oomph {
		return self::$instance;
	}

	/**
	 * @param PlayerToggleFlightEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled TRUE
	 * We do this because for some reason PM doesn't handle it themselves... lmao!
	 */
	public function onToggleFlight(PlayerToggleFlightEvent $event): void {
		if ($event->isFlying() && !$event->getPlayer()->getAllowFlight() && $event->getPlayer()->getGamemode() !== GameMode::SPECTATOR()) {
			$event->cancel();
		}
	}

    /**
     * @param PlayerJoinEvent $event
     * @return void
     */
	public function onJoin(PlayerJoinEvent $event): void {
		OomphSession::register($event->getPlayer());
	}

    /**
     * @param PlayerQuitEvent $event
     * @return void
     */
	public function onQuit(PlayerQuitEvent $event): void {
		OomphSession::unregister($event->getPlayer());
	}

    /**
     * @param DataPacketDecodeEvent $event
     * @priority LOWEST
     * @handleCancelled
     */
    public function onPacketDecode(DataPacketDecodeEvent $event): void {
        if ($event->getPacketId() === ScriptMessagePacket::NETWORK_ID) {
            $event->uncancel();
        }
    }

    /**
     * @param DataPacketReceiveEvent $event
     * @priority HIGHEST
     */
    public function onClientPacket(DataPacketReceiveEvent $event): void {
        $packet = $event->getPacket();

        if (!$packet instanceof ScriptMessagePacket) {
            // The fact we even have to do this is stupid LMAO.
            // Remember to notify dylanthecat!!! (i never did, i never will)
            if ($packet instanceof PlayerAuthInputPacket && $packet->getInputFlags()->get(PlayerAuthInputFlags::START_FLYING)) {
                $player = $event->getOrigin()->getPlayer();
                if ($player !== null && !$player->getAllowFlight() && $player->getGamemode() !== GameMode::SPECTATOR()) {
                    $player->getNetworkSession()->syncAbilities($player);
                }
            }
            return;
        }

        $eventType = $packet->getMessageId();
        $data = json_decode($packet->getValue(), true);
        if ($data === null) {
            $this->getLogger()->debug("JSON decode failed [$eventType]: " . var_export($packet->getValue(), true));
            return;
        }

        if (!isset(self::VALID_EVENTS[$eventType])) {
            return;
        }

        switch ($eventType) {
            case "oomph:flagged":
                $player = $event->getOrigin()->getPlayer();
                if ($player === null || OomphSession::get($player) === null) {
                    $this->getLogger()->debug("Dropping 'oomph:flagged' — " . ($player === null ? "player is null" : "no OomphSession for {$player->getName()}"));
                    return;
                }

                $event->cancel();

                $data["violations"] = round($data["violations"], 2);

                $ev = new OomphViolationEvent($player, $data["check_main"], $data["check_sub"], $data["violations"], $data["extraData"] ?? "");

                $checkSettings = $this->getConfig()->getNested("{$ev->getCheckName()}.{$ev->getCheckType()}", self::DEFAULT_CHECK_SETTINGS);
                if (!($checkSettings["enabled"] ?? true)) {
                    $this->getLogger()->debug("Check '{$ev->getCheckName()}.{$ev->getCheckType()}' disabled, cancelling");
                    $ev->cancel();
                }

                $ev->call();
                if ($ev->isCancelled()) {
                    return;
                }

                $message = str_replace(
                    ["{prefix}", "{player}", "{check_main}", "{check_sub}", "{violations}", "{extra_data}"],
                    [
                        $this->getConfig()->get("Prefix", "§l§7[§eoomph§7]"),
                        $data["player"],
                        $data["check_main"],
                        $data["check_sub"],
                        $data["violations"],
                        $data["extraData"] ?? "",
                    ],
                    $this->getConfig()->get("FlaggedMessage", "{prefix} §d{player} §7flagged §4{check_main} §7(§c{check_sub}§7) §7[§5x{violations}§7] §f{extra_data}")
                );

                LoggedData::getInstance()->add($player->getName(), $data);

                $now = microtime(true);
                foreach ($this->alerted as $session) {
                    $session->getPlayer()->sendMessage($message);
                    $session->lastAlert = $now;
                }

                $this->checkForPunishments($player, $ev->getCheckName(), $ev->getCheckType(), $ev->getViolations());
                break;

            default:
                $this->getLogger()->debug("Rejected unknown event: " . var_export($eventType, true));
                break;
        }
    }

    private function checkForPunishments(Player $player, string $check, string $type, float $violations): void {
        $settings = $this->getConfig()->getNested("$check.$type", self::DEFAULT_CHECK_SETTINGS);

        $punishment = $settings["punishment"] ?? "none";
        if ($punishment === "none" || $violations < ($settings["max_violations"] ?? 10)) {
            return;
        }

        $ev = new OomphPunishmentEvent($player, OomphPunishmentEvent::punishmentTypeFromString($punishment), $check, $type);
        $ev->call();

        if ($ev->isCancelled()) {
            return;
        }

        $replacePairs = [
            ["{prefix}", "{check_main}", "{check_sub}"],
            [$this->getPrefix(), $check, $type],
        ];

        $msg = str_replace(
            $replacePairs[0],
            $replacePairs[1],
            $this->getConfig()->get($ev->isKick() ? "KickMessage" : "BanMessage",
                $ev->isKick()
                    ? "{prefix} §cKicked for the usage of third-party software."
                    : "{prefix} §cBanned for the usage of third-party software."
            )
        );

        $player->kick($msg);

        if ($ev->isBan()) {
            $this->getServer()->getNameBans()->addBan($player->getName(), $msg, null, "Oomph");
        }
    }

	public function getPrefix(): string {
		return $this->getConfig()->get("Prefix", "§7§l[§eoomph§7]§r");
	}

}
