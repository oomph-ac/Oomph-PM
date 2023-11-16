<?php

namespace Oomph\src\session;

final class LoggedData {

	public static ?self $instance = null;
	/** @var array<string, array<string, string[]>> */
	public array $data = [];

	public static function getInstance(): self {
		if (self::$instance === null) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function add(string $player, array $data): void {
		if (!isset($this->data[$player])) {
			$this->data[$player] = [];
		}

		$this->data[] = $data;
	}

	public function get(string $player): array {
		return $this->data[$player] ?? [];
	}

	public function remove(string $player): void {
		unset($this->data[$player]);
	}

}