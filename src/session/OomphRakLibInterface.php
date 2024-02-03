<?php

namespace ethaniccc\Oomph\session;

use ethaniccc\Oomph\session\OomphNetworkSession;
use ethaniccc\Oomph\utils\ReflectionUtils;
use MultiVersion\MultiVersion;
use pmmp\thread\ThreadSafeArray;
use pocketmine\network\mcpe\compression\ZlibCompressor;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\raklib\PthreadsChannelReader;
use pocketmine\network\mcpe\raklib\PthreadsChannelWriter;
use pocketmine\network\mcpe\raklib\RakLibInterface;
use pocketmine\network\mcpe\raklib\RakLibPacketSender;
use pocketmine\network\mcpe\StandardEntityEventBroadcaster;
use pocketmine\network\mcpe\StandardPacketBroadcaster;
use pocketmine\Server;
use pocketmine\timings\Timings;
use raklib\server\ipc\RakLibToUserThreadMessageReceiver;
use raklib\server\ipc\UserToRakLibThreadMessageSender;
use raklib\utils\InternetAddress;
use ReflectionException;

class OomphRakLibInterface extends RakLibInterface{
	public function __construct(Server $server, string $ip, int $port, bool $ipV6){
		$typeConverter = TypeConverter::getInstance();
		$packetSerializerContext = new PacketSerializerContext($typeConverter->getItemTypeDictionary());
		$packetBroadcaster = new StandardPacketBroadcaster($server, $packetSerializerContext);
		$entityEventBroadcaster = new StandardEntityEventBroadcaster($packetBroadcaster, $typeConverter);
		parent::__construct($server, $ip, $port, $ipV6, $packetBroadcaster, $entityEventBroadcaster, $packetSerializerContext, $typeConverter);
	}
	/**
	 * @throws ReflectionException
	 */
	public function onClientConnect(int $sessionId, string $address, int $port, int $clientID) : void{
		$session = new OomphNetworkSession(
			Server::getInstance(),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "network")->getSessionManager(),
			PacketPool::getInstance(),
			ReflectionUtils::getProperty(RakLibInterface::class, $this, "packetSerializerContext"),
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