<?php

namespace ethaniccc\Oomph\session;

use ethaniccc\Oomph\Oomph;
use pocketmine\network\mcpe\PacketRateLimiter;
use pocketmine\network\mcpe\protocol\AnimatePacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;

class OomphSession {

	/** @var OomphSession[] */
	public static array $sessions = [];
	public bool $alertsEnabled = false;
	public float $alertDelay = 5;
	public float $lastAlert = 0;

	private Player $player;
	private PacketRateLimiter $rateLimiter;

	public function __construct(Player $player) {
		$this->player = $player;
		$this->alertsEnabled = $player->hasPermission(Oomph::getInstance()->alertPermission);
		$this->rateLimiter = new PacketRateLimiter("Game Packet Limiter", 2, 100);
	}

	public static function register(Player $player): OomphSession {
		$session = new OomphSession($player);
		self::$sessions[spl_object_hash($player)] = $session;
		return $session;
	}

	public static function unregister(Player $player): void {
		unset(self::$sessions[spl_object_hash($player)]);
	}

	public static function get(Player $player): ?OomphSession {
		return self::$sessions[spl_object_hash($player)] ?? null;
	}

	public function getPlayer(): Player {
		return $this->player;
	}

	public function handlePacket(ServerboundPacket $packet): void {
		if ($packet instanceof InventoryTransactionPacket || $packet instanceof AnimatePacket) {
			return;
		}

		try {
			$this->rateLimiter->decrement();
		} catch (PacketHandlingException $err) {
			Oomph::getInstance()->getScheduler()->scheduleDelayedTask(new ClosureTask(function(): void {
				$this->player->kick("Exceeded packet rate limit");
			}), 1);
		}
	}

}