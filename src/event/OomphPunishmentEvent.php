<?php

namespace ethaniccc\Oomph\event;

use pocketmine\event\CancellableTrait;
use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;

final class OomphPunishmentEvent extends PlayerEvent {

	use CancellableTrait;

	public const TYPE_KICK = 0;
	public const TYPE_BAN = 1;

	public static function punishmentTypeFromString(string $type): int {
		return match (strtolower($type)) {
			"ban" => self::TYPE_BAN,
			default => self::TYPE_KICK,
		};
	}

	public function __construct(
		Player $player,
		public int $punishmentType
	) {
		$this->player = $player;
	}

	public function isKick(): bool {
		return $this->punishmentType === self::TYPE_KICK;
	}

	public function isBan(): bool {
		return $this->punishmentType === self::TYPE_BAN;
	}

}