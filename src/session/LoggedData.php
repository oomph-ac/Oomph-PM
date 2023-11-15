<?php

namespace Oomph\src\session;

final class LoggedData {

	public static self $instance;

	public static function getInstance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/** @var array<string, array<string, string[]>> */
	public array $data = [];

	public function add(string $player, array $data): void {
		if (!isset($this->data[$player])) {
			$this->data[$player] = [];
		}

		$check = $data["check_main"];
		$type = $data["check_sub"];
		$this->data["$check.$type"] = $data;
	}

	public function remove(string $player): void {
		unset($this->data[$player]);
	}

}