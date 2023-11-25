<?php

declare(strict_types=1);

namespace ethaniccc\Oomph;

use ethaniccc\Oomph\event\OomphViolationEvent;
use ethaniccc\Oomph\session\OomphSession;
use ethaniccc\Oomph\session\LoggedData;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerLoginEvent;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\PacketRateLimiter;
use pocketmine\network\mcpe\protocol\ScriptMessagePacket;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\TextFormat;
use ReflectionClass;
use ReflectionException;

class Oomph extends PluginBase implements Listener {

	private const VALID_EVENTS = [
		"oomph:authentication",
		"oomph:latency_report",
		"oomph:flagged",
	];

	private static Oomph $instance;

	/** @var string[] */
	public array $xuidList = [];

	/** @var OomphSession[] */
	private array $alerted = [];
	private ?RakLibInterface $netInterface = null;

	public function onEnable(): void {
		self::$instance = $this;

		if ($this->getConfig()->get("Version", "n/a") !== "1.0.0") {
			@unlink($this->getDataFolder() . "config.yml");
			$this->reloadConfig();
		}

		if (!$this->getConfig()->get("Enabled", true)) {
			$this->getLogger()->warning("Oomph set to disabled in config");
			return;
		}

		$this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(): void {
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
				$this->netInterface->setPacketLimit(PHP_INT_MAX); // TODO: not set this to PHP_INT_MAX.
			}
		}), 1);

		$this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function(): void {
			$this->alerted = [];
			foreach ($this->getServer()->getOnlinePlayers() as $player) {
				if (!$player->hasPermission("Oomph.Alerts")) {
					continue;
				}

				$session = OomphSession::get($player);
				if ($session === null) {
					continue;
				}

				if (microtime(true) - $session->lastAlert < $session->alertDelay) {
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

				if (!$sender->hasPermission("Oomph.Alerts")) {
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
					$delay = max((float) ($args[0] ?? 3), 0.05);
					$session->alertDelay = $delay;
					$sender->sendMessage(TextFormat::GREEN . "Alert delay set to $delay seconds");
				}

				return true;
			case "ologs":
				if (!$sender->hasPermission("Oomph.Logs")) {
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
	 * @param PlayerPreLoginEvent $event
	 * @priority HIGHEST
	 * @ignoreCancelled true
	 * @throws ReflectionException
	 */
	public function onPreLogin(PlayerPreLoginEvent $event): void {
		if ($this->getConfig()->get("Allow-NonOomph-Conn") && $event->getPlayerInfo() instanceof XboxLivePlayerInfo) {
			return;
		}

		// Kick the player if data has not been received from Oomph validating their login.
		if (!isset($this->xuidList["{$event->getIp()}:{$event->getPort()}"])) {
			$event->setKickFlag(
				PlayerPreLoginEvent::KICK_FLAG_PLUGIN,
				"oomph authentication data not found for {$event->getPlayerInfo()->getUsername()}",
				"failed to initialize session - please try logging in again."
			);
			return;
		}

		$ref = (new ReflectionClass($event))->getProperty("playerInfo");
		/** @var PlayerInfo $playerInfo */
		$playerInfo = $ref->getValue($event);
		$extraData = $playerInfo->getExtraData();
		$extraData["Xuid"] = $this->xuidList["{$event->getIp()}:{$event->getPort()}"];
		$extraData["Username"] = $playerInfo->getUsername();
		$playerInfo = new XboxLivePlayerInfo(
			$this->xuidList["{$event->getIp()}:{$event->getPort()}"],
			$playerInfo->getUsername(),
			$playerInfo->getUuid(),
			$playerInfo->getSkin(),
			$playerInfo->getLocale(),
			$extraData,
		);
		$ref->setValue($event, $playerInfo);
		$event->setAuthRequired(false);
	}

	/**
	 * @priority HIGHEST
	 * @param PlayerLoginEvent $event
	 * @return void
	 * @throws ReflectionException
	 */
	public function onLogin(PlayerLoginEvent $event): void {
		$player = $event->getPlayer();
		$xuid = $this->xuidList["{$player->getNetworkSession()->getIp()}:{$player->getNetworkSession()->getPort()}"] ?? null;
		if ($xuid === null) {
			if ($this->getConfig()->get("Allow-NonOomph-Conn")) {
				return;
			}

			$event->setKickMessage("failed to initialize session - please try logging in again.");
			$event->cancel();
			return;
		}

		$ref = new ReflectionClass($player);
		$ref->getProperty("xuid")->setValue($player, $xuid);
		$ref->getProperty("authenticated")->setValue($player, true);
		unset($this->xuidList["{$player->getNetworkSession()->getIp()}:{$player->getNetworkSession()->getPort()}"]);

		OomphSession::register($player);
	}

	public function onQuit(PlayerQuitEvent $event): void {
		OomphSession::unregister($event->getPlayer());
	}

	/**
	 * @priority HIGHEST
	 * @param DataPacketReceiveEvent $event
	 * @return void
	 * @throws ReflectionException'
	 */
	public function onClientPacket(DataPacketReceiveEvent $event): void {
		$player = $event->getOrigin()->getPlayer();
		$packet = $event->getPacket();

		if (!$packet instanceof ScriptMessagePacket) {
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
			case "oomph:authentication":
				if ($player !== null) {
					$this->getLogger()->warning("invalid authentication attempt from {$event->getOrigin()->getIp()}:{$event->getOrigin()->getPort()}");
					return;
				}

				if (!$this->getConfig()->get("Allow-NonOomph-Conn") && !in_array($event->getOrigin()->getIp(), $this->getConfig()->get("Allowed-Connections", ["127.0.0.1"]))) {
					$this->getLogger()->warning("invalid connection from {$event->getOrigin()->getIp()} with XUID " . $data["xuid"]);
					$event->getOrigin()->disconnect("invalid connection [error: 1]");
					return;
				}

				$netRef = new ReflectionClass($event->getOrigin());
				$netRef->getProperty("ip")->setValue($event->getOrigin(), explode(":", $data["address"])[0]);
				$netRef->getProperty("packetBatchLimiter")->setValue($event->getOrigin(), new PacketRateLimiter("Packet Batches", 100, 1_000));
				$netRef->getProperty("gamePacketLimiter")->setValue($event->getOrigin(), new PacketRateLimiter("Game Packets", 100, 1_000));

				$this->xuidList[$event->getOrigin()->getIp() . ":" . $event->getOrigin()->getPort()] = $data["xuid"];
				break;
			case "oomph:latency_report":
				if ($player === null) {
					return;
				}

				if (OomphSession::get($player) === null) {
					return;
				}

				$player->getNetworkSession()->updatePing((int) $data["raknet"]);
				break;
			case "oomph:flagged":
				if ($player === null) {
					return;
				}

				if (OomphSession::get($player) === null) {
					return;
				}

				$data["violations"] = round($data["violations"], 2);

				$message = $this->getConfig()->get("FlaggedMessage", "{prefix} §d{player} §7flagged §4{check_main} §7(§c{check_sub}§7) §7[§5x{violations}§7]");
				$message = str_replace(
					["{prefix}", "{player}", "{check_main}", "{check_sub}", "{violations}", "{ping}"],
					[$this->getConfig()->get("Prefix", "§l§7[§eoomph§7]"), $data["player"], $data["check_main"], $data["check_sub"], $data["violations"], $player->getNetworkSession()->getPing()],
					$message
				);
				$ev = new OomphViolationEvent($player, $data["check_main"], $data["check_sub"], $data["violations"]);
				$ev->call();

				if (!$ev->isCancelled()) {
					LoggedData::getInstance()->add($player->getName(), $data);
					foreach ($this->alerted as $session) {
						$session->getPlayer()->sendMessage($message);
						$session->lastAlert = microtime(true);
					}
				}

				break;
		}
	}

}
