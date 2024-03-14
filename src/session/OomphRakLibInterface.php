<?php

namespace ethaniccc\Oomph\session;

use ethaniccc\Oomph\session\OomphNetworkSession;
use ethaniccc\Oomph\utils\ReflectionUtils;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\Server;
use ReflectionException;

class OomphRakLibInterface extends RakLibInterface{
	public function __construct(Server $server, string $ip, int $port, bool $ipV6){
		$typeConverter = TypeConverter::getInstance();
		$packetBroadcaster = new StandardPacketBroadcaster($server);
		$entityEventBroadcaster = new StandardEntityEventBroadcaster($packetBroadcaster, $typeConverter);
		parent::__construct($server, $ip, $port, $ipV6, $packetBroadcaster, $entityEventBroadcaster, $typeConverter);
	}
	/**
	 * @throws ReflectionException
	 */
	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$session = new OomphNetworkSession(
			Server::getInstance(),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "network")->getSessionManager(),
			PacketPool::getInstance(),
			new RakLibPacketSender($sessionId, $this),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "packetBroadcaster"),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "entityEventBroadcaster"),
			ZlibCompressor::getInstance(),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "typeConverter"),
			$address,
			$port
		);
		$sessions = ReflectionUtils::getProperty(RakLibInterface::class, $this, "sessions");
		$sessions[$sessionId] = $session;
		ReflectionUtils::setProperty(RakLibInterface::class, $this, "sessions", $sessions);
	}
}