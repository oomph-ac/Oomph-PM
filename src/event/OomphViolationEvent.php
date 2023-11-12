<?php

namespace ethaniccc\Oomph\event;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\player\PlayerEvent;
use pocketmine\player\Player;

class OomphViolationEvent extends PlayerEvent {

    use CancellableTrait;

    public function __construct(
        Player $player,
        public string $checkName,
        public string $checkType,
        public float $violations,
    ) {
        $this->player = $player;
    }

    public function getCheckName(): string {
        return $this->checkName;
    }

    public function getCheckType(): string {
        return $this->checkType;
    }

    public function getViolations(): float {
        return $this->violations;
    }

}