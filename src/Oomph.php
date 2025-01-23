<?php

namespace ethaniccc\Oomph;

use cooldogedev\Spectrum\Spectrum;
use ethaniccc\Oomph\event\OomphPunishmentEvent;
use ethaniccc\Oomph\event\OomphViolationEvent;
use ethaniccc\Oomph\session\OomphRakLibInterface;
use ethaniccc\Oomph\session\OomphSession;
use ethaniccc\Oomph\session\LoggedData;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerToggleFlightEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\NetworkInterfaceRegisterEvent;
use pocketmine\network\mcpe\protocol\PlayerAuthInputPacket;
use pocketmine\network\mcpe\protocol\ProtocolInfo;
use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\network\mcpe\protocol\types\PlayerAuthInputFlags;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\query\DedicatedQueryNetworkInterface;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;
use ReflectionException;

class Oomph extends PluginBase implements Listener {

	private const VALID_EVENTS = [
		"oomph:latency_report",
		"oomph:flagged",
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
	];

	private static Oomph $instance;

	public string $alertPermission;
	public string $logPermission;

	/** @var OomphSession[] */
	private array $alerted = [];
	private ?RakLibInterface $netInterface = null;

	public function onEnable(): void {
		$spectrum = $this->getServer()->getPluginManager()->getPlugin("Spectrum");
		if (!$spectrum instanceof Spectrum) {
			throw new \RuntimeException("Oomph-PM requires Spectrum to run.");
		}
		foreach (self::OOMPH_PACKET_DECODE as $pkID) {
			$spectrum->decode[$pkID] = true;
		}

		self::$instance = $this;
		/* $this->getServer()->getNetwork()->registerInterface(new OomphRakLibInterface($this->getServer(), $this->getServer()->getIp(), $this->getServer()->getPort(), false)); // do we want upstream connection to use ipv6 (tip: we could load balance by having some upstream connections on ipv4 and some on ipv6)
		$this->getServer()->getPluginManager()->registerEvent(NetworkInterfaceRegisterEvent::class, function(NetworkInterfaceRegisterEvent $event) : void{
			$interface = $event->getInterface();
			if($interface instanceof OomphRakLibInterface || (!$interface instanceof RakLibInterface && !$interface instanceof DedicatedQueryNetworkInterface)){
				return;
			}
			$this->getLogger()->debug("Prevented network interface " . get_class($interface) . " from being registered");
			$event->cancel();
		}, EventPriority::NORMAL, $this); */

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

		/* $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(): void {
			if ($this->netInterface === null) {
				foreach ($this->getServer()->getNetwork()->getInterfaces() as $interface) {
					if ($interface instanceof RakLibInterface) {
						$this->netInterface = $interface;
						break;
					}
				}

				if ($this->netInterface === null) {
					throw new AssumptionFailedError("raklib interface not found");
				}
				
				// TODO: not use PHP_INT_MAX here...
				$this->netInterface->setPacketLimit(PHP_INT_MAX); // TODO: not set this to PHP_INT_MAX.
			}

			$this->getServer()->getCommandMap()->getCommand("oalerts")?->setPermission($this->alertPermission);
			$this->getServer()->getCommandMap()->getCommand("odelay")?->setPermission($this->alertPermission);
			$this->getServer()->getCommandMap()->getCommand("ologs")?->setPermission($this->logPermission);
		}), 1); */

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
			$this->alerted = [];
			foreach ($this->getServer()->getOnlinePlayers() as $player) {
				if (!$player->hasPermission($this->alertPermission)) {
					$session->authorized = false;
					$session->alertsEnabled = false;
					continue;
				}

				$session = OomphSession::get($player);
				if ($session === null) {
					continue;
				}
				if (!$session->authorized) {
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
		if ($event->isFlying() && !$event->getPlayer()->getAllowFlight()) {
			$event->cancel();
		}
	}

	public function onQuit(PlayerQuitEvent $event): void {
		OomphSession::unregister($event->getPlayer());
	}

	/**
	 * @priority HIGHEST
	 * @param DataPacketReceiveEvent $event
	 * @return void
	 * @throws ReflectionException
	 */
	public function onClientPacket(DataPacketReceiveEvent $event): void {
		$player = $event->getOrigin()->getPlayer();
		$packet = $event->getPacket();

		// The fact we even have to do this is stupid LMAO.
		// Remember to notify dylanthecat!!! (i never did, i never will)
		if (!$packet instanceof ScriptMessagePacket) {
			if ($packet instanceof PlayerAuthInputPacket && $packet->getInputFlags()->get(PlayerAuthInputFlags::START_FLYING) && !$player->getAllowFlight()) {
				$player?->getNetworkSession()->syncAbilities($player);
			}
			return;
		}

		$eventType = $packet->getMessageId();
		if (!in_array($eventType, self::VALID_EVENTS)) {
			return;
		}

		$data = json_decode($packet->getValue(), true);
		if ($data === null) {
			return;
		}

		$event->cancel();
		switch ($eventType) {
			/* case "oomph:latency_report":
				if ($player === null) {
					return;
				}

				if (OomphSession::get($player) === null) {
					return;
				}

				$player->getNetworkSession()->updatePing((int) $data["raknet"]);
				break; */
			case "oomph:flagged":
				if ($player === null) {
					return;
				}

				if (OomphSession::get($player) === null) {
					return;
				}

				$data["violations"] = round($data["violations"], 2);

				$message = $this->getConfig()->get("FlaggedMessage", "{prefix} §d{player} §7flagged §4{check_main} §7(§c{check_sub}§7) §7[§5x{violations}§7] §f{extra_data}");
				$message = str_replace(
					["{prefix}", "{player}", "{check_main}", "{check_sub}", "{violations}", "{extra_data}"],
					[$this->getConfig()->get("Prefix", "§l§7[§eoomph§7]"), $data["player"], $data["check_main"], $data["check_sub"], $data["violations"], $data["extraData"]],
					$message
				);
				$ev = new OomphViolationEvent($player, $data["check_main"], $data["check_sub"], $data["violations"]);
				if (!$this->getConfig()->getNested("{$ev->getCheckName()}.{$ev->getCheckType()}", self::DEFAULT_CHECK_SETTINGS)["enabled"] ?? true) {
					$ev->cancel();
				}

				$ev->call();
				if (!$ev->isCancelled()) {
					LoggedData::getInstance()->add($player->getName(), $data);
					foreach ($this->alerted as $session) {
						$session->getPlayer()->sendMessage($message);
						$session->lastAlert = microtime(true);
					}

					$this->checkForPunishments($player, $ev->getCheckName(), $ev->getCheckType(), $ev->getViolations());
				}

				break;
		}
	}

	private function checkForPunishments(Player $player, string $check, string $type, float $violations): void {
		$settings = $this->getConfig()->getNested("$check.$type", self::DEFAULT_CHECK_SETTINGS);
		if (($settings["punishment"] ?? "none") === "none" || $violations < ($settings["max_violations"] ?? 10)) {
			return;
		}

		$punishmentType = OomphPunishmentEvent::punishmentTypeFromString($settings["punishment"]);
		$ev = new OomphPunishmentEvent($player, $punishmentType);
		$ev->call();
		if ($ev->isCancelled()) return;

		if ($punishmentType === OomphPunishmentEvent::TYPE_KICK) {
			$player->kick(str_replace(
				["{prefix}", "{check_main}", "{check_sub}"],
				[$this->getPrefix(), $check, $type],
				$this->getConfig()->get("KickMessage", "{prefix} §cKicked for the usage of third-party software.")
			));
			return;
		}

		$banMsg =$this->getConfig()->get("BanMessage", "{prefix} §cBanned for the usage of third-party software.");
		$player->kick(str_replace(
			["{prefix}", "{check_main}", "{check_sub}"],
			[$this->getPrefix(), $check, $type],
			$banMsg,
		));
		$this->getServer()->getNameBans()->addBan(
			$player->getName(), 
			$banMsg,
			null, 
			"Oomph",
		);
	}

	public function getPrefix(): string {
		return $this->getConfig()->get("Prefix", "§7§l[§eoomph§7]§r");
	}

}
