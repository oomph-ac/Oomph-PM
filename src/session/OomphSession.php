<?php

namespace ethaniccc\Oomph\session;

use pocketmine\player\Player;

class OomphSession {

	/** @var OomphSession[] */
	public static array $sessions = [];
	public bool $alertsEnabled = false;
	public float $alertDelay = 0;
	public float $lastAlert = 0;
	private Player $player;

	public function __construct(Player $player) {
		$this->player = $player;
		$this->alertsEnabled = $player->hasPermission("Oomph.Alerts");
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

}